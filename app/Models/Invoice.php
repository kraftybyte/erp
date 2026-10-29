<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use TomatoPHP\FilamentInvoices\Models\Invoice as BaseInvoice;

/**
 * Extended Invoice model with PaymentAccount support
 *
 * @property int|null $payment_account_id
 */
class Invoice extends BaseInvoice
{
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
     * Nominal yang ditagihkan lewat invoice ini.
     *
     * Untuk invoice DP, yang ditagih adalah DP-nya — persentase dari harga penuh —
     * bukan sisanya. grand_total menyimpan harga penuh pekerjaan.
     */
    public function amountDue(): float
    {
        $persen = $this->dpPercentage();

        return $persen !== null
            ? (float) $this->grand_total * ($persen / 100)
            : (float) $this->grand_total;
    }

    /**
     * Generate UUID based on type and reset per month
     * Format: #SC/0132607/XXXXX atau #PI/0132607/XXXXX
     *
     * Nomor urut diambil langsung di database (MAX atas 5 digit terakhir), bukan
     * dengan menarik seluruh invoice bulan berjalan ke memory. Nomor yang sudah
     * terpakai juga dilewati, sehingga pemanggilan bersamaan oleh dua pengguna
     * tidak menghasilkan nomor kembar yang ditolak unique constraint.
     */
    public static function generateUuid(string $type = 'sale'): string
    {
        $prefix = match ($type) {
            'estimate' => 'PI',
            'sale' => 'SC',
            default => 'SC',
        };

        // Format: 013YYMM (mis. 0132607 untuk Juli 2026)
        $awalan = '#' . $prefix . '/013' . now()->format('ym') . '/';

        // Urutan tertinggi bulan ini, dihitung di sisi database.
        $urutanTertinggi = (int) static::withTrashed()
            ->where('uuid', 'like', $awalan . '%')
            ->max(DB::raw('CAST(RIGHT(uuid, 5) AS UNSIGNED)'));

        // Lewati nomor yang sudah dipakai — melindungi dari dua permintaan yang
        // membaca urutan tertinggi yang sama sebelum salah satunya tersimpan.
        for ($i = $urutanTertinggi + 1; $i <= $urutanTertinggi + 100; $i++) {
            $kandidat = $awalan . str_pad((string) $i, 5, '0', STR_PAD_LEFT);

            if (! static::withTrashed()->where('uuid', $kandidat)->exists()) {
                return $kandidat;
            }
        }

        // Sangat jarang: kembalikan nomor berikutnya dan biarkan unique constraint
        // yang memutuskan, daripada mengembalikan nomor yang pasti bentrok.
        return $awalan . str_pad((string) ($urutanTertinggi + 1), 5, '0', STR_PAD_LEFT);
    }
}