<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\BankMutation;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BankStatement\MutationMatcher;
use App\Services\BankStatement\MutationRecorder;
use App\Services\BankStatement\StatementReader;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Mutasi rekening di bot keuangan: dibaca (CSV/Excel tanpa AI, PDF/foto lewat
 * Gemini), dicocokkan dengan invoice/expense/advance, lalu dicatat setelah
 * Finance menekan Catat. Nominal sengaja ganjil supaya tidak bertabrakan
 * dengan data yang sudah ada di database.
 */
class BankStatementTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'rahasia-bot-finance';

    private int $chatId = 990_000_303;

    private int $updateId = 0;

    private PaymentAccount $account;

    private string $accountNumber;

    private string $client;

    private string $csv = '';

    private ?array $gemini = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-08 10:00:00');

        IntegrationSettings::fake([
            'gemini_api_key'          => null,
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '111:PENGAJUAN',
            'telegram_webhook_secret' => 'rahasia-bot-pengajuan',
            'finance_bot_token'       => '222:FINANCE',
            'finance_webhook_secret'  => self::SECRET,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forget("telegram:finance-mutasi:{$this->chatId}");

        $this->fakeTelegram();

        $this->client        = 'PT Kilau ' . Str::upper(Str::random(5)) . ' Nusantara';
        $this->accountNumber = (string) random_int(1_000_000_000, 9_999_999_999);
        $this->account       = PaymentAccount::create([
            'name'            => 'Rekening Mutasi ' . Str::upper(Str::random(4)),
            'bank_name'       => 'BCA',
            'account_number'  => $this->accountNumber,
            'account_holder'  => 'Tes',
            'initial_balance' => 0,
            'is_active'       => true,
        ]);
    }

    // =================================================================
    // Membaca file
    // =================================================================

    public function test_csv_klikbca_terbaca_lengkap_dan_saldo_cocok(): void
    {
        $statement = $this->read($this->klikBca([
            ['03/11', 'TRSF E-BANKING CR 0311/FTSCY/WS95051 15437219.00 PT NESTORY INDONESIA', 15_437_219, 'CR'],
            ['04/11', 'TRSF E-BANKING DB 0411/FTFVA/WS95031 BUDI SANTOSO', 500_123, 'DB'],
            ['05/11', 'BIAYA ADM', 10_000, 'DB'],
        ], opening: 10_000_000));

        $this->assertSame($this->accountNumber, $statement['account_number']);
        $this->assertSame(['2026-11-01', '2026-11-07'], [$statement['period_start'], $statement['period_end']]);
        $this->assertSame('tabel', $statement['source']);
        $this->assertCount(3, $statement['rows']);
        $this->assertSame(['2026-11-03', 15_437_219.0, 'in'], [$statement['rows'][0]['date'], $statement['rows'][0]['amount'], $statement['rows'][0]['direction']]);
        $this->assertSame(['2026-11-04', 500_123.0, 'out'], [$statement['rows'][1]['date'], $statement['rows'][1]['amount'], $statement['rows'][1]['direction']]);
        $this->assertSame('ok', $statement['check']['status']);
    }

    public function test_csv_kolom_debet_kredit_format_indonesia_dan_saldo_per_baris(): void
    {
        $csv = "Tanggal;Keterangan;Debet;Kredit;Saldo\n03/11/2026;Setoran dari PT Maju;;1.500.000,00;11.500.000,00\n04/11/2026;Transfer ke Andi;250.000,00;;11.250.000,00\n05/11/2026;Transfer ke Sari;100.000,00;;11.150.000,00\n";

        $statement = $this->read($csv);

        $this->assertCount(3, $statement['rows']);
        $this->assertSame([1_500_000.0, 'in'], [$statement['rows'][0]['amount'], $statement['rows'][0]['direction']]);
        $this->assertSame([250_000.0, 'out', 11_250_000.0], [$statement['rows'][1]['amount'], $statement['rows'][1]['direction'], $statement['rows'][1]['balance']]);
        $this->assertSame(['ok', 'saldo per baris'], [$statement['check']['status'], $statement['check']['method']]);
    }

    public function test_excel_dibaca_dari_nilai_asli_bukan_format_tampilan(): void
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['Tanggal Transaksi', 'Keterangan', 'Debit', 'Kredit', 'Saldo'],
            [ExcelDate::stringToExcel('2026-11-01'), 'TRANSFER DARI PT ALFA', null, 2_750_000.5, 12_750_000.5],
            [ExcelDate::stringToExcel('2026-11-02'), 'TRANSFER KE BUDI', 750_000, null, 12_000_000.5],
        ]);
        // Format tanggal ala AS (bulan/hari) tidak boleh membalik tanggalnya.
        $sheet->getStyle('A2:A3')->getNumberFormat()->setFormatCode('m/d/yyyy');

        $path = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        (new Xlsx($book))->save($path);
        $statement = $this->read(file_get_contents($path), 'mutasi.xlsx');
        @unlink($path);

        $this->assertSame(['2026-11-01', 2_750_000.5, 'in'], [$statement['rows'][0]['date'], $statement['rows'][0]['amount'], $statement['rows'][0]['direction']]);
        $this->assertSame(['2026-11-02', 750_000.0, 'out'], [$statement['rows'][1]['date'], $statement['rows'][1]['amount'], $statement['rows'][1]['direction']]);
        $this->assertSame('ok', $statement['check']['status']);
    }

    public function test_saldo_akhir_yang_tidak_cocok_terdeteksi(): void
    {
        $statement = $this->read($this->klikBca([
            ['03/11', 'SETORAN TUNAI', 1_000_000, 'CR'],
        ], opening: 10_000_000, closing: 12_000_000));

        $this->assertSame('mismatch', $statement['check']['status']);
        $this->assertEquals(-1_000_000, $statement['check']['difference']);
    }

    public function test_pdf_atau_foto_dibaca_gemini(): void
    {
        IntegrationSettings::fake([
            'gemini_api_key'          => 'kunci-tes',
            'gemini_model'            => 'gemini-3.5-flash-lite',
            'telegram_bot_token'      => '111:PENGAJUAN',
            'telegram_webhook_secret' => 'rahasia-bot-pengajuan',
            'finance_bot_token'       => '222:FINANCE',
            'finance_webhook_secret'  => self::SECRET,
        ]);
        $this->gemini = [
            'bank'            => 'BCA',
            'account_number'  => '888-011-4477',
            'period_start'    => '2026-11-01',
            'period_end'      => '2026-11-30',
            'opening_balance' => 1_000_000,
            'closing_balance' => 1_400_000,
            'rows'            => [
                ['date' => '2026-11-03', 'description' => 'TRSF E-BANKING CR PT ALFA', 'amount' => 500_000, 'direction' => 'in', 'balance' => 1_500_000],
                ['date' => '2026-11-04', 'description' => 'BIAYA ADM', 'amount' => 100_000, 'direction' => 'out', 'balance' => 1_400_000],
                ['date' => 'tidak jelas', 'description' => '???', 'amount' => 0, 'direction' => 'in', 'balance' => null],
            ],
        ];

        $statement = $this->read('%PDF-1.4 tes', 'rekening-koran.pdf');

        $this->assertSame(['BCA', '8880114477', 'ai'], [$statement['bank'], $statement['account_number'], $statement['source']]);
        $this->assertCount(2, $statement['rows']);
        $this->assertSame(1, $statement['skipped']);
        $this->assertSame('ok', $statement['check']['status']);
    }

    public function test_angka_dan_tanggal_dari_berbagai_bank(): void
    {
        $reader = app(StatementReader::class);

        foreach ([
            ['1,500,000.00 CR', 1_500_000.0, 'CR'],
            ['1.500.000,00', 1_500_000.0, null],
            ['Rp 1.500.000', 1_500_000.0, null],
            ['250000', 250_000.0, null],
            ['12,345.67 DB', 12_345.67, 'DB'],
            ['1.234', 1_234.0, null],
            ['15,5', 15.5, null],
        ] as [$text, $value, $suffix]) {
            $this->assertSame([$value, $suffix], array_slice($reader->money($text), 0, 2), $text);
        }

        $this->assertTrue($reader->money('-250.000')[2], 'Tanda minus berarti keluar.');
        $this->assertSame('2026-11-03', $reader->date("'03/11", 2026));
        $this->assertSame('2026-11-03', $reader->date('03/11/26', null));
        $this->assertSame('2026-11-03', $reader->date('3 Nov 2026', null));
        $this->assertSame('2026-08-17', $reader->date('17 Agt', 2026));
        $this->assertSame('2025-12-28', $reader->date('28/12', null), 'Tanpa tahun dan periode: Desember di bulan November berarti tahun lalu.');
        $this->assertNull($reader->date('PEND', 2026));
    }

    public function test_file_yang_bukan_mutasi_ditolak(): void
    {
        $result = app(StatementReader::class)->read("\x00\x01\x02 bukan apa-apa", 'acak.bin');

        $this->assertStringContainsString('Format file belum didukung', $result['error']);
    }

    // =================================================================
    // Mencocokkan
    // =================================================================

    public function test_uang_masuk_dengan_nama_klien_pasti_cocok(): void
    {
        $invoice = $this->invoice(15_437_219);

        $result = $this->match([$this->row('in', 15_437_219, 'TRSF E-BANKING CR 0311/FTSCY/WS95051 ' . strtoupper($this->client))])[0];

        $this->assertSame('sure', $result['status']);
        $this->assertSame([['type' => 'invoice', 'id' => $invoice->id, 'amount' => 15_437_219.0]], $result['candidates'][$result['pick']]['targets']);
    }

    public function test_tanpa_nama_hanya_pasti_bila_nominalnya_khas_dan_satu_satunya(): void
    {
        $this->invoice(15_437_221);
        $this->invoice(15_430_000);

        $khas  = $this->match([$this->row('in', 15_437_221, 'SETORAN TUNAI')])[0];
        $bulat = $this->match([$this->row('in', 15_430_000, 'SETORAN TUNAI')])[0];

        $this->assertSame('sure', $khas['status'], 'Nominal tidak bulat dan hanya satu tagihan: hampir pasti.');
        $this->assertLessThanOrEqual(88, $khas['candidates'][0]['confidence']);
        $this->assertContains('✗ nama/nomor tidak ada di berita transfer', $khas['candidates'][0]['reasons']);

        $this->assertSame('review', $bulat['status'], 'Nominal bulat tanpa nama bisa kebetulan.');
        $this->assertLessThanOrEqual(70, $bulat['candidates'][0]['confidence']);
    }

    public function test_berita_yang_menyebut_klien_lain_melemahkan_kandidat(): void
    {
        $other  = 'CV Beda ' . Str::upper(Str::random(5)) . ' Abadi';
        $target = $this->invoice(9_876_543);
        $this->invoice(1_111_119, ['nama_perusahaan' => $other]);

        // Nominal sama dengan invoice klien A, tapi berita menyebut klien B.
        $result = $this->match([$this->row('in', 9_876_543, 'TRSF E-BANKING CR ' . strtoupper($other))])[0];

        $this->assertNotSame('sure', $result['status']);
        $candidate = collect($result['candidates'])->firstWhere(fn ($candidate) => $candidate['targets'][0]['id'] === $target->id);
        $this->assertTrue(collect($candidate['reasons'])->contains(fn ($reason) => str_starts_with($reason, '✗ berita menyebut klien lain')));
    }

    public function test_dp_persen_dari_total_invoice_dikenali(): void
    {
        $invoice = $this->invoice(12_345_670);

        $result = $this->match([$this->row('in', 6_172_835, 'TRSF E-BANKING CR ' . strtoupper($this->client))])[0];

        $this->assertSame('sure', $result['status']);
        $this->assertSame($invoice->id, $result['candidates'][$result['pick']]['targets'][0]['id']);
        $this->assertStringContainsString('DP 50%', $result['candidates'][$result['pick']]['reasons'][0]);
    }

    public function test_dua_invoice_bernominal_sama_dipilih_yang_namanya_cocok(): void
    {
        $other  = $this->invoice(7_777_313, ['nama_perusahaan' => 'CV Lain ' . Str::upper(Str::random(5)) . ' Sentosa']);
        $target = $this->invoice(7_777_313);

        $result = $this->match([$this->row('in', 7_777_313, 'BI-FAST CR ' . strtoupper($this->client))])[0];

        $this->assertSame('sure', $result['status']);
        $this->assertSame($target->id, $result['candidates'][$result['pick']]['targets'][0]['id']);
        $this->assertNotSame($other->id, $result['candidates'][$result['pick']]['targets'][0]['id']);
    }

    public function test_nomor_invoice_di_berita_transfer(): void
    {
        $invoice = $this->invoice(9_123_457, ['uuid' => '#SC/0132611/' . random_int(70000, 79999), 'nama_perusahaan' => 'CV Tanpa ' . Str::upper(Str::random(6))]);
        $code    = substr($invoice->uuid, 4, 7);
        $seq     = substr($invoice->uuid, -5);

        $result = $this->match([$this->row('in', 9_123_457, "TRSF E-BANKING CR PEMBAYARAN INV SC {$code} {$seq}")])[0];

        $this->assertSame('sure', $result['status']);
        $this->assertSame($invoice->id, $result['candidates'][$result['pick']]['targets'][0]['id']);
    }

    public function test_satu_transfer_untuk_dua_invoice_klien_yang_sama(): void
    {
        $first  = $this->invoice(3_100_003);
        $second = $this->invoice(4_200_007);

        $result = $this->match([$this->row('in', 7_300_010, 'TRSF E-BANKING CR ' . strtoupper($this->client))])[0];

        $this->assertSame('sure', $result['status']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($result['candidates'][$result['pick']]['targets'], 'id'));
    }

    public function test_pembayaran_sebagian_dan_potongan_pph23_perlu_dipilih(): void
    {
        // DPP 10.000.000 + PPN 11% = 11.100.000; dipotong PPh 23 2% × DPP = 200.000.
        $pph = $this->invoice(11_100_000, ['subtotal' => 10_000_000, 'total_vat' => 1_100_000, 'total_discount' => 0]);

        $results = $this->match([
            $this->row('in', 10_900_000, 'TRSF E-BANKING CR ' . strtoupper($this->client)),
            $this->row('in', 3_000_000, 'TRSF E-BANKING CR DP ' . strtoupper($this->client)),
        ]);

        $this->assertSame('sure', $results[0]['status'], 'Potongan PPh 23 yang pas + nama klien: pasti.');
        $this->assertStringContainsString('PPh 23', $results[0]['candidates'][0]['note']);
        $this->assertSame($pph->id, $results[0]['candidates'][0]['targets'][0]['id']);

        $this->assertSame('review', $results[1]['status']);
        $this->assertStringContainsString('pembayaran sebagian', $results[1]['candidates'][0]['note']);
    }

    public function test_retainer_bernominal_sama_dipilih_invoice_terdekat_sebelum_transfer(): void
    {
        $september = $this->invoice(8_888_819, ['date' => '2026-09-01', 'due_date' => '2026-09-15']);
        $oktober   = $this->invoice(8_888_819, ['date' => '2026-10-28', 'due_date' => '2026-11-04']);

        $result = $this->match([$this->row('in', 8_888_819, 'TRSF E-BANKING CR ' . strtoupper($this->client), '2026-11-03')])[0];

        $this->assertSame('sure', $result['status']);
        $this->assertSame($oktober->id, $result['candidates'][$result['pick']]['targets'][0]['id']);
        $this->assertNotSame($september->id, $result['candidates'][$result['pick']]['targets'][0]['id']);
        $this->assertStringContainsString('dipilih yang tanggalnya paling dekat sebelum transfer', $result['candidates'][$result['pick']]['note']);
        $this->assertSame(6, $result['candidates'][$result['pick']]['days']);
    }

    public function test_tanggal_invoice_yang_berdekatan_tetap_ditanyakan_dan_diurutkan(): void
    {
        $this->invoice(8_888_821, ['date' => '2026-10-28']);
        $closer = $this->invoice(8_888_821, ['date' => '2026-10-31']);

        $result = $this->match([$this->row('in', 8_888_821, 'TRSF E-BANKING CR ' . strtoupper($this->client), '2026-11-03')])[0];

        $this->assertSame('review', $result['status'], 'Selisih 3 hari belum cukup jelas untuk dipilih sendiri.');
        $this->assertSame($closer->id, $result['candidates'][0]['targets'][0]['id'], 'Yang tanggalnya terdekat di urutan pertama.');
    }

    public function test_nominal_sama_tanpa_nama_diurutkan_dari_tanggal_terdekat(): void
    {
        $this->invoice(8_888_823, ['date' => '2026-07-01', 'nama_perusahaan' => 'CV Lama ' . Str::upper(Str::random(5))]);
        $recent = $this->invoice(8_888_823, ['date' => '2026-11-01', 'nama_perusahaan' => 'CV Baru ' . Str::upper(Str::random(5))]);

        $result = $this->match([$this->row('in', 8_888_823, 'SETORAN TUNAI', '2026-11-03')])[0];

        $this->assertSame('review', $result['status']);
        $this->assertSame($recent->id, $result['candidates'][0]['targets'][0]['id']);
    }

    public function test_pengajuan_bernominal_sama_dipilih_yang_terdekat(): void
    {
        $budi = $this->employee('Budiman', 'Santosa');
        $this->expense($budi, 333_337)->update(['submission_date' => '2026-10-01']);
        $recent = $this->expense($budi, 333_337);
        $recent->update(['submission_date' => '2026-11-02']);

        $result = $this->match([$this->row('out', 333_337, 'TRSF E-BANKING DB BUDIMAN SANTOSA', '2026-11-03')])[0];

        $this->assertSame('sure', $result['status']);
        $this->assertSame($recent->id, $result['candidates'][$result['pick']]['targets'][0]['id']);
    }

    public function test_layar_tinjau_menampilkan_jarak_tanggal_invoice(): void
    {
        $this->user('Finance');
        $this->invoice(15_440_000, ['date' => '2026-10-28']);

        $this->sendDocument($this->klikBca([['03/11', 'SETORAN TUNAI', 15_440_000, 'CR']], opening: 0));
        $this->send($this->tap('mut:rev'));

        $this->assertStringContainsString('invoice dibuat 6 hari sebelum transfer', $this->lastText());
        $this->assertStringContainsString('nominal = sisa tagihan Rp 15.440.000 persis', $this->lastText());
        $this->assertMatchesRegularExpression('/— <b>\d+%<\/b>/', $this->lastText());
        $this->assertStringContainsString('· 28 Okt ·', $this->lastText());
    }

    public function test_uang_keluar_ke_expense_dan_advance_karyawan(): void
    {
        $budi    = $this->employee('Budiman', 'Santosa');
        $expense = $this->expense($budi, 487_313);
        $advance = $this->advance($budi, 2_345_671);

        $results = $this->match([
            $this->row('out', 487_313, 'TRSF E-BANKING DB 0411/FTFVA/WS95031 BUDIMAN SANTOSA'),
            $this->row('out', 2_345_671, 'BI-FAST DB BIF TRANSFER KE 014 BUDIMAN SANTOSA'),
        ]);

        $this->assertSame(['sure', 'sure'], [$results[0]['status'], $results[1]['status']]);
        $this->assertSame([['type' => 'expense', 'id' => $expense->id, 'amount' => 487_313.0]], $results[0]['candidates'][$results[0]['pick']]['targets']);
        $this->assertSame([['type' => 'advance', 'id' => $advance->id, 'amount' => 2_345_671.0]], $results[1]['candidates'][$results[1]['pick']]['targets']);
    }

    public function test_biaya_dan_bunga_bank_dilewati_tapi_nama_orang_tidak(): void
    {
        $results = $this->match([
            $this->row('out', 10_000, 'BIAYA ADM'),
            $this->row('out', 2_500, 'PAJAK BUNGA'),
            $this->row('in', 1_234, 'BUNGA TABUNGAN'),
            $this->row('in', 1_111_317, 'TRSF E-BANKING CR BUNGA LESTARI'),
        ]);

        $this->assertSame(['fee', 'fee', 'fee', 'none'], array_column($results, 'status'));
    }

    public function test_transaksi_yang_sudah_dicatat_manual_dilewati(): void
    {
        $invoice = $this->invoice(6_543_217);
        Transaction::create([
            'payment_account_id' => $this->account->id,
            'type'               => 'in',
            'category'           => 'invoice',
            'amount'             => 6_543_217,
            'reference_type'     => 'invoice',
            'reference_id'       => $invoice->id,
            'transaction_date'   => '2026-11-06',
            'description'        => "Pembayaran Invoice {$invoice->uuid} dari {$this->client}",
        ]);

        $result = $this->match([$this->row('in', 6_543_217, 'TRSF E-BANKING CR ' . strtoupper($this->client), '2026-11-03')])[0];

        $this->assertSame('recorded', $result['status']);
        $this->assertStringContainsString('Sudah tercatat di ERP', $result['note']);
    }

    // =================================================================
    // Mencatat
    // =================================================================

    public function test_mencatat_invoice_expense_dan_advance_dengan_tanggal_mutasi(): void
    {
        $user    = $this->user('Finance');
        $budi    = $this->employee('Budiman', 'Santosa');
        $invoice = $this->invoice(15_437_219);
        $expense = $this->expense($budi, 487_313);
        $advance = $this->advance($budi, 2_345_671);

        $in      = $this->row('in', 15_437_219, 'TRSF CR ' . $this->client, '2026-11-03');
        $out     = $this->row('out', 487_313, 'TRSF DB BUDIMAN', '2026-11-04');
        $payout  = $this->row('out', 2_345_671, 'BI-FAST BUDIMAN', '2026-11-05');
        $outcome = app(MutationRecorder::class)->record($this->account, $user, [
            ['row' => $in, 'targets' => [['type' => 'invoice', 'id' => $invoice->id, 'amount' => 15_437_219]]],
            ['row' => $out, 'targets' => [['type' => 'expense', 'id' => $expense->id, 'amount' => 487_313]]],
            ['row' => $payout, 'targets' => [['type' => 'advance', 'id' => $advance->id, 'amount' => 2_345_671]]],
        ], 'unggahan-tes');

        $this->assertCount(3, $outcome['recorded']);
        $this->assertSame([], $outcome['failed']);

        $this->assertSame('paid', $invoice->fresh()->status);
        $transaction = Transaction::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->sole();
        $this->assertSame('2026-11-03', $transaction->transaction_date->toDateString());
        $this->assertStringContainsString('(mutasi rekening)', $transaction->description);

        $expense->refresh();
        $this->assertSame(['Dibayarkan', 487_313.0], [$expense->status, (float) $expense->paid]);
        $this->assertStringStartsWith('Mutasi 04/11/2026: TRSF DB BUDIMAN', $expense->reference);

        $advance->refresh();
        $this->assertSame(['Dibayarkan', '2026-11-05'], [$advance->status, $advance->paid_at->toDateString()]);

        $this->assertEquals(15_437_219 - 487_313 - 2_345_671, $this->account->fresh()->current_balance);
        $this->assertSame(3, BankMutation::where('payment_account_id', $this->account->id)->count());
        $this->assertNotNull(BankMutation::where('fingerprint', $in['key'])->sole()->targets[0]['transaction_id']);
    }

    public function test_potongan_pph23_dicatat_lunas_tanpa_uang_masuk_tambahan(): void
    {
        $user    = $this->user('Finance');
        $invoice = $this->invoice(11_100_000, ['subtotal' => 10_000_000, 'total_vat' => 1_100_000, 'total_discount' => 0]);
        $row     = $this->row('in', 10_900_000, 'TRSF E-BANKING CR ' . strtoupper($this->client));

        $candidate = $this->match([$row])[0]['candidates'][0];
        $this->assertSame(['type' => 'invoice', 'id' => $invoice->id, 'amount' => 10_900_000.0, 'withholding' => 200_000.0, 'withholding_label' => 'PPh 23'], $candidate['targets'][0]);

        $outcome = app(MutationRecorder::class)->record($this->account, $user, [['row' => $row, 'targets' => $candidate['targets']]], 'pph');
        $this->assertSame([], $outcome['failed']);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(11_100_000, $invoice->paid);
        $this->assertEquals(10_900_000, Transaction::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->sum('amount'), 'Hanya uang yang benar-benar masuk.');
        $this->assertEquals(200_000, $invoice->invoiceMetas()->where('key', 'withholding')->value('value'));
        $this->assertEquals(10_900_000, $this->account->fresh()->current_balance);
    }

    public function test_biaya_bank_bisa_ikut_dicatat_supaya_saldo_sama_dengan_bank(): void
    {
        $this->user('Finance');
        $this->account->update(['initial_balance' => 1_000_000]);
        $this->account->syncBalanceFromTransactions();
        $invoice = $this->invoice(15_437_235);

        $this->sendDocument($this->klikBca([
            ['03/11', 'TRSF E-BANKING CR ' . strtoupper($this->client), 15_437_235, 'CR'],
            ['05/11', 'BIAYA ADM', 10_000, 'DB'],
        ], opening: 1_000_000));

        $this->assertStringContainsString('Siap dicatat (1)', $this->lastText());
        $this->assertButton('mut:fees');

        $this->send($this->tap('mut:fees'));
        $this->assertStringContainsString('Siap dicatat (2)', $this->lastText());
        $this->assertStringContainsString('Biaya / pajak bank', $this->lastText());

        $this->send($this->tap('mut:ok'));

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals(10_000, Transaction::where('payment_account_id', $this->account->id)->where('category', 'bank_fee')->sole()->amount);
        $this->assertEquals(1_000_000 + 15_437_235 - 10_000, $this->account->fresh()->current_balance);
        $this->assertStringContainsString('1 biaya/pajak bank dicatat', $this->lastText());
        $this->assertStringContainsString('saldo akhir di mutasi Rp 16.427.235 ✅', $this->lastText());
    }

    public function test_keterangan_bca_diurai_tanpa_kode_transaksi(): void
    {
        $transfer = \App\Services\BankStatement\BcaDescription::parse('TRSF E-BANKING CR 0311/FTSCY/WS95051 15000000.00 INV SC0132611 00012 PT NESTORY INDO');
        $this->assertSame(['transfer BCA', 15_000_000.0, 'INV SC0132611 00012 PT NESTORY INDO'], [$transfer['kind'], $transfer['echo'], $transfer['counterparty']]);

        $bifast = \App\Services\BankStatement\BcaDescription::parse('BI-FAST CR BIF TRANSFER DR 014 BUDI SANTOSO');
        $this->assertSame(['BI-FAST', '014', 'BUDI SANTOSO'], [$bifast['kind'], $bifast['bank'], $bifast['counterparty']]);

        $this->assertSame(['budi', 'santoso'], \App\Services\BankStatement\BcaDescription::nameWords('PEMBAYARAN DP BUDI SANTOSO'));
        $this->assertTrue(\App\Services\BankStatement\BcaDescription::sameSender(['budi', 'santoso'], ['budi', 'santos']), 'Nama terpotong BCA tetap sama.');
        $this->assertFalse(\App\Services\BankStatement\BcaDescription::sameSender(['budi', 'santoso'], ['budi', 'hartono']));
    }

    public function test_pengirim_yang_pernah_membayar_dikenali_walau_namanya_beda(): void
    {
        $user  = $this->user('Finance');
        $first = $this->invoice(5_100_000);
        $row   = $this->row('in', 5_100_000, 'BI-FAST CR BIF TRANSFER DR 014 HENDRA WIJAYAKUSUMA', '2026-10-25');

        // Pertama kali: nama pengirim (PIC, rekening pribadi) tidak ada di invoice.
        $this->assertSame('review', $this->match([$row])[0]['status']);
        app(MutationRecorder::class)->record($this->account, $user, [['row' => $row, 'targets' => [['type' => 'invoice', 'id' => $first->id, 'amount' => 5_100_000]]]], 'a');

        // Berikutnya: pengirim yang sama untuk invoice klien yang sama langsung pasti.
        $second = $this->invoice(6_200_000);
        $result = $this->match([$this->row('in', 6_200_000, 'BI-FAST CR BIF TRANSFER DR 014 HENDRA WIJAYAKUSUMA')])[0];

        $this->assertSame('sure', $result['status']);
        $this->assertSame($second->id, $result['candidates'][$result['pick']]['targets'][0]['id']);
        $this->assertContains('✓ pengirim ini pernah membayar invoice klien yang sama', $result['candidates'][$result['pick']]['reasons']);
    }

    public function test_nomor_po_dan_nama_di_deal_dikenali(): void
    {
        $deal    = \App\Models\Deal::forceCreate(['nama_perusahaan' => 'PT Sinar Galaksi ' . Str::upper(Str::random(4)), 'nama_pic' => 'Rina', 'alamat' => 'Jl. Tes']);
        $byDeal  = $this->invoice(7_333_337, ['nama_perusahaan' => 'Nama Lain Di Invoice', 'deal_id' => $deal->id]);
        $byPo    = $this->invoice(7_444_447, ['nama_perusahaan' => 'CV Tanpa Nama ' . Str::random(4), 'po_number' => 'PO-88123']);

        $results = $this->match([
            $this->row('in', 7_333_337, 'TRSF E-BANKING CR 0311/FTSCY/WS95051 7333337.00 ' . strtoupper($deal->nama_perusahaan)),
            $this->row('in', 7_444_447, 'TRSF E-BANKING CR 0311/FTSCY/WS95051 7444447.00 PEMBAYARAN PO88123'),
        ]);

        $this->assertSame(['sure', 'sure'], array_column($results, 'status'));
        $this->assertSame($byDeal->id, $results[0]['candidates'][$results[0]['pick']]['targets'][0]['id']);
        $this->assertSame($byPo->id, $results[1]['candidates'][$results[1]['pick']]['targets'][0]['id']);
    }

    public function test_ppn_wapu_dan_selisih_biaya_transfer(): void
    {
        $user = $this->user('Finance');
        // DPP 20.000.000 + PPN 11% = 22.200.000. BUMN memungut PPN dan memotong PPh 23: masuk 19.600.000.
        $wapu = $this->invoice(22_200_000, ['subtotal' => 20_000_000, 'total_vat' => 2_200_000, 'total_discount' => 0]);
        $fee  = $this->invoice(3_456_789);

        $results = $this->match([
            $this->row('in', 19_600_000, 'TRSF E-BANKING CR ' . strtoupper($this->client)),
            $this->row('in', 3_450_289, 'BI-FAST CR BIF TRANSFER DR 008 ' . strtoupper($this->client)),
        ]);

        $wapuTarget = collect($results[0]['candidates'])->firstWhere(fn ($candidate) => $candidate['targets'][0]['id'] === $wapu->id)['targets'][0];
        $this->assertSame([2_600_000.0, 'PPN (WAPU) + PPh 23'], [$wapuTarget['withholding'], $wapuTarget['withholding_label']]);

        $feeCandidate = collect($results[1]['candidates'])->firstWhere(fn ($candidate) => $candidate['targets'][0]['id'] === $fee->id);
        $this->assertSame([6_500.0, 'biaya transfer'], [$feeCandidate['targets'][0]['withholding'], $feeCandidate['targets'][0]['withholding_label']]);

        app(MutationRecorder::class)->record($this->account, $user, [['row' => $this->row('in', 19_600_000, 'X'), 'targets' => [$wapuTarget]]], 'wapu');
        $this->assertSame('paid', $wapu->fresh()->status);
        $this->assertStringContainsString('PPN (WAPU) + PPh 23', (string) $wapu->invoiceLogs()->latest('id')->value('log'));
    }

    public function test_mutasi_yang_sama_tidak_dicatat_dua_kali_dan_gagal_satu_tidak_menahan_lain(): void
    {
        $user    = $this->user('Finance');
        $invoice = $this->invoice(5_555_557);
        $paid    = $this->invoice(4_444_447, ['status' => 'paid', 'paid' => 4_444_447]);
        $row     = $this->row('in', 5_555_557, 'TRSF CR ' . $this->client);
        $items   = [
            ['row' => $row, 'targets' => [['type' => 'invoice', 'id' => $invoice->id, 'amount' => 5_555_557]]],
            ['row' => $this->row('in', 4_444_447, 'TRSF CR LAIN'), 'targets' => [['type' => 'invoice', 'id' => $paid->id, 'amount' => 4_444_447]]],
        ];

        $first = app(MutationRecorder::class)->record($this->account, $user, $items, 'a');
        $this->assertCount(1, $first['recorded']);
        $this->assertStringContainsString('sudah lunas', $first['failed'][0]['reason']);

        $second = app(MutationRecorder::class)->record($this->account, $user, [$items[0]], 'b');
        $this->assertSame([], $second['recorded']);
        $this->assertSame('Mutasi ini sudah pernah dicatat.', $second['failed'][0]['reason']);
        $this->assertSame(1, Transaction::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->count());

        // Mutasi yang sudah dicatat dikenali saat dicocokkan ulang.
        $this->assertSame('duplicate', $this->match([$row])[0]['status']);
    }

    // =================================================================
    // Bot
    // =================================================================

    public function test_finance_mengunggah_csv_lalu_mencatat_dengan_satu_tombol(): void
    {
        $this->user('Finance');
        $budi    = $this->employee('Budiman', 'Santosa');
        $invoice = $this->invoice(15_437_219);
        $expense = $this->expense($budi, 487_313);

        $this->sendDocument($this->klikBca([
            ['03/11', 'TRSF E-BANKING CR 0311/FTSCY/WS95051 ' . strtoupper($this->client), 15_437_219, 'CR'],
            ['04/11', 'TRSF E-BANKING DB 0411/FTFVA/WS95031 BUDIMAN SANTOSA', 487_313, 'DB'],
            ['05/11', 'BIAYA ADM', 10_000, 'DB'],
            ['06/11', 'TRSF E-BANKING CR 0611/FTSCY/WS95051 PENGIRIM TIDAK DIKENAL', 1_919_191, 'CR'],
        ], opening: 1_000_000));

        $text = $this->lastText();
        $this->assertStringContainsString('✅ Saldo cocok', $text);
        $this->assertStringContainsString('✅ <b>Siap dicatat (2)</b>', $text);
        $this->assertStringContainsString($invoice->uuid, $text);
        $this->assertStringContainsString("Expense #{$expense->id}", $text);
        $this->assertStringContainsString('❓ <b>Tanpa pasangan di ERP (1)</b>', $text);
        $this->assertStringContainsString('🏦 biaya/bunga bank 1', $text);
        $this->assertButton('mut:ok');
        $this->assertSame('unpaid', $invoice->fresh()->status, 'Belum ada yang berubah sebelum Catat.');

        $this->send($this->tap('mut:ok'));

        $this->assertStringContainsString('✅ <b>2 mutasi dicatat</b>', $this->lastText());
        $this->assertStringContainsString('1 invoice dibayar', $this->lastText());
        $this->assertStringContainsString('1 expense dibayarkan', $this->lastText());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('Dibayarkan', $expense->fresh()->status);

        // Tombol yang ditekan lagi tidak mencatat dua kali.
        $this->send($this->tap('mut:ok'));
        $this->assertStringContainsString('sudah tidak berlaku', $this->lastText());
        $this->assertSame(1, Transaction::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->count());
    }

    public function test_mutasi_yang_ragu_ditinjau_dan_dipilih(): void
    {
        $this->user('Finance');
        $invoice = $this->invoice(15_450_000);

        $this->sendDocument($this->klikBca([
            ['03/11', 'SETORAN TUNAI', 15_450_000, 'CR'],
        ], opening: 0));

        $this->assertStringContainsString('🟡 <b>Perlu dipilih (1)</b>', $this->lastText());
        $this->assertButton('mut:rev');

        $this->send($this->tap('mut:rev'));
        $this->assertStringContainsString('Tinjau 1 dari 1', $this->lastText());
        $this->assertStringContainsString($invoice->uuid, $this->lastText());
        $this->assertStringContainsString('nama/nomor tidak ada di berita transfer', $this->lastText());

        $index = 0;
        $this->send($this->tap("mut:pick:0:{$index}"));
        $this->assertStringContainsString('✅ <b>Siap dicatat (1)</b>', $this->lastText());
        $this->assertStringContainsString('(dipilih)', $this->lastText());

        $this->send($this->tap('mut:ok'));
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_rekening_ditanyakan_bila_nomornya_tidak_dikenal(): void
    {
        $this->user('Finance');
        $invoice = $this->invoice(15_437_227);
        $csv     = str_replace($this->accountNumber, '5550001112', $this->klikBca([
            ['03/11', 'TRSF E-BANKING CR ' . strtoupper($this->client), 15_437_227, 'CR'],
        ], opening: 0));

        $this->sendDocument($csv);

        $this->assertStringContainsString('Mutasi ini untuk rekening yang mana?', $this->lastText());
        $this->assertStringContainsString('5550001112', $this->lastText());

        $this->send($this->tap("mut:acc:{$this->account->id}"));
        $this->assertStringContainsString('Nomor rekening di mutasi (5550001112) berbeda', $this->lastText());
        $this->assertStringContainsString('✅ <b>Siap dicatat (1)</b>', $this->lastText());
    }

    public function test_unggahan_ulang_tidak_mencatat_lagi(): void
    {
        $this->user('Finance');
        $invoice = $this->invoice(15_437_229);
        $csv     = $this->klikBca([['03/11', 'TRSF E-BANKING CR ' . strtoupper($this->client), 15_437_229, 'CR']], opening: 0);

        $this->sendDocument($csv);
        $this->send($this->tap('mut:ok'));
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->sendDocument($csv);
        $this->assertStringContainsString('♻️ sudah dicatat dari unggahan sebelumnya 1', $this->lastText());
        $this->assertStringContainsString('Siap dicatat (0)', $this->lastText());
        $this->assertStringNotContainsString('mut:ok', json_encode($this->lastRequest()?->data()['reply_markup'] ?? []));
    }

    public function test_karyawan_tidak_bisa_mengunggah_mutasi(): void
    {
        $this->user(null);
        $invoice = $this->invoice(15_437_231);

        $this->sendDocument($this->klikBca([['03/11', 'TRSF E-BANKING CR ' . strtoupper($this->client), 15_437_231, 'CR']], opening: 0));

        $this->assertStringNotContainsString('Siap dicatat', $this->lastText());
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, BankMutation::where('payment_account_id', $this->account->id)->count());
    }

    public function test_perintah_mutasi_menjelaskan_cara_pakai(): void
    {
        $this->user('Finance');

        $this->send($this->text('/mutasi'));

        $this->assertStringContainsString('Catat pembayaran dari mutasi rekening', $this->lastText());
        $this->assertStringContainsString('CSV / Excel', $this->lastText());
    }

    // =================================================================
    // Helper
    // =================================================================

    /**
     * CSV seperti unduhan mutasi KlikBCA.
     *
     * @param  array<int, array{0: string, 1: string, 2: int, 3: string}>  $rows
     */
    private function klikBca(array $rows, float $opening, ?float $closing = null): string
    {
        $balance = $opening;
        $lines   = [
            "No. rekening : {$this->accountNumber}",
            'Nama : PT DIGITALK KREATIF',
            'Periode : 01/11/2026 - 07/11/2026',
            'Kode Mata Uang : IDR',
            '',
            'Tanggal Transaksi,Keterangan,Cabang,Jumlah,,Saldo',
        ];

        foreach ($rows as [$date, $description, $amount, $flag]) {
            $balance += $flag === 'CR' ? $amount : -$amount;
            $lines[]  = "'{$date},\"{$description}\",'0000',\"" . number_format($amount, 2, '.', ',') . "\",{$flag},\"" . number_format($balance, 2, '.', ',') . '"';
        }

        $lines[] = '';
        $lines[] = 'Saldo Awal,"' . number_format($opening, 2, '.', ',') . '"';
        $lines[] = 'Saldo Akhir,"' . number_format($closing ?? $balance, 2, '.', ',') . '"';

        return implode("\n", $lines) . "\n";
    }

    private function read(string $data, string $name = 'mutasi.csv'): array
    {
        $result = app(StatementReader::class)->read($data, $name);
        $this->assertArrayHasKey('statement', $result, $result['error'] ?? '');

        return $result['statement'];
    }

    private function match(array $rows): array
    {
        return app(MutationMatcher::class)->match($rows, $this->account);
    }

    private function row(string $direction, float $amount, string $description, string $date = '2026-11-03'): array
    {
        return ['key' => hash('sha256', Str::random(20)), 'date' => $date, 'description' => $description, 'amount' => (float) $amount, 'direction' => $direction, 'balance' => null];
    }

    private function invoice(float $total, array $attributes = []): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => '#TES/' . Str::upper(Str::random(8)),
            'user_id'         => User::value('id'),
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'unpaid',
            'nama_perusahaan' => $this->client,
            'date'            => '2026-10-20',
            'due_date'        => '2026-11-20',
            'subtotal'        => $total,
            'total_discount'  => 0,
            'total_vat'       => 0,
            'grand_total'     => $total,
            'paid'            => 0,
        ]);
    }

    private function expense(User $owner, float $amount): Expense
    {
        return Expense::create(['user_id' => $owner->id, 'submission_date' => '2026-11-01', 'category' => 'Transport', 'keterangan_expense' => 'Grab ke klien', 'amount' => $amount, 'paid' => 0, 'status' => 'Disetujui']);
    }

    private function advance(User $owner, float $amount): Advance
    {
        return Advance::create(['user_id' => $owner->id, 'submission_date' => '2026-11-01', 'category' => 'Project Support', 'keterangan_advance' => 'Sewa alat shooting', 'amount' => $amount, 'status' => 'Disetujui']);
    }

    private function employee(string $firstname, string $lastname): User
    {
        return User::forceCreate([
            'username'     => 'emp_' . Str::lower(Str::random(8)),
            'firstname'    => $firstname,
            'lastname'     => $lastname,
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
    }

    private function user(?string $role): User
    {
        $user = User::forceCreate([
            'username'           => 'mut_' . Str::lower(Str::random(8)),
            'firstname'          => 'Tes',
            'lastname'           => $role ?? 'Karyawan',
            'email'              => Str::lower(Str::random(10)) . '@example.test',
            'password'           => bcrypt('rahasia'),
            'is_active'          => true,
            'push_enabled'       => false,
            'telegram_chat_id'   => (string) $this->chatId,
            'telegram_linked_at' => now(),
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function fakeTelegram(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/getFile')                    => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/mutasi.csv', 'file_size' => strlen($this->csv)]]),
                str_contains($request->url(), 'api.telegram.org/file/')       => Http::response($this->csv),
                str_contains($request->url(), 'generativelanguage.googleapis') => $this->gemini
                    ? Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($this->gemini)]]]]]])
                    : Http::response(['error' => ['message' => 'tidak dipakai']], 500),
                default                                                       => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            };
        });
    }

    private function sendDocument(string $csv): void
    {
        $this->csv = $csv;

        $this->send([
            'update_id' => 820_000_000 + ++$this->updateId,
            'message'   => [
                'message_id' => 10 + $this->updateId,
                'chat'       => ['id' => $this->chatId, 'type' => 'private'],
                'from'       => ['id' => $this->chatId, 'is_bot' => false, 'first_name' => 'Tes'],
                'document'   => ['file_id' => 'f' . $this->updateId, 'file_unique_id' => 'u' . $this->updateId, 'file_name' => 'mutasi.csv', 'mime_type' => 'text/csv', 'file_size' => strlen($csv)],
            ],
        ]);
    }

    private function send(array $update): void
    {
        $this->postJson('/api/telegram/finance-webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function text(string $text): array
    {
        return [
            'update_id' => 820_000_000 + ++$this->updateId,
            'message'   => [
                'message_id' => 10 + $this->updateId,
                'chat'       => ['id' => $this->chatId, 'type' => 'private'],
                'from'       => ['id' => $this->chatId, 'is_bot' => false, 'first_name' => 'Tes'],
                'text'       => $text,
            ],
        ];
    }

    private function tap(string $data): array
    {
        return [
            'update_id'      => 820_000_000 + ++$this->updateId,
            'callback_query' => [
                'id'      => 'cb-' . $this->updateId,
                'data'    => $data,
                'message' => ['message_id' => 1, 'chat' => ['id' => $this->chatId, 'type' => 'private']],
            ],
        ];
    }

    private function lastRequest(): ?Request
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => preg_match('#/(sendMessage|editMessageText)$#', $request->url()))
            ->last();
    }

    private function lastText(): string
    {
        return (string) $this->lastRequest()?->data()['text'];
    }

    private function assertButton(string $callbackData): void
    {
        $this->assertStringContainsString(
            '"callback_data":"' . $callbackData . '"',
            json_encode($this->lastRequest()?->data()['reply_markup'] ?? []),
            "Tombol {$callbackData} tidak ada."
        );
    }
}
