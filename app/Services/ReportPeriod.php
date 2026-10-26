<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rentang tanggal laporan keuangan (inklusif), atau seluruh waktu.
 */
final class ReportPeriod
{
    public const PRESETS = [
        'bulan-ini'  => 'Bulan ini',
        'bulan-lalu' => 'Bulan lalu',
        'tahun-ini'  => 'Tahun ini',
        'tahun-lalu' => 'Tahun lalu',
        'semua'      => 'Semua waktu',
    ];

    private const MONTHS = [
        'januari' => 1, 'jan' => 1, 'februari' => 2, 'feb' => 2, 'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'mei' => 5, 'may' => 5, 'juni' => 6, 'jun' => 6,
        'juli' => 7, 'jul' => 7, 'agustus' => 8, 'agu' => 8, 'agt' => 8, 'ags' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'oktober' => 10, 'okt' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11, 'desember' => 12, 'des' => 12, 'dec' => 12,
    ];

    private function __construct(public readonly ?CarbonImmutable $from, public readonly ?CarbonImmutable $to) {}

    public static function all(): self
    {
        return new self(null, null);
    }

    public static function between(CarbonInterface $from, CarbonInterface $to): self
    {
        [$from, $to] = $from->lte($to) ? [$from, $to] : [$to, $from];

        return new self(CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->startOfDay());
    }

    public static function preset(string $key, ?CarbonInterface $today = null): ?self
    {
        $today = CarbonImmutable::parse($today ?? now());

        return match ($key) {
            'bulan-ini'  => self::between($today->startOfMonth(), $today->endOfMonth()),
            'bulan-lalu' => self::between($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()),
            'tahun-ini'  => self::between($today->startOfYear(), $today->endOfYear()),
            'tahun-lalu' => self::between($today->subYear()->startOfYear(), $today->subYear()->endOfYear()),
            'semua'      => self::all(),
            default      => null,
        };
    }

    /**
     * Rentang dari teks: "1/10/2026 - 31/10/2026", "1 okt - 15 okt", "01-10-26 sd 15-10-26",
     * "2026-10-01 2026-10-31", "oktober 2026", "2026", "bulan lalu". Satu tanggal = hari itu.
     * Tahun yang tidak ditulis mengikuti tanggal lainnya, atau tahun ini.
     */
    public static function parse(string $text, ?CarbonInterface $today = null): ?self
    {
        $today = CarbonImmutable::parse($today ?? now());
        $text  = mb_strtolower(trim($text));

        if ($key = array_search($text, array_map('mb_strtolower', self::PRESETS), true)) {
            return self::preset($key, $today);
        }

        $months = implode('|', array_keys(self::MONTHS));

        preg_match_all(
            "/(?<iy>\d{4})-(?<im>\d{1,2})-(?<id>\d{1,2})"
            // Pemisah dalam satu tanggal harus sama: "1/10-31/10" dua tanggal, bukan tahun 2031.
            . "|(?<nd>\d{1,2})(?<sep>[\/.-])(?<nm>\d{1,2})(?:\k<sep>(?<ny>\d{2,4}))?"
            . "|(?<wd>\d{1,2})\s*(?<wm>{$months})\b\.?(?:\s*(?<wy>\d{4}))?/u",
            $text,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        );

        $dates = array_map(fn (array $m) => match (true) {
            $m['iy'] !== null => [(int) $m['iy'], (int) $m['im'], (int) $m['id']],
            $m['nd'] !== null => [self::year($m['ny']), (int) $m['nm'], (int) $m['nd']],
            default           => [self::year($m['wy']), self::MONTHS[$m['wm']], (int) $m['wd']],
        }, $matches);

        if ($dates) {
            $a = $dates[0];
            $b = $dates[1] ?? $a;

            $a[0] ??= $b[0] ?? $today->year;
            $b[0] ??= $a[0];

            if (! checkdate($a[1], $a[2], $a[0]) || ! checkdate($b[1], $b[2], $b[0])) {
                return null;
            }

            $first = CarbonImmutable::create(...$a);
            $last  = CarbonImmutable::create(...$b);

            // "15 des 2025 - 10 jan": tahun yang tidak ditulis melewati pergantian tahun.
            if ($last->lt($first) && isset($dates[1])) {
                if ($dates[1][0] === null) {
                    $last = $last->addYear();
                } elseif ($dates[0][0] === null) {
                    $first = $first->subYear();
                }
            }

            return self::between($first, $last);
        }

        // Nama bulan saja: sebulan penuh. Tahun saja: setahun penuh.
        if (preg_match("/\b(?<m>{$months})\b(?:\s*(?<y>\d{4}))?/u", $text, $m)) {
            $month = CarbonImmutable::create((int) ($m['y'] ?? $today->year), self::MONTHS[$m['m']], 1);

            return self::between($month, $month->endOfMonth());
        }

        if (preg_match('/^\d{4}$/', $text)) {
            $year = CarbonImmutable::create((int) $text, 1, 1);

            return self::between($year, $year->endOfYear());
        }

        return null;
    }

    public static function fromArray(?array $data): self
    {
        return isset($data['from'], $data['to'])
            ? self::between(CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']))
            : self::all();
    }

    /**
     * @return array{from: ?string, to: ?string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from?->toDateString(), 'to' => $this->to?->toDateString()];
    }

    public function isAll(): bool
    {
        return $this->from === null;
    }

    public function label(): string
    {
        if ($this->isAll()) {
            return 'Semua waktu';
        }

        $from = $this->from->locale('id');
        $to   = $this->to->locale('id');

        return match (true) {
            $from->eq($to)                                                        => $from->translatedFormat('j M Y'),
            $from->eq($from->startOfYear()) && $to->eq($from->endOfYear()->startOfDay()) => (string) $from->year,
            $from->eq($from->startOfMonth()) && $to->eq($from->endOfMonth()->startOfDay()) => $from->translatedFormat('F Y'),
            $from->year === $to->year                                             => $from->translatedFormat('j M') . ' – ' . $to->translatedFormat('j M Y'),
            default                                                               => $from->translatedFormat('j M Y') . ' – ' . $to->translatedFormat('j M Y'),
        };
    }

    public function apply(Builder $query, string $column): Builder
    {
        return $this->isAll()
            ? $query
            : $query->whereDate($column, '>=', $this->from->toDateString())->whereDate($column, '<=', $this->to->toDateString());
    }

    public function contains(mixed $date): bool
    {
        if ($this->isAll()) {
            return true;
        }

        if (blank($date)) {
            return false;
        }

        $day = CarbonImmutable::parse($date)->startOfDay();

        return $day->gte($this->from) && $day->lte($this->to);
    }

    private static function year(?string $year): ?int
    {
        return match (true) {
            $year === null      => null,
            strlen($year) === 2 => 2000 + (int) $year,
            default             => (int) $year,
        };
    }
}
