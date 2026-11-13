<?php

namespace App\Services\Telegram;

use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Services\AdvanceParallel;
use App\Models\User;
use App\Services\RealisasiService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Advance yang masih bisa direalisasi lewat bot: sudah dibayarkan atau sedang
 * direalisasi. Karyawan hanya melihat advance miliknya sendiri; pemegang izin
 * restore_advance (pengelola advance, izin tombol setujui/bayar di ERP) melihat semua.
 */
class OpenAdvances
{
    private const OPEN_STATUSES = [Advance::STATUS_DIBAYARKAN, Advance::STATUS_REALIZED_USER];

    public function __construct(private RealisasiService $realisasi) {}

    /**
     * Advance terbuka milik user sendiri: untuk /status dan cek klaim ganda
     * (expense untuk project yang advance-nya masih terbuka).
     *
     * @return Collection<int, Advance>
     */
    public function forUser(User $user): Collection
    {
        return AdvanceParallel::whereRealizable(Advance::with('project.deal', 'itemAdvance'), self::OPEN_STATUSES)
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();
    }

    public static function seesAll(User $user): bool
    {
        return $user->can('restore_advance');
    }

    /**
     * Advance yang boleh direalisasi user ini: miliknya sendiri, atau semua
     * untuk pengelola advance (milik sendiri tetap di depan).
     *
     * @return Collection<int, Advance>
     */
    public function realizableBy(User $user): Collection
    {
        if (! self::seesAll($user)) {
            return $this->forUser($user);
        }

        return AdvanceParallel::whereRealizable(Advance::with('project.deal', 'user', 'itemAdvance'), self::OPEN_STATUSES)
            ->orderByRaw('user_id = ? desc', [$user->id])
            ->latest('id')
            ->get();
    }

    public function find(User $user, ?int $advanceId): ?Advance
    {
        return $advanceId
            ? $this->realizableBy($user)->firstWhere('id', $advanceId)
            : null;
    }

    /**
     * Advance dengan ID $query ("12", "#12", "id 12"), atau yang pengaju,
     * keterangan, klien, atau project-nya memuat $query.
     *
     * @return Collection<int, Advance>
     */
    public function search(User $user, string $query): Collection
    {
        if (preg_match('/^(?:id\s*)?#?\s*(\d+)$/iu', trim($query), $id)) {
            return $this->realizableBy($user)->where('id', (int) $id[1])->values();
        }

        $words = array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))));

        return $this->realizableBy($user)->filter(function (Advance $advance) use ($words) {
            $haystack = mb_strtolower(implode(' ', [
                $advance->keterangan_advance,
                $advance->user?->firstname,
                $advance->user?->lastname,
                $advance->user?->username,
                ClientLabel::project($advance->project),
            ]));

            return $words && collect($words)->every(fn (string $word) => str_contains($haystack, $word));
        })->values();
    }

    /**
     * Pilih advance untuk realisasi: yang project-nya sama dengan pesan, atau
     * satu-satunya advance terbuka milik user. Tanpa yang cocok, candidates
     * kosong dan bot menampilkan semua advance terbuka per halaman.
     *
     * @return array{advance_id: ?int, candidates: array<int, int>}
     */
    public function match(User $user, ?int $projectId, ?string $text = null): array
    {
        $open = $this->realizableBy($user);
        $pool = $projectId ? $open->where('project_id', $projectId) : collect();

        if ($pool->count() === 1) {
            return ['advance_id' => $pool->first()->id, 'candidates' => []];
        }

        if ($pool->isNotEmpty()) {
            // Beberapa advance di project ini: keterangan di pesan yang menentukan.
            return $this->byText($pool, $text) ?? ['advance_id' => null, 'candidates' => $pool->pluck('id')->values()->all()];
        }

        if ($open->count() === 1) {
            return ['advance_id' => $open->first()->id, 'candidates' => []];
        }

        // "reali lighting", "realisasi budi": keterangan, barang, atau pengaju advance-nya.
        return $this->byText($open, $text) ?? ['advance_id' => null, 'candidates' => []];
    }

    /**
     * Advance yang keterangan, barang, pengaju, atau klien/project-nya paling
     * cocok dengan $text (singkatan dan salah ketik seperti ProjectMatcher).
     * Satu yang paling cocok dipilih; beberapa yang sama cocok jadi pilihan.
     *
     * @param  Collection<int, Advance>  $advances
     * @return array{advance_id: ?int, candidates: array<int, int>}|null
     */
    private function byText(Collection $advances, ?string $text): ?array
    {
        $words = self::words((string) $text);

        if (! $words) {
            return null;
        }

        $scored = $advances->map(function (Advance $advance) use ($words) {
            $tokens = collect(self::words(implode(' ', [
                $advance->keterangan_advance,
                $advance->user?->firstname,
                $advance->user?->lastname,
                ClientLabel::project($advance->project),
                $advance->itemAdvance?->pluck('name')->implode(' '),
            ])))->unique();

            return ['id' => $advance->id, 'score' => $tokens->sum(fn ($token) => collect($words)->max(fn ($word) => ProjectMatcher::similarity($token, $word)))];
        })->where('score', '>=', 2);

        if ($scored->isEmpty()) {
            return null;
        }

        $best = $scored->where('score', $scored->max('score'));

        return $best->count() === 1
            ? ['advance_id' => $best->first()['id'], 'candidates' => []]
            : ['advance_id' => null, 'candidates' => $best->pluck('id')->take(6)->values()->all()];
    }

    /** Kata pembeda (3+ huruf), tanpa kata jenis pengajuan dan kata sambung. */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn ($word) => mb_strlen($word) >= 3 && ! ctype_digit($word) && ! in_array($word, self::STOP_WORDS, true)));
    }

    private const STOP_WORDS = [
        'advance', 'adv', 'realisasi', 'expense', 'kasbon', 'untuk', 'buat', 'dan', 'yang', 'dengan', 'dari', 'pakai',
        'pake', 'ini', 'itu', 'sudah', 'udah', 'mau', 'tolong', 'dana', 'uang', 'project', 'proyek', 'klien',
    ];

    /**
     * Alasan realisasi advance ini belum bisa ditambah (kelebihan yang belum
     * disetujui Finance), dengan aturan yang sama seperti saat disimpan; null bila bisa.
     */
    public function blockedReason(Advance $advance): ?string
    {
        try {
            $this->realisasi->validateAdvanceCanRealisasi($advance);

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    public function realized(Advance $advance): float
    {
        return $this->realisasi->getTotalRealisasi($advance);
    }

    /**
     * Sisa yang masih bisa direalisasi: dari yang dicairkan, atau untuk bayar
     * paralel dari yang disetujui (pembayarannya menyusul).
     */
    public function remaining(Advance $advance): float
    {
        return $advance->realisasiBasis() - $this->realized($advance);
    }
}
