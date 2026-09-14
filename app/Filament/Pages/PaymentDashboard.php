<?php

namespace App\Filament\Pages;

use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\Expense;
use App\Models\Advance;
use App\Settings\GeneralSettings;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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

    // Stats
    public float $totalBalance = 0;
    public float $totalInThisMonth = 0;
    public float $totalOutThisMonth = 0;
    public int $pendingPayments = 0;
    public int $pendingExpense = 0;
    public int $pendingAdvance = 0;

    // Collections
    /** @var Collection<int, \App\Models\PaymentAccount> */
    public Collection $accounts;

    /** @var Collection<int, \App\Models\Transaction> */
    public Collection $recentTransactions;

    /** @var Collection<int, array{month: string, in: float, out: float, net: float}> */
    public Collection $monthlyStats;

    /** @var Collection<int, \App\Models\Expense> */
    public Collection $recentExpenses;

    /** @var Collection<int, \App\Models\Advance> */
    public Collection $recentAdvances;

    // Pagination
    public int $transactionsPage = 1;
    public int $transactionsPerPage = 10;
    public bool $hasMoreTransactions = false;
    public int $totalTransactions = 0;

    // Filters
    public ?string $filterAccount = null;
    public ?string $filterType = null;
    public ?string $filterCategory = null;

    // Theme
    public string $primaryColor = '#07a0cf';
    public string $successColor = '#10b981';
    public string $dangerColor = '#ef4444';
    public string $warningColor = '#f59e0b';
    public string $infoColor = '#3b82f6';

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

            $this->primaryColor = ltrim($settings->primary_gradient_from ?? $theme['primary'] ?? $this->primaryColor, '#');
            $this->successColor = ltrim($theme['success'] ?? $this->successColor, '#');
            $this->dangerColor = ltrim($theme['danger'] ?? $this->dangerColor, '#');
            $this->warningColor = ltrim($theme['warning'] ?? $this->warningColor, '#');
            $this->infoColor = ltrim($theme['info'] ?? $this->infoColor, '#');
        } catch (\Exception $e) {
            // Use defaults
        }
    }

    public function loadData()
    {
        // Accounts with balance - use getBalance() for accurate calculation
        $this->accounts = PaymentAccount::active()
            ->get()
            ->map(function ($account) {
                $account->available_balance = $account->getBalance();
                return $account;
            })
            ->sortByDesc('available_balance');

        $this->totalBalance = $this->accounts->sum('available_balance');

        // Monthly stats
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // Total pemasukan dari Transaction (type = 'in')
        $this->totalInThisMonth = (float) Transaction::whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->where('type', 'in')
            ->sum('amount');

        // Total pengeluaran dari Expense + Advance model (bukan Transaction)
        // Hitung semua expense (tanpa filter status)
        $totalExpense = (float) Expense::whereBetween('submission_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');
        $totalAdvance = (float) Advance::whereBetween('submission_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');
        $this->totalOutThisMonth = $totalExpense + $totalAdvance;

        // Pending counts
        $this->pendingExpense = Expense::whereIn('status', ['Pending', 'Disetujui'])->count();
        $this->pendingAdvance = Advance::whereIn('status', ['Pending', 'Disetujui'])->count();
        $this->pendingPayments = $this->pendingExpense + $this->pendingAdvance;

        // Monthly stats (last 6 months) - juga dari Expense model
        $this->monthlyStats = collect();
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            // Pemasukan dari Transaction
            $in = (float) Transaction::whereBetween('transaction_date', [$start, $end])
                ->where('type', 'in')
                ->sum('amount');

            // Pengeluaran dari Expense + Advance (semua status)
            $outExpense = (float) Expense::whereBetween('submission_date', [$start, $end])
                ->sum('amount');
            $outAdvance = (float) Advance::whereBetween('submission_date', [$start, $end])
                ->sum('amount');
            $out = $outExpense + $outAdvance;

            $this->monthlyStats->push([
                'month' => $month->format('M Y'),
                'in' => $in,
                'out' => $out,
                'net' => $in - $out,
            ]);
        }

        // Recent expenses & advances
        $this->recentExpenses = Expense::with(['user', 'project'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $this->recentAdvances = Advance::with(['user', 'project'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Transactions
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

        if ($this->filterAccount) {
            $query->where('payment_account_id', $this->filterAccount);
        }
        if ($this->filterType) {
            $query->where('type', $this->filterType);
        }
        if ($this->filterCategory) {
            $query->where('category', $this->filterCategory);
        }

        $this->totalTransactions = (clone $query)->count();

        $newTransactions = (clone $query)
            ->offset(($this->transactionsPage - 1) * $this->transactionsPerPage)
            ->limit($this->transactionsPerPage)
            ->get();

        if ($reset) {
            $this->recentTransactions = $newTransactions;
        } else {
            $this->recentTransactions = $this->recentTransactions->merge($newTransactions);
        }

        $this->hasMoreTransactions = $this->recentTransactions->count() < $this->totalTransactions;
    }

    public function loadMoreTransactions()
    {
        $this->transactionsPage++;
        $this->loadTransactions();
    }

    public function updatedFilterAccount() { $this->loadTransactions(reset: true); }
    public function updatedFilterType() { $this->loadTransactions(reset: true); }
    public function updatedFilterCategory() { $this->loadTransactions(reset: true); }

    public function clearFilters()
    {
        $this->filterAccount = null;
        $this->filterType = null;
        $this->filterCategory = null;
        $this->loadTransactions(reset: true);
    }

    public function refreshData()
    {
        $this->loadData();
    }

    // Helper methods for blade
    public function getRgba(string $color, float $opacity): string
    {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba({$r}, {$g}, {$b}, {$opacity})";
    }

    public function formatCurrency(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    public function getCategoryLabel(string $category): string
    {
        $labels = [
            'invoice' => 'Invoice',
            'deposit' => 'Setoran',
            'operational' => 'Operasional',
            'payroll' => 'Gaji',
            'marketing' => 'Marketing',
            'office' => 'Kantor',
            'it' => 'IT',
            'utility' => 'Utilitas',
            'withdrawal' => 'Penarikan',
            'transfer' => 'Transfer',
            'payment' => 'Pembayaran',
            'expense' => 'Biaya',
            'income' => 'Pendapatan',
            'transport' => 'Transport',
            'komunikasi' => 'Komunikasi',
            'software' => 'Software',
            'entertainment' => 'Entertainment',
            'project_support' => 'Project Support',
            'biaya_lain' => 'Biaya Lain',
            'other' => 'Lainnya',
        ];
        return $labels[$category] ?? $category;
    }
}
