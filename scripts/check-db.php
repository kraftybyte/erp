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

$section('Pemakaian bot 7 hari terakhir (struk tercatat)');
$rows = DB::table('telegram_receipts')
    ->where('created_at', '>=', now()->subDays(7)->startOfDay())
    ->selectRaw("DATE(created_at) AS hari, SUM(scope = 'pengajuan') AS pengajuan, SUM(scope LIKE 'realisasi:%') AS realisasi")
    ->groupBy('hari')->orderBy('hari')->get();
echo ($rows->isEmpty() ? 'Belum ada.' : $rows->map(fn ($row) => "{$row->hari}  pengajuan {$row->pengajuan} · realisasi {$row->realisasi}")->implode("\n")) . "\n";
