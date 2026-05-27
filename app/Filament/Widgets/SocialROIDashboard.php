<?php

namespace App\Filament\Widgets;

use App\Models\SocialAccount;
use App\Models\Deal;
use App\Models\ConnectedAccount;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class SocialROIDashboard extends BaseWidget
{
    protected ?string $heading = 'Social Media ROI';
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $instagramAccounts = SocialAccount::where('platform', 'instagram')
            ->where('is_active', true)
            ->get();

        $totalFollowers = $instagramAccounts->sum('followers_count');
        $totalMedia = $instagramAccounts->sum('media_count');

        $facebookAccounts = SocialAccount::where('platform', 'facebook')
            ->where('is_active', true)
            ->get();

        $fbTotalFollowers = $facebookAccounts->sum('fan_count');

        $connectedAccounts = ConnectedAccount::where('is_active', true)->count();

        $socialLeads = Deal::where('source', 'Social Media')->count();
        $socialDeals = Deal::where('source', 'Social Media')
            ->whereNotIn('status', ['Hold', 'Kalah'])
            ->count();

        return [
            Card::make('Instagram Followers', number_format($totalFollowers))
                ->description($instagramAccounts->count() . ' accounts')
                ->icon('heroicon-o-user-group'),

            Card::make('Facebook Fans', number_format($fbTotalFollowers))
                ->description($facebookAccounts->count() . ' pages')
                ->icon('heroicon-o-hand-raised'),

            Card::make('Connected Accounts', $connectedAccounts)
                ->description('Active social sync')
                ->icon('heroicon-o-link'),

            Card::make('Social Deals', $socialDeals)
                ->description($socialLeads . ' total leads')
                ->descriptionColor($socialDeals > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-currency-dollar'),

            Card::make('Media Posted', number_format($totalMedia))
                ->description('Total posts across IG')
                ->icon('heroicon-o-photo'),
        ];
    }
}
