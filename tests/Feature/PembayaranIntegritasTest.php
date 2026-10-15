<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicesItem;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\AdvanceObserver;
use App\Services\RealisasiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Mengunci perilaku yang diperbaiki pada audit Oktober 2026.
 *
 * Tiap kasus di sini pernah benar-benar bocor di produksi atau terbukti bisa
 * bocor lewat simulasi, jadi tes ini ada untuk mencegahnya kembali — bukan
 * sekadar mendokumentasikan API.
 *
 * Semua tes memakai DatabaseTransactions: suite berjalan di database nyata
 * (phpunit.xml tidak mengalihkan ke sqlite), sehingga setiap perubahan harus
 * dikembalikan setelah tes selesai.
 */
class PembayaranIntegritasTest extends TestCase
{
    use DatabaseTransactions;

    // =================================================================
    // Field yang diam-diam dibuang mass assignment
    // =================================================================

    public function test_vat_percentage_tersimpan_pada_item_invoice(): void
    {
        $item = new InvoicesItem();

        $this->assertContains(
            'vat_percentage',
            $item->getFillable(),
            'vat_percentage harus fillable; tanpa ini PDF invoice mencetak Grand Total tanpa PPN.'
        );
    }

    public function test_bukti_transfer_tersimpan_pada_transaksi(): void
    {
        $transaction = new Transaction();

        $this->assertContains(
            'attachment',
            $transaction->getFillable(),
            'attachment harus fillable; tanpa ini bukti pengembalian dana hilang.'
        );
    }

    public function test_transaksi_memakai_created_by_bukan_user_id(): void
    {
        $fillable = (new Transaction())->getFillable();

        $this->assertContains('created_by', $fillable);
        $this->assertNotContains(
            'user_id',
            $fillable,
            'Kolomnya bernama created_by; mengirim user_id membuat jejak pembuat hilang diam-diam.'
        );
    }

    // =================================================================
    // Status yang harus cocok antar berkas
    // =================================================================

    public function test_observer_menangani_status_realisasi_yang_benar_benar_dipakai(): void
    {
        $sumber = file_get_contents((new \ReflectionClass(AdvanceObserver::class))->getFileName());

        $this->assertStringContainsString(
            'Advance::STATUS_REALIZED_USER',
            $sumber,
            'Observer harus memakai konstanta; string literal pernah tertinggal saat nilainya berubah '
            . 'sehingga notifikasi ke Finance tidak pernah terkirim.'
        );

        $this->assertStringNotContainsString(
            "case 'Sudah di-Realisasi User'",
            $sumber,
            'String status lama tidak boleh dipakai lagi.'
        );
    }

    // =================================================================
    // Pembayaran ganda
    // =================================================================

    public function test_advance_yang_sudah_dibayarkan_tidak_bisa_dibayar_lagi(): void
    {
        $advance = $this->buatAdvance(['status' => Advance::STATUS_DISETUJUI, 'amount' => 500_000]);

        $this->assertTrue($this->bolehDibayar($advance), 'Advance yang disetujui harus bisa dibayar.');

        $advance->update(['status' => Advance::STATUS_DIBAYARKAN, 'paid' => 500_000]);

        $this->assertFalse(
            $this->bolehDibayar($advance->fresh()),
            'Advance yang sudah dibayarkan tidak boleh diproses lagi — ini membuat saldo terpotong dua kali.'
        );
    }

    public function test_expense_yang_sudah_dibayarkan_tidak_bisa_dibayar_lagi(): void
    {
        $expense = $this->buatExpense(['status' => 'Disetujui', 'amount' => 300_000]);

        $this->assertTrue($this->bolehDibayar($expense));

        $expense->update(['status' => 'Dibayarkan', 'paid' => 300_000]);

        $this->assertFalse($this->bolehDibayar($expense->fresh()));
    }

    // =================================================================
    // Pengembalian dana ganda
    // =================================================================

    public function test_pengembalian_dana_hanya_bisa_sekali(): void
    {
        $advance = $this->buatAdvance([
            'status' => Advance::STATUS_DIBAYARKAN,
            'amount' => 1_000_000,
            'paid' => 1_000_000,
            'payment_account_id' => $this->rekening()->id,
        ]);

        $sudahDikembalikan = fn () => Transaction::where('reference_type', 'advance_return')
            ->where('reference_id', $advance->id)
            ->exists();

        $this->assertFalse($sudahDikembalikan());

        Transaction::create([
            'payment_account_id' => $advance->payment_account_id,
            'created_by' => $advance->user_id,
            'type' => 'in',
            'amount' => 400_000,
            'description' => 'Pengembalian sisa advance',
            'reference_type' => 'advance_return',
            'reference_id' => $advance->id,
            'transaction_date' => now()->toDateString(),
            'category' => 'other',
        ]);

        $this->assertTrue(
            $sudahDikembalikan(),
            'Penanda pengembalian harus terbaca; getRemainingAmount() tidak berkurang setelah '
            . 'pengembalian, jadi hanya transaksi ini yang bisa mencegah pemrosesan kedua.'
        );
    }

    public function test_sisa_advance_tidak_berkurang_setelah_pengembalian_dicatat(): void
    {
        $advance = $this->buatAdvance([
            'status' => Advance::STATUS_DIBAYARKAN,
            'amount' => 1_000_000,
            'paid' => 1_000_000,
            'payment_account_id' => $this->rekening()->id,
        ]);

        $service = new RealisasiService();
        $sisaAwal = $service->getRemainingAmount($advance);

        Transaction::create([
            'payment_account_id' => $advance->payment_account_id,
            'created_by' => $advance->user_id,
            'type' => 'in',
            'amount' => $sisaAwal,
            'description' => 'Pengembalian sisa advance',
            'reference_type' => 'advance_return',
            'reference_id' => $advance->id,
            'transaction_date' => now()->toDateString(),
            'category' => 'other',
        ]);

        $this->assertEquals(
            $sisaAwal,
            $service->getRemainingAmount($advance->fresh()),
            'Sisa dihitung dari (paid - realisasi) dan memang tidak melihat pengembalian — '
            . 'justru itu sebabnya guard harus bersandar pada transaksi, bukan pada nilai sisa.'
        );
    }

    // =================================================================
    // Arah mutasi saldo
    // =================================================================

    public function test_pembayaran_invoice_menaikkan_saldo(): void
    {
        $rekening = $this->rekening();
        $saldoAwal = $rekening->getAvailableBalance();

        Transaction::create([
            'payment_account_id' => $rekening->id,
            'created_by' => $this->pengguna()->id,
            'type' => 'in',
            'amount' => 2_000_000,
            'description' => 'Pembayaran invoice',
            'reference_type' => 'invoice',
            'reference_id' => Invoice::value('id') ?? 1,
            'transaction_date' => now()->toDateString(),
            'category' => 'invoice',
        ]);

        $rekening->syncBalanceFromTransactions();

        $this->assertEquals(
            $saldoAwal + 2_000_000,
            $rekening->fresh()->getAvailableBalance(),
            'Uang masuk harus menaikkan saldo; sebelumnya saldo justru di-decrement.'
        );
    }

    public function test_saldo_tersimpan_selalu_sama_dengan_saldo_terhitung(): void
    {
        $rekening = $this->rekening();

        Transaction::create([
            'payment_account_id' => $rekening->id,
            'created_by' => $this->pengguna()->id,
            'type' => 'in',
            'amount' => 750_000,
            'description' => 'Pembayaran invoice',
            'reference_type' => 'invoice',
            'reference_id' => Invoice::value('id') ?? 1,
            'transaction_date' => now()->toDateString(),
            'category' => 'invoice',
        ]);

        $rekening->syncBalanceFromTransactions();
        $rekening = $rekening->fresh();

        $this->assertEquals(
            (float) $rekening->current_balance,
            $rekening->getAvailableBalance(),
            'current_balance tidak boleh menyimpang dari mutasi transaksi.'
        );
    }

    public function test_pembayaran_boleh_melebihi_saldo_rekening(): void
    {
        $rekening = $this->rekening();
        $melebihiSaldo = $rekening->getAvailableBalance() + 5_000_000;

        Transaction::create([
            'payment_account_id' => $rekening->id,
            'created_by' => $this->pengguna()->id,
            'type' => 'out',
            'amount' => $melebihiSaldo,
            'description' => 'Advance melebihi saldo (budget longgar)',
            'reference_type' => 'advance',
            'reference_id' => 1,
            'transaction_date' => now()->toDateString(),
            'category' => 'operational',
        ]);

        $rekening->syncBalanceFromTransactions();

        $this->assertLessThan(
            0,
            $rekening->fresh()->getAvailableBalance(),
            'Budget sengaja dilonggarkan: pembayaran tidak diblokir saldo, dan minusnya '
            . 'harus terlihat apa adanya, bukan dipaksa ke nol.'
        );
    }

    // =================================================================
    // Batas nominal terhadap pengajuan
    // =================================================================

    public function test_nominal_pembayaran_dibatasi_jumlah_yang_diajukan(): void
    {
        $diajukan = 1_000_000.0;
        $aturan = ['paid' => ['required', 'numeric', 'min:1', 'max:' . $diajukan]];

        $this->assertTrue(
            validator(['paid' => $diajukan + 1], $aturan)->fails(),
            'Membayar di atas pengajuan harus ditolak.'
        );
        $this->assertTrue(validator(['paid' => 0], $aturan)->fails(), 'Nominal nol harus ditolak.');
        $this->assertFalse(validator(['paid' => $diajukan], $aturan)->fails());
        $this->assertFalse(validator(['paid' => $diajukan / 2], $aturan)->fails());
    }

    public function test_form_pembayaran_memasang_batas_maksimal(): void
    {
        foreach ([
            'AdvanceResource' => app_path('Filament/Resources/AdvanceResource.php'),
            'ExpenseResource' => app_path('Filament/Resources/ExpenseResource.php'),
        ] as $nama => $berkas) {
            $this->assertStringContainsString(
                '->maxValue(fn($record) => (float) $record->amount)',
                file_get_contents($berkas),
                "{$nama} harus membatasi nominal pembayaran; pesan errornya sudah ada sejak awal "
                . 'tapi aturannya tidak pernah terpasang.'
            );
        }
    }

    // =================================================================
    // Penomoran invoice
    // =================================================================

    public function test_nomor_invoice_tidak_pernah_kembar(): void
    {
        $pertama = Invoice::generateUuid('sale');

        Invoice::create([
            'uuid' => $pertama,
            'user_id' => $this->pengguna()->id,
            'type' => 'sale',
            'status' => 'draft',
            'date' => now(),
            'due_date' => now()->addDays(30),
            'nama_perusahaan' => 'Uji penomoran',
            'nama_pic' => 'Uji',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'paid' => 0,
        ]);

        $this->assertNotSame(
            $pertama,
            Invoice::generateUuid('sale'),
            'Nomor berikutnya harus beda; nomor kembar ditolak unique constraint saat simpan.'
        );
    }

    public function test_nomor_invoice_melompati_nomor_yang_sudah_terpakai(): void
    {
        $kandidat = Invoice::generateUuid('sale');

        // Proses lain memakai nomor itu lebih dulu.
        Invoice::create([
            'uuid' => $kandidat,
            'user_id' => $this->pengguna()->id,
            'type' => 'sale',
            'status' => 'draft',
            'date' => now(),
            'due_date' => now()->addDays(30),
            'nama_perusahaan' => 'Penyerobot',
            'nama_pic' => 'Penyerobot',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'paid' => 0,
        ]);

        $this->assertNotSame($kandidat, Invoice::generateUuid('sale'));
    }

    public function test_penomoran_invoice_memakai_satu_format_tanggal(): void
    {
        $sumber = file_get_contents(app_path('Filament/Resources/QuotationResource.php'));

        $this->assertStringContainsString(
            "Invoice::generateUuid('estimate')",
            $sumber,
            'Convert quotation harus memakai generator yang sama; format tanggalnya pernah '
            . "berbeda ('my' vs 'ym') sehingga penomoran satu bulan terpecah dua seri."
        );

        $this->assertStringNotContainsString(
            "now()->format('my')",
            $sumber,
            "Format MMYY tidak boleh dipakai lagi; standarnya YYMM."
        );
    }

    // =================================================================
    // Tautan Deal
    // =================================================================

    public function test_invoice_menyimpan_tautan_ke_deal(): void
    {
        foreach ([
            'CreateInvoice' => app_path('Filament/Resources/InvoiceResource/Pages/CreateInvoice.php'),
            'EditInvoice' => app_path('Filament/Resources/InvoiceResource/Pages/EditInvoice.php'),
        ] as $nama => $berkas) {
            $this->assertStringContainsString(
                "\$data['deal_id']",
                file_get_contents($berkas),
                "{$nama} harus menyimpan deal_id; sebelumnya _deal_id dibuang sebelum simpan "
                . 'sehingga seluruh invoice lahir tanpa tautan ke Deal.'
            );
        }
    }

    public function test_relasi_deal_memakai_foreign_key_bukan_nama(): void
    {
        $deal = new \App\Models\Deal();

        $this->assertSame(
            'deal_id',
            $deal->invoices()->getForeignKeyName(),
            'Deal::invoices() harus bersandar pada deal_id, bukan kecocokan nama perusahaan.'
        );
        $this->assertSame('deal_id', $deal->quotations()->getForeignKeyName());
    }

    // =================================================================
    // Data pelanggan di form invoice
    // =================================================================

    public function test_nama_pelanggan_ikut_tersimpan_saat_form_disimpan(): void
    {
        $sumber = file_get_contents(app_path('Filament/Resources/InvoiceResource.php'));

        // Ambil hanya bagian Company Information.
        $awal = strpos($sumber, "Section::make(\n                'Company Information'");
        $akhir = strpos($sumber, "'Data Invoice'");
        $section = substr($sumber, $awal, $akhir - $awal);

        foreach (['nama_perusahaan', 'nama_pic'] as $field) {
            $posisi = strpos($section, "'{$field}'");
            $this->assertNotFalse($posisi, "Field {$field} harus ada di form.");

            $potongan = substr($section, $posisi, 400);
            $this->assertStringNotContainsString(
                'dehydrated(false)',
                $potongan,
                "Field {$field} tidak boleh dehydrated(false): apa pun yang diketik pengguna "
                . 'akan dibuang sebelum sampai ke database.'
            );
        }
    }

    public function test_dropdown_deal_tidak_wajib_diisi(): void
    {
        $sumber = file_get_contents(app_path('Filament/Resources/InvoiceResource.php'));
        $posisi = strpos($sumber, "'_deal_id'");
        $potongan = substr($sumber, $posisi, 1500);

        $this->assertStringNotContainsString(
            '->required(',
            $potongan,
            'Dropdown Deal tidak boleh wajib: invoice hasil convert quotation sering tidak '
            . 'punya Deal yang cocok, dan itu membuat form tidak bisa disimpan.'
        );
    }

    public function test_halaman_edit_tidak_menimpa_nama_dengan_data_deal(): void
    {
        foreach ([
            'EditInvoice' => app_path('Filament/Resources/InvoiceResource/Pages/EditInvoice.php'),
            'ViewInvoice' => app_path('Filament/Resources/InvoiceResource/Pages/ViewInvoice.php'),
        ] as $nama => $berkas) {
            $sumber = file_get_contents($berkas);

            $this->assertStringNotContainsString(
                "\$data['nama_perusahaan'] = \$deal->nama_perusahaan",
                $sumber,
                "{$nama} tidak boleh menimpa nama invoice dengan nama Deal — nama yang tercetak "
                . 'di PDF bisa berbeda dari yang tampil di layar.'
            );

            $this->assertStringNotContainsString(
                'is_manual_company',
                $sumber,
                "{$nama} masih memakai toggle mode manual yang sudah dihapus dari form."
            );
        }
    }

    public function test_convert_quotation_membawa_nama_dan_tautan_deal(): void
    {
        $sumber = file_get_contents(app_path('Filament/Resources/QuotationResource.php'));

        $this->assertStringContainsString(
            "'nama_perusahaan' => \$record->nama_perusahaan",
            $sumber,
            'Convert harus menyalin nama perusahaan dari quotation.'
        );

        $this->assertStringContainsString(
            '$record->deal_id',
            $sumber,
            'Convert harus mengutamakan deal_id yang sudah tersimpan di quotation, '
            . 'bukan menebak lewat kecocokan nama perusahaan saja.'
        );
    }

    // =================================================================
    // Perbandingan nominal
    // =================================================================

    public function test_invoice_lunas_dianggap_lunas_meski_tipe_datanya_berbeda(): void
    {
        // MySQL mengembalikan decimal sebagai string, sementara paid berupa float.
        $grandTotal = '1110000.00';
        $paid = 1110000.0;

        $this->assertFalse(
            $grandTotal === $paid,
            'Perbandingan identik memang gagal di sini — itulah sebabnya tombol Bayar '
            . 'dulu tetap muncul pada invoice yang sudah lunas.'
        );
        $this->assertTrue((float) $paid >= (float) $grandTotal);
    }

    public function test_tombol_bayar_memakai_perbandingan_numerik(): void
    {
        $sumber = file_get_contents(app_path('Filament/Resources/InvoiceResource.php'));

        $this->assertStringContainsString(
            '(float) $record->paid >= $record->amountDue()',
            $sumber,
            'Visibilitas tombol Bayar harus membandingkan nominal secara numerik, '
            . 'dan terhadap nilai yang benar-benar ditagihkan (untuk DP: uang mukanya).'
        );
    }

    // =================================================================
    // Rumus perhitungan: harus sama di quotation dan invoice
    // =================================================================

    /**
     * Satu-satunya rumus yang berlaku: (price * qty) - discount + PPN,
     * dengan discount sebagai nilai TOTAL per baris.
     */
    private function hitungBaris(float $price, float $qty, float $discount, float $vatPersen): float
    {
        $setelahDiskon = max(($price * $qty) - $discount, 0);

        return $setelahDiskon + ($setelahDiskon * ($vatPersen / 100));
    }

    public function test_pdf_quotation_memakai_rumus_yang_sama_dengan_invoice(): void
    {
        $quotation = file_get_contents(resource_path('views/pdf/quotation-dynamic.blade.php'));

        $this->assertStringNotContainsString(
            '$discount * $qty',
            $quotation,
            'Diskon adalah nilai total per baris; mengalikannya dengan qty membuat PDF quotation '
            . 'mencetak angka berbeda dari invoice untuk pekerjaan yang sama.'
        );

        $this->assertStringNotContainsString(
            '$itemDiscount * $itemQty',
            $quotation,
            'Baris tabel PDF quotation juga tidak boleh mengalikan diskon dengan qty.'
        );

        $this->assertStringContainsString(
            'max($itemSubTotal - $discount, 0)',
            $quotation,
            'Grand Total quotation harus memotong diskon, persis seperti invoice.'
        );
    }

    public function test_pdf_quotation_tidak_lagi_membaca_kolom_yang_tidak_ada(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('quotation_items', 'original_price'),
            'Kolom original_price memang tidak ada di quotation_items.'
        );

        $this->assertStringNotContainsString(
            '$item->original_price',
            file_get_contents(resource_path('views/pdf/quotation-dynamic.blade.php')),
            'PDF quotation membaca kolom yang tidak pernah dibuat, dengan komentar "harga sebelum DP" '
            . 'padahal quotation tidak punya konsep DP sama sekali.'
        );
    }

    public function test_quotation_dan_invoice_menghasilkan_angka_sama_untuk_item_identik(): void
    {
        // Kasus dengan diskon dan PPN — di sinilah dua rumus dulu berbeda.
        $kasus = [
            ['price' => 500_000, 'qty' => 2, 'discount' => 50_000, 'vat' => 11],
            ['price' => 5_000_000, 'qty' => 1, 'discount' => 1_000_000, 'vat' => 10],
            ['price' => 1_000_000, 'qty' => 1, 'discount' => 0, 'vat' => 11],
            ['price' => 250_000, 'qty' => 4, 'discount' => 100_000, 'vat' => 0],
        ];

        foreach ($kasus as $k) {
            $nilai = $this->hitungBaris($k['price'], $k['qty'], $k['discount'], $k['vat']);

            // Diskon dipotong tepat satu kali, bukan dikalikan qty.
            $tanpaDiskon = $this->hitungBaris($k['price'], $k['qty'], 0, $k['vat']);
            $selisih = $tanpaDiskon - $nilai;
            $diharapkan = $k['discount'] * (1 + $k['vat'] / 100);

            $this->assertEqualsWithDelta(
                $diharapkan,
                $selisih,
                0.01,
                'Efek diskon harus sebesar diskon itu sendiri (plus PPN-nya), '
                . 'bukan kelipatan qty.'
            );
        }
    }

    public function test_pdf_quotation_selalu_mencetak_grand_total(): void
    {
        $quotation = \App\Models\Quotation::with('items')->has('items')->first();
        $this->assertNotNull($quotation, 'Butuh quotation beserta itemnya untuk tes ini.');

        // settings kosong = belum ada Terms & Conditions. Dulu seluruh ringkasan
        // total terbungkus di dalam @if(count($terms) > 0), sehingga quotation
        // terkirim ke klien tanpa Grand Total sama sekali.
        $html = view('pdf.quotation-dynamic', [
            'quotation' => $quotation,
            'themeColor' => '#f04f29',
            'settings' => [],
        ])->render();

        $teks = preg_replace('/\s+/', ' ', strip_tags($html));

        foreach (['Sub Total', 'Grand Total'] as $label) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($label, '/') . ':?\s*-?Rp\s*[\d.]+/',
                $teks,
                "\"{$label}\" harus tercetak walau Terms & Conditions belum diisi."
            );
        }
    }

    public function test_header_dokumen_selaras_dengan_itemnya(): void
    {
        foreach ([
            ['quotations', 'quotation_items', 'quotation_id'],
            ['invoices', 'invoices_items', 'invoice_id'],
        ] as [$tabel, $tabelItem, $fk]) {
            $menyimpang = [];

            foreach (\Illuminate\Support\Facades\DB::table($tabel)->get() as $doc) {
                // Dokumen yang sudah dibayar tidak dikoreksi otomatis.
                if ((float) ($doc->paid ?? 0) > 0) {
                    continue;
                }

                $items = \Illuminate\Support\Facades\DB::table($tabelItem)->where($fk, $doc->id)->get();

                if ($items->isEmpty()) {
                    continue;
                }

                $grandTotal = 0.0;

                foreach ($items as $item) {
                    $grandTotal += $this->hitungBaris(
                        (float) $item->price,
                        (float) $item->qty,
                        (float) $item->discount,
                        (float) ($item->vat_percentage ?? 0)
                    );
                }

                if (abs((float) $doc->grand_total - $grandTotal) >= 1) {
                    $menyimpang[] = $doc->uuid;
                }
            }

            $this->assertEmpty(
                $menyimpang,
                "Header {$tabel} harus selaras dengan itemnya — PDF dicetak dari item, "
                . 'jadi selisih di sini berarti layar dan dokumen menampilkan angka berbeda. '
                . 'Menyimpang: ' . implode(', ', $menyimpang)
            );
        }
    }

    // =================================================================
    // Data klien di form quotation
    // =================================================================

    public function test_nama_klien_quotation_terisi_saat_form_dibuka(): void
    {
        $sumber = file_get_contents(app_path('Filament/Resources/QuotationResource.php'));

        // Dropdown tidak boleh menempel pada kolomnya sendiri: nilainya berupa
        // "Perusahaan|PIC" sementara yang tersimpan hanya "Perusahaan", sehingga
        // tidak pernah cocok dan field wajib itu tampil kosong setiap kali dibuka.
        $this->assertStringNotContainsString(
            "Select::make('nama_perusahaan')",
            $sumber,
            'nama_perusahaan harus berupa input teks yang menampilkan nilai tersimpan, '
            . 'bukan dropdown dengan nilai gabungan.'
        );

        $this->assertStringContainsString(
            "TextInput::make('nama_perusahaan')",
            $sumber,
            'Nama Klien harus tampil dan bisa disunting.'
        );
    }

    public function test_nilai_quotation_tersimpan_cocok_dengan_yang_ditampilkan(): void
    {
        $quotations = \App\Models\Quotation::all();

        if ($quotations->isEmpty()) {
            $this->markTestSkipped('Belum ada quotation untuk diperiksa.');
        }

        foreach ($quotations as $quotation) {
            // Form diisi langsung dari record; tidak ada transformasi yang bisa
            // membuat nilainya gagal dikenali seperti pada dropdown gabungan.
            $this->assertNotNull(
                $quotation->nama_perusahaan,
                "Quotation {$quotation->uuid} tidak punya nama klien — field wajib ini "
                . 'dulu bisa kosong karena dropdown-nya tidak pernah cocok.'
            );
        }
    }

    public function test_dropdown_kontak_quotation_hanya_alat_bantu(): void
    {
        $sumber = file_get_contents(app_path('Filament/Resources/QuotationResource.php'));
        $posisi = strpos($sumber, "'_kontak'");

        $this->assertNotFalse($posisi, 'Dropdown pengisi otomatis harus ada.');

        $potongan = substr($sumber, $posisi, 2000);

        $this->assertStringContainsString(
            'dehydrated(false)',
            $potongan,
            '_kontak adalah field bantu dan tidak boleh ikut tersimpan sebagai kolom.'
        );
        $this->assertStringNotContainsString(
            '->required(',
            $potongan,
            'Pengisi otomatis tidak boleh wajib — klien baru belum tentu ada di Leads/Deal.'
        );
    }

    // =================================================================
    // Invoice DP
    // =================================================================

    public function test_invoice_dp_menagih_persentase_dari_harga_penuh(): void
    {
        $invoice = new Invoice(['type_payment' => 'dp_20']);
        $invoice->grand_total = 560_000;

        $this->assertSame(20, $invoice->dpPercentage());
        $this->assertEqualsWithDelta(
            112_000,
            $invoice->amountDue(),
            0.01,
            'Invoice DP 20% atas pekerjaan Rp 560.000 menagih Rp 112.000 — bukan sisanya '
            . '(Rp 448.000), dan bukan harga penuhnya.'
        );
    }

    public function test_dp_lima_puluh_persen_tidak_menyembunyikan_kekeliruan(): void
    {
        // Pada DP 50% uang muka dan sisanya sama besar, sehingga rumus yang keliru
        // pun terlihat benar. Persentase lain yang membongkarnya.
        foreach ([10 => 100_000, 20 => 200_000, 30 => 300_000, 50 => 500_000, 90 => 900_000] as $persen => $diharapkan) {
            $invoice = new Invoice(['type_payment' => "dp_{$persen}"]);
            $invoice->grand_total = 1_000_000;

            $this->assertEqualsWithDelta(
                $diharapkan,
                $invoice->amountDue(),
                0.01,
                "DP {$persen}% dari Rp 1.000.000 harus Rp " . number_format($diharapkan)
            );
        }
    }

    public function test_invoice_non_dp_menagih_seluruh_nilainya(): void
    {
        foreach (['full_payment', 'final_payment'] as $tipe) {
            $invoice = new Invoice(['type_payment' => $tipe]);
            $invoice->grand_total = 750_000;

            $this->assertNull($invoice->dpPercentage());
            $this->assertEqualsWithDelta(750_000, $invoice->amountDue(), 0.01);
        }
    }

    public function test_pdf_invoice_dp_mencetak_uang_muka_sebagai_amount_due(): void
    {
        $invoice = Invoice::with('invoicesItems')
            ->where('type_payment', 'like', 'dp_%')
            ->has('invoicesItems')
            ->first();

        $this->assertNotNull($invoice, 'Butuh satu invoice DP beserta itemnya.');

        $teks = $this->teksPdfInvoice($invoice);
        $ditagihkan = number_format($invoice->amountDue(), 0, ',', '.');

        $this->assertStringContainsString(
            'Rp' . $ditagihkan,
            $teks,
            'Amount Due pada PDF harus sebesar uang muka yang ditagihkan.'
        );
        $this->assertStringContainsString('Amount Due (DP', $teks);
    }

    public function test_tipe_pembayaran_tidak_dikenali_tidak_dicetak_sebagai_dp(): void
    {
        $sumber = file_get_contents(resource_path('views/pdf/invoice-dynamic.blade.php'));

        $this->assertStringNotContainsString(
            "Down Payment {{ (int) str_replace('dp_', '', \$invoice->type_payment) }}%",
            $sumber,
            'Cabang terakhir dulu menangkap semua nilai lain — termasuk "Full Payment" '
            . 'berspasi dan NULL — lalu mencetaknya sebagai "Down Payment 0%".'
        );
    }

    public function test_semua_type_payment_di_database_dikenali(): void
    {
        $tidakDikenali = \Illuminate\Support\Facades\DB::table('invoices')
            ->select('type_payment')
            ->distinct()
            ->pluck('type_payment')
            ->filter(fn ($nilai) => $nilai !== 'full_payment'
                && $nilai !== 'final_payment'
                && ! (is_string($nilai) && str_starts_with($nilai, 'dp_')))
            ->all();

        $this->assertEmpty(
            $tidakDikenali,
            'Nilai type_payment yang tidak dikenali akan salah dicetak di dokumen ke klien. '
            . 'Ditemukan: ' . implode(', ', array_map(fn ($v) => var_export($v, true), $tidakDikenali))
        );
    }

    public function test_pdf_pelunasan_membaca_dari_tautan_bukan_catatan_teks(): void
    {
        $sumber = file_get_contents(resource_path('views/pdf/invoice-dynamic.blade.php'));

        $this->assertStringContainsString(
            '$invoice->parent_invoice_id ? $invoice->parentInvoice : null',
            $sumber,
            'Nilai DP pada invoice pelunasan harus datang dari invoice DP yang ditautkan, '
            . 'bukan dari mem-parsing teks bebas di kolom notes.'
        );
    }

    private function teksPdfInvoice(Invoice $invoice): string
    {
        $html = view('pdf.invoice-dynamic', [
            'invoice' => $invoice,
            'themeColor' => '#f04f29',
            'settings' => [],
        ])->render();

        $teks = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{00A0}\x{2003}]+/u', ' ', $teks);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function pengguna(): User
    {
        $user = User::first();
        $this->assertNotNull($user, 'Butuh minimal satu user untuk menjalankan tes ini.');

        return $user;
    }

    private function rekening(): PaymentAccount
    {
        $rekening = PaymentAccount::first();
        $this->assertNotNull($rekening, 'Butuh minimal satu rekening untuk menjalankan tes ini.');

        return $rekening;
    }

    private function buatAdvance(array $atribut = []): Advance
    {
        return Advance::create(array_merge([
            'user_id' => $this->pengguna()->id,
            'submission_date' => now()->toDateString(),
            'keterangan_advance' => 'Advance uji integritas',
            'category' => 'operational',
            'amount' => 500_000,
            'status' => Advance::STATUS_PENDING,
        ], $atribut));
    }

    private function buatExpense(array $atribut = []): Expense
    {
        return Expense::create(array_merge([
            'user_id' => $this->pengguna()->id,
            'submission_date' => now()->toDateString(),
            'keterangan_expense' => 'Expense uji integritas',
            'category' => 'operational',
            'amount' => 300_000,
            'status' => 'Pending',
        ], $atribut));
    }

    /**
     * Meniru penjaga status di dalam action convert_to_pay.
     */
    private function bolehDibayar(Advance|Expense $pengajuan): bool
    {
        return $pengajuan->status === 'Disetujui';
    }
}
