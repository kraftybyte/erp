<?php

namespace App\Services\Telegram;

use App\Helpers\ClientLabel;
use App\Models\Advance;
use App\Models\User;
use App\Services\RealisasiService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Advance yang masih bisa direalisasi lewat bot: sudah dibayarkan atau sedang
 * direalisasi. Karyawan hanya melihat advance miliknya sendiri; Finance,
 * Director, dan super_admin melihat semua, sama seperti di halaman Realisasi ERP.
 */
class OpenAdvances
{
    private const OPEN_STATUSES = [Advance::STATUS_DIBAYARKAN, Advance::STATUS_REALIZED_USER];

    /** Boleh merealisasi advance milik siapa pun. */
    public const ALL_ROLES = ['Finance', 'Director', 'super_admin'];

    public function __construct(private RealisasiService $realisasi) {}

    /**
     * Advance terbuka milik user sendiri: untuk /status dan cek klaim ganda
     * (expense untuk project yang advance-nya masih terbuka).
     *
     * @return Collection<int, Advance>
     */
    public function forUser(User $user): Collection
    {
        return Advance::with('project.deal', 'itemAdvance')
            ->where('user_id', $user->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->latest('id')
            ->get();
    }

    public static function seesAll(User $user): bool
    {
        return $user->hasRole(self::ALL_ROLES);
    }

    /**
     * Advance yang boleh direalisasi user ini: miliknya sendiri, atau semua
     * untuk Finance/Director/super_admin (milik sendiri tetap di depan).
     *
     * @return Collection<int, Advance>
     */
    public function realizableBy(User $user): Collection
    {
        if (! self::seesAll($user)) {
            return $this->forUser($user);
        }

        return Advance::with('project.deal', 'user', 'itemAdvance')
            ->whereIn('status', self::OPEN_STATUSES)
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
    public function match(User $user, ?int $projectId): array
    {
        $open = $this->realizableBy($user);

        if ($projectId) {
            $sameProject = $open->where('project_id', $projectId);

            if ($sameProject->count() === 1) {
                return ['advance_id' => $sameProject->first()->id, 'candidates' => []];
            }

            if ($sameProject->isNotEmpty()) {
                return ['advance_id' => null, 'candidates' => $sameProject->pluck('id')->values()->all()];
            }
        }

        if ($open->count() === 1) {
            return ['advance_id' => $open->first()->id, 'candidates' => []];
        }

        return ['advance_id' => null, 'candidates' => []];
    }

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

    public function remaining(Advance $advance): float
    {
        return (float) $advance->paid - $this->realized($advance);
    }
}
