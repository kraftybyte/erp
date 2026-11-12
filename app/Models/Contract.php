<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    protected $fillable = [
        'deal_id',
        'contract_number',
        'document_title',
        'opening_text',
        'articles',
        'closing_text',
        'header_image',
        'footer_image',
        'type',
        'content',
        'effective_date',
        'expiration_date',
        'file_path',
        'duration_months',
        'total_value',
        'down_payment_percent',
        'max_revisions',
        'platform',
        'content_count',
        'scope_of_work',
        'client_name_full',
        'client_address',
        'client_phone',
        'client_email',
        'client_signatory_name',
        'client_signatory_title',
        'agency_signatory_name',
        'agency_signatory_title',
        // Payment type
        'payment_type',
        'installment_months',
        // Meta/Additional
        'project_description',
        'additional_terms',
        'force_majeure_extended',
        'termination_notice_days',
        'penalty_clause',
        // Lampiran customization
        'lampiran_title',
        'lampiran_description',
        'table_a_title',
        'table_b_title',
        'table_b_description',
        'table_a_columns',
        'table_a_total_label',
        'table_b_columns',
        'layout',
        'signing_city',
        'client_legal_basis',
        'payment_due_day',
        'has_third_party',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'expiration_date' => 'date',
        'total_value' => 'decimal:2',
        'down_payment_percent' => 'decimal:2',
        'installment_months' => 'integer',
        'content_count' => 'integer',
        'max_revisions' => 'integer',
        'termination_notice_days' => 'integer',
        'has_third_party' => 'boolean',
        'articles' => 'array',
        'table_a_columns' => 'array',
        'table_b_columns' => 'array',
        'layout' => 'array',
        'payment_due_day' => 'integer',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class)->orderBy('sort_order');
    }

    public function feeItems(): HasMany
    {
        return $this->hasMany(ContractItem::class)->where('type', 'fee')->orderBy('sort_order');
    }

    public function thirdPartyItems(): HasMany
    {
        return $this->hasMany(ContractItem::class)->where('type', 'third_party')->orderBy('sort_order');
    }

    public function sowItems(): HasMany
    {
        return $this->hasMany(ContractItem::class)->where('type', 'sow')->orderBy('sort_order');
    }

    /**
     * SOW Categories - Parent level items with children loaded
     */
    public function sowCategories(): HasMany
    {
        return $this->hasMany(ContractItem::class)
            ->where('type', 'sow')
            ->where('is_category_header', true)
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order');
    }

    /** Penanda di format nomor kontrak (Settings → Contract Template), dengan keterangannya. */
    public const NUMBER_TOKENS = [
        '{no}'           => 'nomor urut 3 digit (001)',
        '{kode_agensi}'  => 'kode perusahaan kita (PT Sinergi Citra Nusantara → SCN)',
        '{kode_klien}'   => 'kode client (singkatan dalam kurung, atau huruf awal nama)',
        '{bulan}'        => 'bulan angka (11)',
        '{bulan_romawi}' => 'bulan romawi (XI)',
        '{tahun}'        => 'tahun (2026)',
        '{tahun2}'       => 'tahun 2 digit (26)',
    ];

    public const DEFAULT_NUMBER_FORMAT = '{no}/SPK/{bulan}{tahun2}';

    private const ROMAN_MONTHS = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /**
     * Nomor kontrak berikutnya menurut format di Settings (bawaan 001/SPK/1126).
     * Nomor urut = angka di depan nomor kontrak terakhir + 1.
     */
    public static function generateContractNumber(?string $clientName = null, ?\DateTimeInterface $date = null, ?string $format = null): string
    {
        $settings = app(\App\Settings\GeneralSettings::class);
        $format   = filled($format) ? $format : (filled($settings->contract_number_format) ? $settings->contract_number_format : self::DEFAULT_NUMBER_FORMAT);
        $date     = \Illuminate\Support\Carbon::instance($date ?? now());

        $last = static::whereNotNull('contract_number')->orderByDesc('id')->value('contract_number');
        $next = $last && preg_match('/^\s*(\d+)/', $last, $matches) ? (int) $matches[1] + 1 : 1;

        return strtr($format, [
            '{no}'           => str_pad((string) $next, 3, '0', STR_PAD_LEFT),
            '{kode_agensi}'  => self::companyCode($settings->inv_company_name),
            '{kode_klien}'   => self::companyCode($clientName),
            '{bulan}'        => $date->format('m'),
            '{bulan_romawi}' => self::ROMAN_MONTHS[(int) $date->format('n') - 1],
            '{tahun}'        => $date->format('Y'),
            '{tahun2}'       => $date->format('y'),
        ]);
    }

    /**
     * Kode perusahaan untuk nomor kontrak: singkatan di dalam kurung bila ada
     * ("PT Citra Meta Data (CMD)" → CMD), selain itu huruf awal tiap kata
     * tanpa bentuk usaha ("PT Sinergi Citra Nusantara" → SCN).
     */
    public static function companyCode(?string $name): string
    {
        $name = trim((string) $name);

        if (preg_match('/\(([A-Za-z0-9]{2,10})\)/', $name, $matches)) {
            return strtoupper($matches[1]);
        }

        $words = preg_split('/[\s.,]+/', preg_replace('/\(.*?\)/', '', $name), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn (string $word) => ! in_array(strtoupper($word), ['PT', 'CV', 'UD', 'TBK', 'PERSERO', 'LTD', 'LLC', 'INC'], true));

        return strtoupper(implode('', array_map(fn (string $word) => mb_substr($word, 0, 1), $words)));
    }
}