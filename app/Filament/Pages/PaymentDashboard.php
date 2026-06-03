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
use Livewire\Attributes\On;

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

    // Pagination for unlimited scroll
    public $transactionsPage = 1;
    public $transactionsPerPage = 10;
    public $hasMoreTransactions = false;
    public $totalTransactions = 0;

    // Filter state
    public $filterAccount = null;
    public $filterType = null;
    public $filterCategory = null;

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

        // Load transactions with pagination
        $this->loadTransactions(reset: true);
    }

    public function loadTransactions(bool $reset = false)
    {
        if ($reset) {
            $this->transactionsPage = 1;
            $this->recentTransactions = collect();
        }

        $query = Transaction::with(['paymentAccount', 'user'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($this->filterAccount) {
            $query->where('payment_account_id', $this->filterAccount);
        }
        if ($this->filterType) {
            $query->where('type', $this->filterType);
        }
        if ($this->filterCategory) {
            $query->where('category', $this->filterCategory);
        }

        // Get total count
        $this->totalTransactions = (clone $query)->count();

        // Get paginated results
        $newTransactions = (clone $query)
            ->offset(($this->transactionsPage - 1) * $this->transactionsPerPage)
            ->limit($this->transactionsPerPage)
            ->get();

        if ($reset) {
            $this->recentTransactions = $newTransactions;
        } else {
            $this->recentTransactions = $this->recentTransactions->merge($newTransactions);
        }

        // Check if there are more
        $this->hasMoreTransactions = $this->recentTransactions->count() < $this->totalTransactions;
    }

    public function loadMoreTransactions()
    {
        $this->transactionsPage++;
        $this->loadTransactions();
    }

    public function applyFilters()
    {
        $this->loadTransactions(reset: true);
    }

    public function clearFilters()
    {
        $this->filterAccount = null;
        $this->filterType = null;
        $this->filterCategory = null;
        $this->loadTransactions(reset: true);
    }

    #[On('refresh-transactions')]
    public function refreshTransactions()
    {
        $this->loadTransactions(reset: true);
    }

    public function refreshData()
    {
        $this->loadData();
    }
}