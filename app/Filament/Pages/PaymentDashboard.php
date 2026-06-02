<?php

namespace App\Filament\Pages;

use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\Expense;
use App\Models\Advance;
use App\Settings\GeneralSettings;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentDashboard extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';
    protected static ?string $navigationLabel = 'Payment Dashboard';
    protected static ?string $title = 'Payment Dashboard';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationGroup = 'Finance';

    protected static string $view = 'filament.pages.payment-dashboard';

    public $totalBalance;
    public $totalInThisMonth;
    public $totalOutThisMonth;
    public $pendingPayments;
    public $accounts;
    public $recentTransactions;
    public $monthlyStats;

    // Theme colors from settings
    public $primaryColor;
    public $primaryColorDark;
    public $primaryGradientFrom;
    public $primaryGradientTo;
    public $successColor;
    public $dangerColor;
    public $warningColor;
    public $infoColor;

    public function mount()
    {
        $this->loadThemeColors();
        $this->loadData();
    }

    protected function loadThemeColors()
    {
        try {
            $settings = app(GeneralSettings::class);
            $theme = $settings->site_theme ?? [];

            // Default colors
            $this->primaryColor = $theme['primary'] ?? '#07a0cf';
            $this->primaryColorDark = $theme['primary_dark'] ?? '#0589b0';
            $this->primaryGradientFrom = $settings->primary_gradient_from ?? '#3b82f6';
            $this->primaryGradientTo = $settings->primary_gradient_to ?? '#1d4ed8';
            $this->successColor = $theme['success'] ?? '#10b981';
            $this->dangerColor = $theme['danger'] ?? '#ef4444';
            $this->warningColor = $theme['warning'] ?? '#f59e0b';
            $this->infoColor = $theme['info'] ?? '#3b82f6';
        } catch (\Exception $e) {
            // Default fallback colors
            $this->primaryColor = '#07a0cf';
            $this->primaryColorDark = '#0589b0';
            $this->primaryGradientFrom = '#3b82f6';
            $this->primaryGradientTo = '#1d4ed8';
            $this->successColor = '#10b981';
            $this->dangerColor = '#ef4444';
            $this->warningColor = '#f59e0b';
            $this->infoColor = '#3b82f6';
        }
    }

    public function loadData()
    {
        // Total balance across all active accounts
        $this->totalBalance = PaymentAccount::active()->get()->sum(function ($account) {
            return $account->getAvailableBalance();
        });

        // This month stats
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $this->totalInThisMonth = Transaction::whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->where('type', 'in')
            ->sum('amount');

        $this->totalOutThisMonth = Transaction::whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->where('type', 'out')
            ->sum('amount');

        // Pending payments count
        $pendingExpense = Expense::whereIn('status', ['Pending', 'Disetujui'])->count();
        $pendingAdvance = Advance::whereIn('status', ['Pending', 'Disetujui'])->count();
        $this->pendingPayments = $pendingExpense + $pendingAdvance;

        // All active accounts with balance
        $this->accounts = PaymentAccount::active()
            ->withCount(['transactions as total_in' => function ($query) {
                $query->where('type', 'in');
            }, 'transactions as total_out' => function ($query) {
                $query->where('type', 'out');
            }])
            ->get()
            ->map(function ($account) {
                $account->available_balance = $account->getAvailableBalance();
                $account->percentage = $this->totalBalance > 0
                    ? round(($account->available_balance / $this->totalBalance) * 100, 1)
                    : 0;
                return $account;
            })
            ->sortByDesc('available_balance');

        // Recent transactions
        $this->recentTransactions = Transaction::with(['paymentAccount', 'user'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Monthly stats (last 6 months)
        $this->monthlyStats = collect();
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $in = Transaction::whereBetween('transaction_date', [$start, $end])
                ->where('type', 'in')
                ->sum('amount');
            $out = Transaction::whereBetween('transaction_date', [$start, $end])
                ->where('type', 'out')
                ->sum('amount');

            $this->monthlyStats->push([
                'month' => $month->format('M Y'),
                'in' => (float) $in,
                'out' => (float) $out,
                'net' => (float) ($in - $out),
            ]);
        }
    }

    public function refreshData()
    {
        $this->loadData();
    }
}
