<?php

namespace App\Services\Telegram;

use App\Models\Advance;
use App\Models\User;
use App\Services\RealisasiService;
use Illuminate\Support\Collection;

/**
 * Advance milik user yang masih bisa direalisasi lewat bot: sudah dibayarkan
 * atau sedang direalisasi. Hanya advance milik pengirim sendiri yang pernah
 * ditawarkan.
 */
class OpenAdvances
{
    private const OPEN_STATUSES = [Advance::STATUS_DIBAYARKAN, Advance::STATUS_REALIZED_USER];

    public function __construct(private RealisasiService $realisasi) {}

    /**
     * @return Collection<int, Advance>
     */
    public function forUser(User $user): Collection
    {
        return Advance::with('project.deal')
            ->where('user_id', $user->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->latest('id')
            ->get();
    }

    public function find(User $user, ?int $advanceId): ?Advance
    {
        return $advanceId
            ? $this->forUser($user)->firstWhere('id', $advanceId)
            : null;
    }

    /**
     * Pilih advance untuk realisasi: yang project-nya sama dengan pesan, atau
     * satu-satunya advance terbuka milik user.
     *
     * @return array{advance_id: ?int, candidates: array<int, int>}
     */
    public function match(User $user, ?int $projectId): array
    {
        $open = $this->forUser($user);

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

        return ['advance_id' => null, 'candidates' => $open->pluck('id')->take(8)->values()->all()];
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
