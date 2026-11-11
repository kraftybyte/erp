<?php

namespace App\Models;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TomatoPHP\FilamentInvoices\Models\Invoice as BaseInvoice;

/**
 * Extended Invoice model with PaymentAccount support
 *
 * @property int|null $payment_account_id
 */
class Invoice extends BaseInvoice
{
    /**
     * Akhiran uuid proforma yang dihapus: "#PI/0132611/00002-BATAL-15". Nomor
     * aslinya lepas dari unique index sehingga bisa dipakai proforma berikutnya.
     */
    public const TANDA_BATAL = '-BATAL-';

    protected static function booted(): void
    {
        // Proforma dihapus: nomornya dilepas supaya nomor proforma tidak melompat.
        // Invoice #SC tetap memegang nomornya (sudah dikirim ke klien).
        static::deleted(function (Invoice $invoice) {
            if ($invoice->type === 'estimate' && ! $invoice->isForceDeleting() && ! str_contains($invoice->uuid, self::TANDA_BATAL)) {
                $invoice->forceFill(['uuid' => $invoice->uuid . self::TANDA_BATAL . $invoice->getKey()])->saveQuietly();
            }
        });

        // Dipulihkan: kembali ke nomor lamanya, atau nomor baru bila sudah dipakai lagi.
        static::restoring(function (Invoice $invoice) {
            if (! str_contains($invoice->uuid, self::TANDA_BATAL)) {
                return;
            }

            $nomor         = Str::before($invoice->uuid, self::TANDA_BATAL);
            $invoice->uuid = static::nomorTerpakai($nomor) ? static::generateUuid('estimate') : $nomor;
        });
    }

    /**
     * Override the invoicesItems relationship to use custom model
     */
    public function invoicesItems()
    {
        return $this->hasMany(InvoicesItem::class);
    }

    protected $fillable = [
        // === PaymentAccount integration ===
        'payment_account_id',

        // === Deal integration ===
        'deal_id',

        // === Rantai pembayaran: invoice DP -> invoice pelunasan ===
        'parent_invoice_id',

        // === Nomor #PI sebelum proforma dikonversi menjadi invoice ===
        'proforma_uuid',

        // === Bank fields ===
        'bank_account',
        'bank_account_owner',
        'bank_iban',
        'bank_swift',
        'bank_address',
        'bank_branch',
        'bank_name',
        'bank_city',
        'bank_country',
        'is_bank_transfer',

        // === Core invoice fields ===
        'currency_id',
        'user_id',
        'uuid',
        'nama_perusahaan',
        'nama_pic',
        'telephone',
        'alamat',
        'type',
        'description',
        'status',
        'subtotal',
        'grand_total',
        'total_discount',
        'total_vat',
        'vat_percentage',
        'paid',
        'date',
        'due_date',
        'invoice_id',
        'is_default',
        'total',
        'name',
        'for_type',
        'for_id',
        'from_type',
        'from_id',
        'type_payment',
        'po_number',
        'faktur',
        // PDF faktur pajak keluaran, di disk public (halaman Faktur).
        'faktur_file',
        'delivery_note_number',
        'is_activated',
        'is_offer',
        'send_email',
        'notes',
        'order_id',
        'category_id',
        'shipping',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'date' => 'datetime',
        'is_offer' => 'bool',
        'is_activated' => 'bool',
        'send_email' => 'bool',
        'is_bank_transfer' => 'bool',
    ];

    /**
     * Relationship with PaymentAccount
     */
    public function paymentAccount()
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    /**
     * Relationship with Deal
     */
    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * Project yang dibuat dari invoice ini.
     */
    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Invoice DP yang mendahului invoice pelunasan ini.
     */
    public function parentInvoice()
    {
        return $this->belongsTo(Invoice::class, 'parent_invoice_id');
    }

    /**
     * Invoice-invoice lanjutan (mis. pelunasan) dari invoice DP ini.
     */
    public function childInvoices()
    {
        return $this->hasMany(Invoice::class, 'parent_invoice_id');
    }

    /**
     * Persentase DP, atau null bila invoice ini bukan DP.
     */
    public function dpPercentage(): ?int
    {
        $tipe = (string) $this->type_payment;

        return str_starts_with($tipe, 'dp_')
            ? (int) str_replace('dp_', '', $tipe)
            : null;
    }

    /**
     * DP yang sudah ditagih sebelum invoice pelunasan ini: dari invoice DP yang
     * ditautkan (parent_invoice_id), atau, untuk invoice lama tanpa tautan,
     * catatan "DP: 50% - Rp 20.000.000". Null bila bukan pelunasan atau DP-nya
     * tidak diketahui. Sumber yang sama dipakai PDF, tombol Bayar, laporan
     * piutang, dan rekap faktur.
     *
     * @return array{percent: int, amount: float, full: float}|null  full = harga penuh pekerjaan
     */
    public function settledDp(): ?array
    {
        if ($this->type_payment !== 'final_payment') {
            return null;
        }

        if ($this->parent_invoice_id && ($dp = $this->parentInvoice)) {
            $full    = (float) $dp->grand_total ?: (float) $this->grand_total;
            $percent = $dp->dpPercentage();
            $amount  = $percent !== null ? $full * $percent / 100 : (float) $dp->paid;

            if ($percent === null && $amount <= 0) {
                return null;
            }

            return ['percent' => (int) ($percent ?? round($amount / max($full, 1) * 100)), 'amount' => $amount, 'full' => $full];
        }

        if (preg_match('/DP[:\s]*(\d+)%\s*[-–:]*\s*[Rr]p\s*([\d.,]+)/i', trim((string) $this->notes), $match)) {
            $percent = (int) $match[1];
            $amount  = (float) str_replace('.', '', $match[2]);

            if ($percent > 0 && $percent < 100 && $amount > 0) {
                return ['percent' => $percent, 'amount' => $amount, 'full' => $amount / ($percent / 100)];
            }
        }

        return null;
    }

    /**
     * Nominal yang ditagihkan lewat invoice ini.
     *
     * Invoice DP menagih DP-nya: persentase dari harga penuh (grand_total
     * menyimpan harga penuh pekerjaan). Invoice pelunasan menagih sisanya:
     * harga penuh dikurangi DP yang sudah ditagih, sama dengan "Sisa
     * Pembayaran" di PDF. Dulu pelunasan ditagih penuh, sehingga yang sudah
     * dibayar klien sesuai PDF tetap tercatat belum lunas.
     */
    public function amountDue(): float
    {
        if (($persen = $this->dpPercentage()) !== null) {
            return (float) $this->grand_total * ($persen / 100);
        }

        if ($dp = $this->settledDp()) {
            return max(0, $dp['full'] - $dp['amount']);
        }

        return (float) $this->grand_total;
    }

    /**
     * Salinan draft invoice ini beserta itemnya, bernomor baru (Duplikat dan
     * Buat pelunasan).
     */
    public function duplicateAsDraft(array $overrides = []): self
    {
        $copy = static::create($overrides + [
            'uuid'                 => static::generateUuid($this->type ?? 'sale'),
            'date'                 => now(),
            'due_date'             => now()->addDays(30),
            'type'                 => $this->type ?? 'sale',
            'status'               => 'draft',
            'type_payment'         => $this->type_payment ?? 'full_payment',
            'nama_perusahaan'      => $this->nama_perusahaan,
            'nama_pic'             => $this->nama_pic,
            'telephone'            => $this->telephone,
            'alamat'               => $this->alamat,
            'deal_id'              => $this->deal_id,
            'po_number'            => null,
            'faktur'               => null,
            'delivery_note_number' => null,
            'subtotal'             => $this->subtotal,
            'total_discount'       => $this->total_discount,
            'total_vat'            => $this->total_vat,
            'grand_total'          => $this->grand_total,
            'paid'                 => 0,
            'currency_id'          => $this->currency_id,
            'notes'                => $this->notes,
            'user_id'              => auth()->id() ?? $this->user_id,
        ]);

        foreach ($this->invoicesItems as $item) {
            $copy->invoicesItems()->create([
                'item'           => $item->item,
                'description'    => $item->description,
                'qty'            => $item->qty,
                'price'          => $item->price,
                'discount'       => $item->discount,
                'vat_percentage' => $item->vat_percentage,
                'vat'            => $item->vat,
                'total'          => $item->total,
                'original_price' => $item->original_price,
            ]);
        }

        return $copy;
    }

    /**
     * Generate UUID based on type and reset per month
     * Format: #SC/0132607/XXXXX atau #PI/0132607/XXXXX. Prefix diatur di
     * Settings → General → Invoice Template (bawaan SC dan PI).
     *
     * Nomor urut diambil langsung di database (MAX atas 5 digit terakhir), bukan
     * dengan menarik seluruh invoice bulan berjalan ke memory. Nomor yang sudah
     * terpakai juga dilewati, sehingga pemanggilan bersamaan oleh dua pengguna
     * tidak menghasilkan nomor kembar yang ditolak unique constraint.
     *
     * Proforma memakai lebih dulu nomor proforma bulan ini yang dihapus, supaya
     * nomornya tidak melompat. Invoice #SC tidak: nomor yang sudah dikirim ke
     * klien tidak diberikan ke invoice lain.
     */
    public static function generateUuid(string $type = 'sale'): string
    {
        $settings   = app(GeneralSettings::class);
        $isProforma = $type === 'estimate';
        $prefix     = $isProforma
            ? $settings->numberPrefix('proforma_prefix', 'PI')
            : $settings->numberPrefix('invoice_prefix', 'SC');

        // Format: 013YYMM (mis. 0132607 untuk Juli 2026)
        $awalan = '#' . $prefix . '/013' . now()->format('ym') . '/';

        if ($isProforma && ($dilepas = static::nomorProformaDilepas($awalan))) {
            return $dilepas;
        }

        // Urutan tertinggi bulan ini, dihitung di sisi database. Proforma yang
        // sudah menjadi invoice tetap memegang nomor PI-nya (proforma_uuid).
        $urutanTertinggi = max(
            (int) static::withTrashed()
                ->where('uuid', 'like', $awalan . '%')
                ->where('uuid', 'not like', '%' . self::TANDA_BATAL . '%')
                ->max(DB::raw('CAST(RIGHT(uuid, 5) AS UNSIGNED)')),
            (int) static::withTrashed()
                ->where('proforma_uuid', 'like', $awalan . '%')
                ->max(DB::raw('CAST(RIGHT(proforma_uuid, 5) AS UNSIGNED)')),
        );

        // Lewati nomor yang sudah dipakai — melindungi dari dua permintaan yang
        // membaca urutan tertinggi yang sama sebelum salah satunya tersimpan.
        for ($i = $urutanTertinggi + 1; $i <= $urutanTertinggi + 100; $i++) {
            $kandidat = $awalan . str_pad((string) $i, 5, '0', STR_PAD_LEFT);

            if (! static::nomorTerpakai($kandidat)) {
                return $kandidat;
            }
        }

        // Sangat jarang: kembalikan nomor berikutnya dan biarkan unique constraint
        // yang memutuskan, daripada mengembalikan nomor yang pasti bentrok.
        return $awalan . str_pad((string) ($urutanTertinggi + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Nomor terkecil dari proforma bulan ini yang dihapus dan belum dipakai lagi.
     */
    private static function nomorProformaDilepas(string $awalan): ?string
    {
        return static::onlyTrashed()
            ->where('uuid', 'like', $awalan . '%' . self::TANDA_BATAL . '%')
            ->pluck('uuid')
            ->map(fn (string $uuid) => Str::before($uuid, self::TANDA_BATAL))
            ->unique()
            ->sort()
            ->first(fn (string $nomor) => ! static::nomorTerpakai($nomor));
    }

    /**
     * Dipegang invoice mana pun, termasuk yang dihapus dan nomor PI dari
     * proforma yang sudah menjadi invoice.
     */
    public static function nomorTerpakai(string $nomor): bool
    {
        return static::withTrashed()
            ->where(fn ($query) => $query->where('uuid', $nomor)->orWhere('proforma_uuid', $nomor))
            ->exists();
    }
}