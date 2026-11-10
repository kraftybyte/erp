<?php

/**
 * Cek kesehatan data live, hanya membaca: migrasi, Gemini, status advance,
 * quotation yang dikonversi, penomoran proforma, pelunasan yang belum
 * tertaut, dan pemakaian bot. Hanya angka, status, dan nomor dokumen; tanpa
 * nama klien.
 *
 * Dijalankan dari luar folder situs memakai kode situs itu sendiri, jadi
 * tidak perlu deploy (lihat check-db.sh):
 *   php check-db.php <folder_situs>
 *
 * CEK_DB_SKIP_GEMINI=1 melewati tes koneksi Gemini (dipakai tes).
 */

use App\Models\Advance;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\GeminiService;
use App\Services\Telegram\OpenAdvances;
use App\Settings\IntegrationSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$site = rtrim($argv[1] ?? '', '/');

if ($site === '' || ! is_file("{$site}/artisan")) {
    fwrite(STDERR, "Pemakaian: php check-db.php <folder_situs>\n");
    exit(1);
}

require "{$site}/vendor/autoload.php";
$app = require "{$site}/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

$section = function (string $title): void {
    echo "\n== {$title}\n";
};

$section('Migrasi yang belum jalan');
Artisan::call('migrate:status');
$pending = collect(explode("\n", Artisan::output()))->filter(fn ($line) => str_contains($line, 'Pending'))->map(fn ($line) => trim($line));
echo ($pending->isEmpty() ? 'Tidak ada.' : $pending->implode("\n")) . "\n";

$section('Gemini (baca struk otomatis)');
$gemini = app(GeminiService::class);
echo 'API key: ' . ($gemini->isConfigured() ? 'terisi' : 'KOSONG') . ' · model: ' . IntegrationSettings::geminiModel() . "\n";
echo 'Tes koneksi: ' . match (true) {
    getenv('CEK_DB_SKIP_GEMINI') === '1' => 'dilewati',
    ! $gemini->isConfigured()            => 'dilewati (key kosong)',
    default                              => $gemini->testConnection() ?? 'OK',
} . "\n";

$section('Advance per status');
Advance::query()->selectRaw('status, COUNT(*) AS jumlah')->groupBy('status')->orderByDesc('jumlah')->get()
    ->each(fn ($row) => print(str_pad((string) $row->status, 28) . $row->jumlah . "\n"));

$open    = Advance::whereIn('status', [Advance::STATUS_DIBAYARKAN, Advance::STATUS_REALIZED_USER])->get();
$blocked = $open->filter(fn (Advance $advance) => app(OpenAdvances::class)->blockedReason($advance))->pluck('id');
echo "Terbuka untuk realisasi: {$open->count()} · tertahan kelebihan belum disetujui: {$blocked->count()}"
    . ($blocked->isNotEmpty() ? ' (ID ' . $blocked->implode(', ') . ')' : '') . "\n";

$legacy = Advance::whereIn('status', [Advance::STATUS_REALIZING, Advance::STATUS_READY_TO_CLOSE])->pluck('id');
echo 'Status lama (Sedang di-Realisasi / Siap Diselesaikan): ' . ($legacy->isEmpty() ? 'tidak ada' : 'ID ' . $legacy->implode(', ')) . "\n";

$section('Quotation');
$converted    = Quotation::where('status', 'Converted')->get(['id', 'uuid']);
$withProforma = Invoice::where('for_type', 'quotation')->whereIn('for_id', $converted->pluck('id'))->pluck('for_id')->unique();
$orphans      = $converted->whereNotIn('id', $withProforma->all());
echo "Converted: {$converted->count()} · tanpa proforma/invoice aktif: {$orphans->count()}"
    . ($orphans->isNotEmpty() ? ' (' . $orphans->pluck('uuid')->take(20)->implode(', ') . ')' : '') . "\n";

$section('Penomoran invoice bulan ini');
$month = '/013' . now()->format('ym') . '/';
echo 'Proforma #PI aktif: ' . Invoice::where('uuid', 'like', "#PI{$month}%")->count()
    . ' · invoice #SC aktif: ' . Invoice::where('uuid', 'like', "#SC{$month}%")->count() . "\n";
echo 'Proforma dihapus (nomornya dilepas, -BATAL-): ' . Invoice::onlyTrashed()->where('uuid', 'like', '%' . Invoice::TANDA_BATAL . '%')->count()
    . ' · dihapus sebelum aturan baru (masih memegang nomor): ' . Invoice::onlyTrashed()->where('type', 'estimate')->where('uuid', 'not like', '%' . Invoice::TANDA_BATAL . '%')->count() . "\n";
echo 'Invoice yang mencatat nomor PI asalnya (proforma_uuid): ' . Invoice::whereNotNull('proforma_uuid')->count() . "\n";

$section('Pelunasan belum tertaut ke invoice DP');
$unlinked = Invoice::where('type', 'sale')->where('type_payment', 'final_payment')->whereNull('parent_invoice_id')
    ->where(fn ($query) => $query->whereNull('notes')->orWhere('notes', 'not regexp', 'DP[: ]*[0-9]+%'))
    ->get(['uuid', 'status']);
echo "{$unlinked->count()} invoice"
    . ($unlinked->isNotEmpty() ? ': ' . $unlinked->map(fn ($invoice) => "{$invoice->uuid} ({$invoice->status})")->take(20)->implode(', ') : '') . "\n";

// =====================================================================
// Jenis pembayaran: DP, full payment, pelunasan. Yang ditagih per invoice
// mengikuti Invoice::amountDue() (DP = Total × persen; pelunasan = harga
// penuh − DP), sama dengan PDF dan tombol Bayar.
// =====================================================================
$rupiah = fn ($amount) => 'Rp ' . number_format((float) $amount, 0, ',', '.');
$list   = fn ($items, int $limit = 15) => collect($items)->take($limit)->implode(', ') . (count($items) > $limit ? ', …+' . (count($items) - $limit) : '');

$section('Jenis pembayaran invoice (tanpa yang dibatalkan)');
$invoices = Invoice::with('parentInvoice')->whereIn('type', ['sale', 'estimate'])->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'cancelled'))->get();
$kind     = fn (Invoice $invoice) => match (true) {
    $invoice->dpPercentage() !== null          => 'dp',
    $invoice->type_payment === 'final_payment' => 'pelunasan',
    $invoice->type_payment === 'full_payment'  => 'full',
    default                                    => 'lain: ' . var_export($invoice->type_payment, true),
};

$invoices->groupBy(fn (Invoice $invoice) => ($invoice->type === 'estimate' ? 'proforma' : 'invoice') . ' ' . $kind($invoice))->sortKeys()
    ->each(function ($group, $key) use ($rupiah) {
        $statuses = $group->countBy(fn (Invoice $invoice) => $invoice->status ?: '-')->map(fn ($n, $status) => "{$status} {$n}")->implode(' · ');
        echo str_pad($key, 22) . str_pad($group->count() . ' dok', 9) . 'ditagih ' . str_pad($rupiah($group->sum(fn (Invoice $invoice) => $invoice->amountDue())), 18)
            . 'Total kolom ' . str_pad($rupiah($group->sum('grand_total')), 18) . "({$statuses})\n";
    });

$bad = $invoices->filter(fn (Invoice $invoice) => str_starts_with($kind($invoice), 'lain') || ($invoice->dpPercentage() !== null && ($invoice->dpPercentage() <= 0 || $invoice->dpPercentage() >= 100)));
echo 'Jenis pembayaran tidak dikenal / persen DP tidak wajar: ' . ($bad->isEmpty() ? 'tidak ada' : $list($bad->map(fn ($i) => "{$i->uuid} ({$i->type_payment})"))) . "\n";

$section('Status vs nominal (yang ditagih = amountDue)');
$due      = fn (Invoice $invoice) => round($invoice->amountDue(), 2);
$label    = fn (Invoice $invoice) => "{$invoice->uuid} [{$invoice->status}] ditagih " . $rupiah($due($invoice)) . ' dibayar ' . $rupiah($invoice->paid);
$checks   = [
    'Status paid tapi dibayar kurang dari yang ditagih' => fn (Invoice $i) => $i->status === 'paid' && (float) $i->paid < $due($i) - 1,
    'Sudah dibayar penuh tapi status belum paid'        => fn (Invoice $i) => $i->status !== 'paid' && $due($i) > 0 && (float) $i->paid >= $due($i) - 1,
    'Dibayar lebih dari yang ditagih'                   => fn (Invoice $i) => (float) $i->paid > $due($i) + 1,
    'Status partial tapi belum ada pembayaran'          => fn (Invoice $i) => $i->status === 'partial' && (float) $i->paid <= 0,
];

foreach ($checks as $title => $test) {
    $found = $invoices->filter($test);
    echo "{$title}: " . ($found->isEmpty() ? 'tidak ada' : $found->count() . "\n   " . $list($found->map($label), 10)) . "\n";
}

// Kolom paid dibandingkan dengan uang masuk di rekening + potongan pajak yang dicatat.
$received = DB::table('transactions')->where('reference_type', 'invoice')->where('type', 'in')
    ->selectRaw('reference_id, SUM(amount) AS total')->groupBy('reference_id')->pluck('total', 'reference_id');
$withheld = DB::table((new \TomatoPHP\FilamentInvoices\Models\InvoiceMeta)->getTable())->where('key', 'withholding')
    ->selectRaw('invoice_id, SUM(value) AS total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
$drift    = $invoices->filter(fn (Invoice $i) => (float) $i->paid > 0 || isset($received[$i->id]))
    ->map(fn (Invoice $i) => ['invoice' => $i, 'diff' => round((float) $i->paid - (float) ($received[$i->id] ?? 0) - (float) ($withheld[$i->id] ?? 0), 2)])
    ->filter(fn ($row) => abs($row['diff']) > 1)->sortByDesc(fn ($row) => abs($row['diff']));
echo 'Kolom dibayar ≠ uang masuk di rekening + potongan: ' . ($drift->isEmpty() ? 'tidak ada' : $drift->count() . "\n   "
    . $list($drift->map(fn ($row) => "{$row['invoice']->uuid} selisih " . $rupiah($row['diff'])), 10)) . "\n";

$section('DP dan pelunasan');
$finals   = $invoices->where('type', 'sale')->where('type_payment', 'final_payment');
$children = $finals->whereNotNull('parent_invoice_id')->groupBy('parent_invoice_id');

$waiting = $invoices->where('type', 'sale')->filter(fn (Invoice $i) => $i->dpPercentage() !== null && $i->status === 'paid' && ! $children->has($i->id));
echo 'DP lunas, invoice pelunasan belum dibuat: ' . ($waiting->isEmpty() ? 'tidak ada' : $waiting->count() . ' · sisa pekerjaan ' . $rupiah($waiting->sum(fn ($i) => (float) $i->grand_total - $i->amountDue()))
    . "\n   " . $list($waiting->sortBy('date')->map(fn ($i) => "{$i->uuid} ({$i->date?->format('d/m/y')})"))) . "\n";

$unpaidDp = $finals->filter(fn (Invoice $i) => $i->parentInvoice && $i->parentInvoice->status !== 'paid');
echo 'Pelunasan dibuat padahal DP-nya belum lunas: ' . ($unpaidDp->isEmpty() ? 'tidak ada' : $list($unpaidDp->map(fn ($i) => "{$i->uuid} ← {$i->parentInvoice->uuid} [{$i->parentInvoice->status}]"))) . "\n";

$notDp = $finals->filter(fn (Invoice $i) => $i->parentInvoice && $i->parentInvoice->dpPercentage() === null);
echo 'Pelunasan tertaut ke invoice yang bukan DP: ' . ($notDp->isEmpty() ? 'tidak ada' : $list($notDp->map(fn ($i) => "{$i->uuid} ← {$i->parentInvoice->uuid} ({$i->parentInvoice->type_payment})"))) . "\n";

$changed = $finals->filter(fn (Invoice $i) => $i->parentInvoice && abs((float) $i->grand_total - (float) $i->parentInvoice->grand_total) > 1);
echo 'Total pelunasan berbeda dari total invoice DP-nya (yang ditagih memakai total DP): ' . ($changed->isEmpty() ? 'tidak ada' : $changed->count() . "\n   "
    . $list($changed->map(fn ($i) => "{$i->uuid} " . $rupiah($i->grand_total) . " vs DP {$i->parentInvoice->uuid} " . $rupiah($i->parentInvoice->grand_total)), 10)) . "\n";

$twice = $children->filter(fn ($group) => $group->count() > 1);
echo 'Satu DP punya lebih dari satu pelunasan: ' . ($twice->isEmpty() ? 'tidak ada' : $list($twice->map(fn ($group) => $group->first()->parentInvoice?->uuid . ' → ' . $group->pluck('uuid')->implode(' + ')))) . "\n";

$fromNotes = $finals->filter(fn (Invoice $i) => ! $i->parent_invoice_id && $i->settledDp());
echo 'Pelunasan lama (DP dibaca dari catatan, tanpa tautan): ' . ($fromNotes->isEmpty() ? 'tidak ada' : $list($fromNotes->map(fn ($i) => "{$i->uuid} DP {$i->settledDp()['percent']}%"))) . "\n";

// Pelunasan lama: angka dari catatan dibandingkan dengan Total invoice itu sendiri,
// dan invoice DP yang kemungkinan pasangannya (deal/klien sama, nominal DP cocok)
// supaya bisa ditautkan dari ERP. Hanya nomor dokumen dan nominal.
$dps = $invoices->where('type', 'sale')->filter(fn (Invoice $i) => $i->dpPercentage() !== null);
$pairOf = function (Invoice $final) use ($dps, $children) {
    $dp      = $final->settledDp();
    $company = mb_strtolower(trim((string) $final->nama_perusahaan));

    return $dps->filter(fn (Invoice $i) => ! $children->has($i->id)
            && (($final->deal_id && $i->deal_id === $final->deal_id) || ($company !== '' && mb_strtolower(trim((string) $i->nama_perusahaan)) === $company))
            && (abs($i->amountDue() - $dp['amount']) <= 1 || abs((float) $i->grand_total - (float) $final->grand_total) <= 1))
        ->sortBy(fn (Invoice $i) => abs($i->amountDue() - $dp['amount']));
};

if ($fromNotes->isNotEmpty()) {
    echo "Rincian pelunasan lama:\n";

    foreach ($fromNotes as $final) {
        $dp    = $final->settledDp();
        $pairs = $pairOf($final);
        $off   = abs($dp['full'] - (float) $final->grand_total) > 1;

        echo "   {$final->uuid} [{$final->status}] Total " . $rupiah($final->grand_total)
            . " · catatan: DP {$dp['percent']}% " . $rupiah($dp['amount']) . ' → harga penuh ' . $rupiah($dp['full'])
            . ' · ditagih ' . $rupiah($final->amountDue()) . ' · dibayar ' . $rupiah($final->paid)
            . ($off ? ' · ⚠️ harga penuh di catatan ≠ Total' : '')
            . ' · pasangan DP: ' . ($pairs->isEmpty() ? 'tidak ketemu' : $pairs->take(3)->map(fn ($i) => "{$i->uuid} [{$i->status}] DP " . $rupiah($i->amountDue()) . ' dari ' . $rupiah($i->grand_total))->implode(' / ')) . "\n";
    }
}

// DP lunas tanpa pelunasan tertaut: apakah ada pelunasan lama yang kemungkinan miliknya.
if ($waiting->isNotEmpty()) {
    echo "DP lunas tanpa pelunasan tertaut, dicocokkan dengan pelunasan lama:\n";

    foreach ($waiting->sortBy('date') as $dpInvoice) {
        $match = $fromNotes->first(fn (Invoice $final) => $pairOf($final)->contains('id', $dpInvoice->id));
        echo "   {$dpInvoice->uuid} DP {$dpInvoice->dpPercentage()}% " . $rupiah($dpInvoice->amountDue()) . ' dari ' . $rupiah($dpInvoice->grand_total)
            . ' · dibayar ' . $rupiah($dpInvoice->paid) . ' → ' . ($match ? "kemungkinan pelunasannya {$match->uuid} [{$match->status}]" : 'pelunasan belum ada') . "\n";
    }
}

$section('Pemakaian bot 7 hari terakhir (struk tercatat)');
$rows = DB::table('telegram_receipts')
    ->where('created_at', '>=', now()->subDays(7)->startOfDay())
    ->selectRaw("DATE(created_at) AS hari, SUM(scope = 'pengajuan') AS pengajuan, SUM(scope LIKE 'realisasi:%') AS realisasi")
    ->groupBy('hari')->orderBy('hari')->get();
echo ($rows->isEmpty() ? 'Belum ada.' : $rows->map(fn ($row) => "{$row->hari}  pengajuan {$row->pengajuan} · realisasi {$row->realisasi}")->implode("\n")) . "\n";

$section('Mutasi rekening dari bot (/mutasi)');
echo \Illuminate\Support\Facades\Schema::hasTable('bank_mutations')
    ? DB::table('bank_mutations')->count() . ' mutasi tercatat · terakhir: ' . (DB::table('bank_mutations')->max('created_at') ?? '-') . "\n"
    : "Tabel belum ada (belum deploy).\n";

$section('Antrean job (queue)');
echo 'Koneksi: ' . config('queue.default')
    . (\Illuminate\Support\Facades\Schema::hasTable('jobs') ? ' · menunggu: ' . DB::table('jobs')->count() . ' (terlama: ' . (($oldest = DB::table('jobs')->min('created_at')) ? date('Y-m-d H:i', (int) $oldest) : '-') . ')' : '')
    . (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs') ? ' · gagal: ' . DB::table('failed_jobs')->count() : '') . "\n";

$section('Gemini 7 hari terakhir');
echo \Illuminate\Support\Facades\Schema::hasTable('ai_usages')
    ? DB::table('ai_usages')->where('date', '>=', today()->subDays(7)->toDateString())->orderBy('date')->get()
        ->map(fn ($row) => "{$row->date}  {$row->calls} panggilan · {$row->failures} gagal · " . number_format($row->input_tokens + $row->output_tokens, 0, ',', '.') . ' token')
        ->whenEmpty(fn ($rows) => collect(['Belum tercatat.']))->implode("\n") . "\n"
    : "Tabel belum ada (belum deploy).\n";
