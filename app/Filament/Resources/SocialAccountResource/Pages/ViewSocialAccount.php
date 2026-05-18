<?php

namespace App\Filament\Resources\SocialAccountResource\Pages;

use App\Filament\Resources\SocialAccountResource;
use App\Models\SocialAccountInsight;
use App\Models\SocialPost;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions;
use Illuminate\Support\Carbon;

class ViewSocialAccount extends ViewRecord
{
    protected static string $resource = SocialAccountResource::class;
    protected static string $view     = 'filament.resources.social-account.view-social-account';

    // Pakai Livewire property supaya wire:set() jalan
    public string $fromDate = '';
    public string $toDate   = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Default: 30 hari terakhir
        $this->fromDate = now()->subDays(180)->toDateString();
        $this->toDate   = now()->toDateString();
    }

    // Dipanggil otomatis saat $wire.set('fromDate', ...) dari blade
    public function updatedFromDate(): void {}
    public function updatedToDate(): void {}

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function getViewData(): array
    {
        $account = $this->record;

        // Selalu copy() agar tidak mutate
        $from = Carbon::parse($this->fromDate)->copy()->startOfDay();
        $to   = Carbon::parse($this->toDate)->copy()->endOfDay();

        $fromStr = $from->toDateString();
        $toStr   = $to->toDateString();

        // ── Insights harian ──────────────────────────────────────────
        $insights = SocialAccountInsight::where('social_account_id', $account->id)
            ->whereBetween('date', [$fromStr, $toStr])
            ->orderBy('date')
            ->get();

        // ── Posts dalam range ────────────────────────────────────────
        $posts = SocialPost::where('social_account_id', $account->id)
            ->whereBetween('posted_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->with('metrics')
            ->orderByDesc('posted_at')
            ->get();

        // ── Semua posts dengan metrics (top posts & avg engagement) ──
        $allPostsWithMetrics = SocialPost::where('social_account_id', $account->id)
            ->with('metrics')
            ->whereHas('metrics')
            ->orderByDesc('posted_at')
            ->get();

        // ── Followers growth ─────────────────────────────────────────
        $followersStart  = $insights->first()?->follower_count ?? $account->followers_count ?? 0;
        $followersEnd    = $insights->last()?->follower_count  ?? $account->followers_count ?? 0;
        $followersGrowth = $followersEnd - $followersStart;

        // ── Total metrics dari posts dalam range ─────────────────────
        $totalLikes       = $posts->sum(fn ($p) => $p->metrics?->likes    ?? 0);
        $totalComments    = $posts->sum(fn ($p) => $p->metrics?->comments ?? 0);
        $totalShares      = $posts->sum(fn ($p) => $p->metrics?->shares   ?? 0);
        $totalSaved       = $posts->sum(fn ($p) => $p->metrics?->saved    ?? 0);

        // Reach & impressions dari insights (bukan posts)
        $totalReach       = $insights->sum('reach');
        $totalImpressions = $insights->sum('impressions');

        // ── Avg engagement dari semua posts ──────────────────────────
        $avgEngagement = $allPostsWithMetrics->isNotEmpty()
            ? round($allPostsWithMetrics->avg(fn ($p) => $p->metrics?->engagement_rate ?? 0), 2)
            : 0;

        // ── Post terbaru (6 post) ────────────────────────────────────
        $latestPosts = SocialPost::where('social_account_id', $account->id)
            ->with('metrics')
            ->orderByDesc('posted_at')
            ->take(6)
            ->get();

        // ── Alpine.js top posts data ─────────────────────────────────
        $allPostsJson = $allPostsWithMetrics->map(fn ($p) => [
            'id'              => $p->id,
            'caption'         => $p->caption ? mb_substr(strip_tags($p->caption), 0, 60) : null,
            'thumbnail_url'   => $p->thumbnail_url,
            'permalink'       => $p->permalink,
            'posted_at'       => $p->posted_at?->format('d M Y') ?? '-',
            'likes'           => $p->metrics?->likes           ?? 0,
            'comments'        => $p->metrics?->comments        ?? 0,
            'saved'           => $p->metrics?->saved           ?? 0,
            'reach'           => $p->metrics?->reach           ?? 0,
            'engagement_rate' => $p->metrics?->engagement_rate ?? 0,
        ])->values()->toArray();

        // ── Chart: followers growth ──────────────────────────────────
        $followersChart = $insights->map(fn ($i) => [
            'date'  => Carbon::parse($i->date)->format('d M'),
            'value' => $i->follower_count ?? 0,
        ])->values()->toArray();

        // ── Chart: reach & impressions ───────────────────────────────
        $reachChart = $insights->map(fn ($i) => [
            'date'        => Carbon::parse($i->date)->format('d M'),
            'reach'       => $i->reach ?? 0,
            'impressions' => $i->impressions ?? 0,
        ])->values()->toArray();

        return [
            'account'          => $account,
            'from'             => Carbon::parse($fromStr),
            'to'               => Carbon::parse($toStr),
            'insights'         => $insights,
            'posts'            => $posts,
            'followersGrowth'  => $followersGrowth,
            'followersStart'   => $followersStart,
            'followersEnd'     => $followersEnd,
            'totalLikes'       => $totalLikes,
            'totalComments'    => $totalComments,
            'totalShares'      => $totalShares,
            'totalSaved'       => $totalSaved,
            'totalReach'       => $totalReach,
            'totalImpressions' => $totalImpressions,
            'avgEngagement'    => $avgEngagement,
            'latestPosts'      => $latestPosts,
            'allPostsJson'     => $allPostsJson,
            'followersChart'   => $followersChart,
            'reachChart'       => $reachChart,
            'calendarData'     => [], // dihapus sesuai permintaan
            'totalPosts'       => $posts->count(),
        ];
    }
}
