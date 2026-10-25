<?php

namespace App\Services\Telegram;

use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mencari project yang disebut di pesan lewat nama klien atau nama project:
 * "grab ke kantor klien nestory" → PT Nestory Indonesia | Video Profile.
 *
 * Toleran terhadap huruf besar-kecil, nama yang disingkat ("nestory" untuk
 * "nestoryid") dan salah ketik satu huruf ("nestori"). Semua project ikut
 * dicari, bukan hanya yang terbaru.
 */
class ProjectMatcher
{
    // Kata yang umum di nama perusahaan/project sehingga tidak membedakan klien.
    private const GENERIC = [
        'pt', 'cv', 'ud', 'tbk', 'persero', 'indonesia', 'indo', 'group', 'grup', 'corp', 'company',
        'official', 'store', 'shop', 'toko', 'studio', 'agency', 'creative', 'kreatif', 'digital',
        'media', 'sosmed', 'social', 'marketing', 'project', 'proyek', 'video', 'foto', 'photo',
        'konten', 'content', 'profile', 'profil', 'campaign', 'event', 'launching', 'brand',
        'branding', 'design', 'desain', 'website', 'web', 'ads', 'iklan', 'shooting', 'syuting',
        'produksi', 'production', 'jasa', 'layanan', 'service', 'services', 'paket', 'bulanan',
        'tahunan', 'dan', 'and', 'the', 'of', 'untuk', 'kantor', 'klien', 'client', 'baru', 'new',
        'expense', 'advance',
    ];

    private const MAX_CANDIDATES = 6;

    /**
     * @return array{project_id: ?int, candidates: array<int, int>}
     */
    public function match(string $text): array
    {
        $words = $this->tokens($text);

        if (! $words) {
            return ['project_id' => null, 'candidates' => []];
        }

        $scored = Project::with('deal')->get()
            ->map(fn (Project $project) => [
                'id'     => $project->id,
                'score'  => $this->score($project, $words),
                'active' => ! in_array(Str::lower((string) $project->status), ['completed'], true),
            ])
            ->filter(fn ($row) => $row['score'] >= 2);

        if ($scored->isEmpty()) {
            return ['project_id' => null, 'candidates' => []];
        }

        $best = $scored->where('score', $scored->max('score'));

        // Bila beberapa project sama kuat, yang masih berjalan lebih mungkin
        // dimaksud; tetap ditanyakan bila yang berjalan pun lebih dari satu.
        $activeBest = $best->where('active', true);
        if ($best->count() > 1 && $activeBest->count() === 1) {
            $best = $activeBest;
        }

        if ($best->count() === 1) {
            return ['project_id' => $best->first()['id'], 'candidates' => []];
        }

        return [
            'project_id' => null,
            'candidates' => $best->sortByDesc('active')->pluck('id')->take(self::MAX_CANDIDATES)->values()->all(),
        ];
    }

    /**
     * Jumlah skor kata pembeda di nama project yang muncul di pesan:
     * sama persis 3, awalan (min. 4 huruf) 2, salah ketik satu huruf 2
     * untuk kata panjang atau 1 untuk kata pendek.
     */
    private function score(Project $project, array $words): int
    {
        $names = array_filter([
            $project->nama_perusahaan,
            $project->deal?->nama_perusahaan,
            $project->nama_project,
        ]);

        $tokens = collect($names)
            ->flatMap(fn ($name) => $this->tokens($name))
            ->reject(fn ($token) => in_array($token, self::GENERIC, true))
            ->unique();

        $score = $tokens->sum(fn ($token) => collect($words)->max(fn ($word) => $this->similarity($token, $word)));

        // Nama yang ditulis rapat: "nes tory" ditulis "nestory".
        foreach ($names as $name) {
            $withoutPrefix = preg_replace('/^(pt|cv|ud)\.?\s+/i', '', trim($name));
            $compact = preg_replace('/[^a-z0-9]/', '', Str::lower($withoutPrefix));
            if (strlen($compact) >= 5 && in_array($compact, $words, true)) {
                $score = max($score, 3);
            }
        }

        return (int) $score;
    }

    private function similarity(string $token, string $word): int
    {
        if ($token === $word) {
            return 3;
        }

        $shorter = min(strlen($token), strlen($word));

        // Minimal 5 huruf: dengan 4, kata sehari-hari seperti "beli" ikut
        // cocok dengan klien bernama "Belinda".
        if ($shorter >= 5 && (str_starts_with($token, $word) || str_starts_with($word, $token))) {
            return 2;
        }

        if ($shorter >= 5 && levenshtein($token, $word) === 1) {
            return $shorter >= 6 ? 2 : 1;
        }

        return 0;
    }

    /**
     * @return array<int, string>
     */
    private function tokens(?string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower((string) $text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn ($word) => mb_strlen($word) >= 3 && ! ctype_digit($word)));
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Project>
     */
    public static function projects(array $ids): Collection
    {
        return Project::with('deal')->whereKey($ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids))->values();
    }
}
