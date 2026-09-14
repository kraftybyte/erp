<?php

namespace App\Filament\Pages;

use App\Models\Advance;
use App\Models\Asset;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Leads;
use App\Models\PaymentAccount;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Realisasi;
use App\Models\RealisasiItem;
use App\Models\Task;
use App\Models\Transaction;
use Carbon\Carbon;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use TomatoPHP\FilamentInvoices\Models\Invoice;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class AnalyticsDashboard extends Page
{
    use HasPageShield, WithPagination;

    protected $paginationTheme = 'bootstrap';
    protected $pageName = 'advances_page';

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'Financial Analytics';
    protected static ?string $title = 'Financial Analytics Dashboard';
    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('analytics', 'Financial Analytics');
    }

    public static function getNavigationGroup(): ?string
    {
        return __("Finance");
    }

    protected static string $view = 'filament.pages.analytics-dashboard';

    #[Url]
    public ?string $activeTab = 'overview';

    // Date Filter
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public string $dateRange = 'this_month';

    // ============================================
    // BOOT & MOUNT
    // ============================================

    public function mount(): void
    {
        $this->setDateRange($this->dateRange);
    }

    public function setDateRange(string $range): void
    {
        $this->dateRange = $range;
        $now = Carbon::now();

        switch ($range) {
            case 'today':
                $this->dateFrom = $now->copy()->startOfDay()->toDateString();
                $this->dateTo = $now->copy()->endOfDay()->toDateString();
                break;
            case 'this_week':
                $this->dateFrom = $now->copy()->startOfWeek()->toDateString();
                $this->dateTo = $now->copy()->endOfWeek()->toDateString();
                break;
            case 'this_month':
                $this->dateFrom = $now->copy()->startOfMonth()->toDateString();
                $this->dateTo = $now->copy()->endOfMonth()->toDateString();
                break;
            case 'last_month':
                $this->dateFrom = $now->copy()->subMonth()->startOfMonth()->toDateString();
                $this->dateTo = $now->copy()->subMonth()->endOfMonth()->toDateString();
                break;
            case 'this_quarter':
                $this->dateFrom = $now->copy()->startOfQuarter()->toDateString();
                $this->dateTo = $now->copy()->endOfQuarter()->toDateString();
                break;
            case 'this_year':
                $this->dateFrom = $now->copy()->startOfYear()->toDateString();
                $this->dateTo = $now->copy()->endOfYear()->toDateString();
                break;
            case 'custom':
                // Keep existing values
                break;
        }
    }

    // ============================================
    // OVERVIEW DATA
    // ============================================

    public function getOverviewData(): array
    {
        $startDate = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : Carbon::now()->endOfDay();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfYear = Carbon::now()->startOfYear();

        // Cache leads counts
        $totalLeads = Leads::count();
        $convertedLeads = Leads::where('is_converted', true)->count();

        return [
            // Leads
            'leads' => [
                'total' => $totalLeads,
                'period' => Leads::whereBetween('created_at', [$startDate, $endDate])->count(),
                'converted' => $convertedLeads,
                'conversion_rate' => $totalLeads > 0 ? round($convertedLeads / $totalLeads * 100, 1) : 0,
            ],

            // Deals
            'deals' => [
                'total' => Deal::count(),
                'active' => Deal::whereNotIn('status', ['Selesai', 'Hold', 'Kalah'])->count(),
                'completed' => Deal::where('status', 'Selesai')->count(),
                'period' => Deal::whereBetween('created_at', [$startDate, $endDate])->count(),
            ],

            // Revenue
            'revenue' => [
                'all_time' => Invoice::where('status', 'paid')->sum('grand_total'),
                'year_to_date' => Invoice::where('status', 'paid')->whereBetween('date', [$startOfYear, now()])->sum('grand_total'),
                'period' => Invoice::where('status', 'paid')->whereBetween('date', [$startDate, $endDate])->sum('grand_total'),
                'pending' => Invoice::where('status', 'pending')->sum('grand_total'),
                'pending_count' => Invoice::where('status', 'pending')->count(),
            ],

            // Expenses
            'expenses' => [
                'all_time' => Expense::sum('amount'),
                'year_to_date' => Expense::whereBetween('submission_date', [$startOfYear, now()])->sum('amount'),
                'period' => Expense::whereBetween('submission_date', [$startDate, $endDate])->sum('amount'),
                'pending' => Expense::where('status', 'Pending')->sum('amount'),
                'pending_count' => Expense::where('status', 'Pending')->count(),
            ],

            // Advances
            'advances' => [
                'all_time' => Advance::sum('amount'),
                'year_to_date' => Advance::whereBetween('submission_date', [$startOfYear, now()])->sum('amount'),
                'period' => Advance::whereBetween('submission_date', [$startDate, $endDate])->sum('amount'),
                'pending' => Advance::where('status', 'Pending')->sum('amount'),
                'pending_count' => Advance::where('status', 'Pending')->count(),
                'recent' => Advance::with('user')
                    ->whereBetween('submission_date', [$startDate, $endDate])
                    ->orderBy('submission_date', 'desc')
                    ->limit(10)
                    ->get(),
            ],

            // Balance
            'balance' => [
                'total' => PaymentAccount::active()->get()->sum(fn($a) => $a->getAvailableBalance()),
                'accounts' => PaymentAccount::active()->count(),
                // monthly_out dihitung dari Expense + Advance (semua status)
                'monthly_in' => Invoice::where('status', 'paid')->whereBetween('date', [$startOfMonth, now()])->sum('grand_total'),
                'monthly_out' => Expense::whereBetween('submission_date', [$startOfMonth, now()])->sum('amount')
                    + Advance::whereBetween('submission_date', [$startOfMonth, now()])->sum('amount'),
            ],

            // Tasks
            'tasks' => [
                'completed' => Task::where('status', 'Done')->whereBetween('selesai_task', [$startOfMonth, now()])->count(),
                'in_progress' => Task::whereIn('status', ['Log', 'Process', 'Review'])->count(),
                'overdue' => Task::whereIn('status', ['Log', 'Process', 'Review'])->where('selesai_task', '<', now())->count(),
            ],

            // Payment Accounts
            'accounts' => PaymentAccount::active()
                ->select('payment_accounts.*')
                ->selectSub(function ($query) {
                    $query->selectRaw('COALESCE(SUM(CASE WHEN type = "in" THEN amount ELSE 0 END), 0)')
                        ->from('transactions')
                        ->whereColumn('payment_account_id', 'payment_accounts.id');
                }, 'total_in')
                ->selectSub(function ($query) {
                    $query->selectRaw('COALESCE(SUM(CASE WHEN type = "out" THEN amount ELSE 0 END), 0)')
                        ->from('transactions')
                        ->whereColumn('payment_account_id', 'payment_accounts.id');
                }, 'total_out')
                ->get()
                ->map(function ($account) {
                    $account->available_balance = $account->getAvailableBalance();
                    return $account;
                }),
        ];
    }

    // ============================================
    // REVENUE DATA
    // ============================================

    public function getRevenueData(): array
    {
        $startDate = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : Carbon::now()->endOfDay();
        $now = Carbon::now();

        // Invoice stats
        $stats = Invoice::query()
            ->selectRaw("
                SUM(CASE WHEN status = 'paid' THEN grand_total ELSE 0 END) as total_revenue,
                SUM(CASE WHEN status = 'paid' AND MONTH(created_at) = ? AND YEAR(created_at) = ? THEN grand_total ELSE 0 END) as current_month_revenue,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'pending' THEN grand_total ELSE 0 END) as pending_total,
                SUM(CASE WHEN status = 'pending' AND due_date < ? THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status = 'pending' AND due_date < ? THEN grand_total ELSE 0 END) as overdue_total
            ", [$now->month, $now->year, $now->toDateString(), $now->toDateString()])
            ->first();

        // Recent invoices
        $recentInvoices = Invoice::whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Monthly trend (last 12 months)
        $monthlyTrend = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $monthlyTrend[] = [
                'month' => $month->format('M Y'),
                'paid' => Invoice::where('status', 'paid')
                    ->whereYear('created_at', $month->year)
                    ->whereMonth('created_at', $month->month)
                    ->sum('grand_total'),
                'pending' => Invoice::where('status', 'pending')
                    ->whereYear('created_at', $month->year)
                    ->whereMonth('created_at', $month->month)
                    ->sum('grand_total'),
            ];
        }

        // Status distribution
        $distribution = Invoice::query()
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'paid' THEN grand_total ELSE 0 END), 0) as paid,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN grand_total ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN grand_total ELSE 0 END), 0) as cancelled,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN grand_total ELSE 0 END), 0) as draft,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count,
                COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_count
            ")
            ->first();

        return [
            'stats' => $stats,
            'recent' => $recentInvoices,
            'trend' => $monthlyTrend,
            'distribution' => $distribution,
        ];
    }

    // ============================================
    // EXPENSES DATA
    // ============================================

    public function getExpensesData(): array
    {
        $startDate = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : Carbon::now()->endOfDay();

        // ========== EXPENSES ==========
        $expenses = Expense::whereBetween('submission_date', [$startDate, $endDate])
            ->with('user', 'itemExpenses')
            ->orderBy('submission_date', 'desc')
            ->limit(20)
            ->get();

        // ========== ADVANCES ==========
        $advances = Advance::whereBetween('submission_date', [$startDate, $endDate])
            ->with('user', 'project.deal')
            ->orderBy('submission_date', 'desc')
            ->limit(20)
            ->get();

        // ========== SUMMARY ==========
        // Hitung semua expense/advance tanpa filter status, pakai field 'amount'
        $totalExpenses = Expense::whereBetween('submission_date', [$startDate, $endDate])->sum('amount');
        $totalAdvances = Advance::whereBetween('submission_date', [$startDate, $endDate])->sum('amount');
        $pendingExpenses = Expense::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Pending')->sum('amount');
        $pendingAdvances = Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Pending')->sum('amount');

        // ========== BREAKDOWN BY USER ==========
        $expensesByUser = Expense::whereBetween('submission_date', [$startDate, $endDate])
            ->with('user')
            ->get()
            ->groupBy(fn($e) => $e->user?->name ?? 'Unknown')
            ->map(fn($items, $user) => [
                'user' => $user,
                'count' => $items->count(),
                'total' => $items->sum('amount'),
            ])
            ->sortByDesc('total')
            ->take(10)
            ->values();

        $advancesByUser = Advance::whereBetween('submission_date', [$startDate, $endDate])
            ->with('user')
            ->get()
            ->groupBy(fn($a) => $a->user?->name ?? 'Unknown')
            ->map(fn($items, $user) => [
                'user' => $user,
                'count' => $items->count(),
                'total' => $items->sum('amount'),
            ])
            ->sortByDesc('total')
            ->take(10)
            ->values();

        // ========== BREAKDOWN BY PROJECT (Advances) ==========
        $advancesByProject = Advance::whereBetween('submission_date', [$startDate, $endDate])
            ->whereNotNull('project_id')
            ->with('project.deal')
            ->get()
            ->groupBy(fn($a) => $a->project?->nama_project ?? 'Unknown')
            ->map(fn($items, $project) => [
                'project' => $project,
                'client' => $items->first()->project?->deal?->nama_perusahaan ?? '-',
                'count' => $items->count(),
                'total' => $items->sum('amount'),
            ])
            ->sortByDesc('total')
            ->take(10)
            ->values();

        // ========== TREND BY MONTH (last 6 months) ==========
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            $expensesMonth = Expense::whereBetween('submission_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $advancesMonth = Advance::whereBetween('submission_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $monthlyTrend[] = [
                'month' => $month->format('M Y'),
                'expenses' => $expensesMonth,
                'advances' => $advancesMonth,
                'total' => $expensesMonth + $advancesMonth,
            ];
        }

        // ========== BREAKDOWN BY CATEGORY (Expense) ==========
        $expensesByCategory = Expense::whereBetween('submission_date', [$startDate, $endDate])
            ->whereNotNull('category')
            ->get()
            ->groupBy('category')
            ->map(fn($items, $category) => [
                'category' => $category,
                'count' => $items->count(),
                'total' => $items->sum('amount'),
            ])
            ->sortByDesc('total')
            ->values();

        // ========== TOP EXPENSE ITEMS (Grouped by similar names) ==========
        $topExpenseItems = \App\Models\ItemExpense::whereHas('expense', function ($q) use ($startDate, $endDate) {
            $q->whereBetween('submission_date', [$startDate, $endDate]);
        })
            ->get()
            ->groupBy(function ($item) {
                // Group by first word only (e.g., "Transport Jakarta" + "Transport Bandung" = "Transport")
                $words = explode(' ', trim($item->name ?? ''));
                return strtolower($words[0] ?? 'unknown');
            })
            ->map(function ($items, $groupKey) {
                // Get the most common name pattern from the group
                $name = $items->first()->name ?? 'Unknown';
                $words = explode(' ', trim($name));
                $displayName = $words[0]; // Use first word

                return [
                    'name' => $displayName,
                    'count' => $items->count(),
                    'qty' => $items->sum('quantity'),
                    'total' => $items->sum(fn($i) => $i->quantity * $i->price),
                    'avg_price' => $items->avg(fn($i) => $i->price),
                ];
            })
            ->sortByDesc('total')
            ->take(15)
            ->values();

        return [
            // Lists
            'expenses' => $expenses,
            'advances' => $advances,

            // Summary totals
            'total_expenses' => $totalExpenses,
            'total_advances' => $totalAdvances,
            'total_combined' => $totalExpenses + $totalAdvances,
            'pending_expenses' => $pendingExpenses,
            'pending_advances' => $pendingAdvances,
            'pending_expenses_count' => Expense::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Pending')->count(),
            'pending_advances_count' => Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Pending')->count(),

            // Breakdown
            'by_user' => [
                'expenses' => $expensesByUser,
                'advances' => $advancesByUser,
            ],
            'by_project' => $advancesByProject,
            'by_category' => [
                'expenses' => $expensesByCategory,
            ],
            'top_items' => $topExpenseItems,
            'monthly_trend' => $monthlyTrend,
        ];
    }

    // ============================================
    // ADVANCES DATA
    // ============================================

    public function getAdvancesData(): array
    {
        $startDate = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : Carbon::now()->endOfDay();

        $allAdvancesQuery = Advance::with(['user', 'project', 'paymentAccount'])
            ->whereBetween('submission_date', [$startDate, $endDate])
            ->orderBy('submission_date', 'desc');

        $allAdvances = $allAdvancesQuery->paginate(10, ['*'], 'advances_page');

        $byStatus = [
            'Pending' => Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Pending')->count(),
            'Disetujui' => Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Disetujui')->count(),
            'Dibayarkan' => Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Dibayarkan')->count(),
            'Selesai' => Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Selesai')->count(),
            'Ditolak' => Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Ditolak')->count(),
        ];

        return [
            'all' => $allAdvances,
            'by_status' => $byStatus,
            'total_all_time' => Advance::whereBetween('submission_date', [$startDate, $endDate])->sum('amount'),
            'total_pending' => Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Pending')->sum('amount'),
            'total_pending_count' => Advance::whereBetween('submission_date', [$startDate, $endDate])->where('status', 'Pending')->count(),
            'count_by_status' => $byStatus,
        ];
    }

    // ============================================
    // TRANSACTIONS DATA
    // ============================================

    public function getTransactionsData(): array
    {
        $startDate = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : Carbon::now()->endOfDay();

        $transactions = Transaction::with('paymentAccount')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->orderBy('transaction_date', 'desc')
            ->limit(50)
            ->get();

        $accounts = PaymentAccount::active()->with('transactions')->get();

        $summary = [
            'total_in' => Transaction::whereBetween('transaction_date', [$startDate, $endDate])->where('type', 'in')->sum('amount'),
            'total_out' => Transaction::whereBetween('transaction_date', [$startDate, $endDate])->where('type', 'out')->sum('amount'),
            'net' => Transaction::whereBetween('transaction_date', [$startDate, $endDate])->where('type', 'in')->sum('amount')
                - Transaction::whereBetween('transaction_date', [$startDate, $endDate])->where('type', 'out')->sum('amount'),
        ];

        return [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'summary' => $summary,
        ];
    }

    // ============================================
    // PIPELINE DATA
    // ============================================

    public function getPipelineData(): array
    {
        $deals = Deal::with('quotations')->get();

        // Pipeline stages
        $stages = [
            'Negosiasi' => ['count' => 0, 'value' => 0],
            'Menunggu Pembayaran' => ['count' => 0, 'value' => 0],
            'Dalam Tahap Project' => ['count' => 0, 'value' => 0],
            'Selesai' => ['count' => 0, 'value' => 0],
        ];

        foreach ($deals as $deal) {
            $dealValue = $deal->quotations->where('status', 'Approved')->sum('grand_total');
            if (isset($stages[$deal->status])) {
                $stages[$deal->status]['count']++;
                $stages[$deal->status]['value'] += $dealValue;
            }
        }

        // Client data
        $totalClients = Deal::distinct('nama_perusahaan')->count('nama_perusahaan');
        $totalRevenue = Quotation::where('status', 'Approved')->sum('grand_total');
        $avgClientValue = $totalClients > 0 ? $totalRevenue / $totalClients : 0;

        // Quotations
        $quotations = Quotation::with(['deal', 'user'])
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();

        return [
            'stages' => $stages,
            'total_pipeline' => $stages['Negosiasi']['value'] + $stages['Menunggu Pembayaran']['value'],
            'clients' => $totalClients,
            'revenue' => $totalRevenue,
            'avg_client' => $avgClientValue,
            'quotations' => $quotations,
            'pending_quotations' => Quotation::where('status', 'Pending')->count(),
            'approved_quotations' => Quotation::where('status', 'Approved')->count(),
        ];
    }

    // ============================================
    // ASSETS DATA
    // ============================================

    public function getAssetsData(): array
    {
        $startDate = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : Carbon::now()->endOfMonth();

        $allAssets = Asset::with('user')
            ->whereBetween('tanggal_pembelian', [$startDate, $endDate])
            ->orderBy('tanggal_pembelian', 'desc')
            ->paginate(10, ['*'], 'assets_page');

        $totalValue = Asset::whereBetween('tanggal_pembelian', [$startDate, $endDate])->sum('harga_barang');

        $byCondition = [
            'Baik' => Asset::whereBetween('tanggal_pembelian', [$startDate, $endDate])->where('kondisi', 'Baik')->count(),
            'Rusak Ringan' => Asset::whereBetween('tanggal_pembelian', [$startDate, $endDate])->where('kondisi', 'Rusak Ringan')->count(),
            'Rusak Berat' => Asset::whereBetween('tanggal_pembelian', [$startDate, $endDate])->where('kondisi', 'Rusak Berat')->count(),
        ];

        return [
            'all' => $allAssets,
            'total' => Asset::whereBetween('tanggal_pembelian', [$startDate, $endDate])->count(),
            'total_value' => $totalValue,
            'by_condition' => $byCondition,
        ];
    }

    // ============================================
    // PROJECTS DATA
    // ============================================

    public function getProjectsData(): array
    {
        $startDate = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : Carbon::now()->endOfMonth();

        $allProjects = Project::with(['Deal', 'tasks'])
            ->whereBetween('timeline_mulai', [$startDate, $endDate])
            ->orderBy('timeline_mulai', 'desc')
            ->paginate(10, ['*'], 'projects_page');

        $byStatus = [
            'Not Started' => Project::whereBetween('timeline_mulai', [$startDate, $endDate])->where('status', 'Not Started')->count(),
            'On Progress' => Project::whereBetween('timeline_mulai', [$startDate, $endDate])->where('status', 'On Progress')->count(),
            'Completed' => Project::whereBetween('timeline_mulai', [$startDate, $endDate])->where('status', 'Completed')->count(),
            'Cancelled' => Project::whereBetween('timeline_mulai', [$startDate, $endDate])->where('status', 'Cancelled')->count(),
        ];

        return [
            'all' => $allProjects,
            'total' => Project::whereBetween('timeline_mulai', [$startDate, $endDate])->count(),
            'active' => Project::whereBetween('timeline_mulai', [$startDate, $endDate])->where('status', 'On Progress')->count(),
            'completed' => Project::whereBetween('timeline_mulai', [$startDate, $endDate])->where('status', 'Completed')->count(),
            'by_status' => $byStatus,
        ];
    }

    // ============================================
    // TABS
    // ============================================

    public function getTabs(): array
    {
        return [
            'overview' => ['label' => 'Overview', 'icon' => 'chart-pie'],
            'revenue' => ['label' => 'Revenue', 'icon' => 'currency-dollar'],
            'expenses' => ['label' => 'Expenses', 'icon' => 'credit-card'],
            'advances' => ['label' => 'Advances', 'icon' => 'banknotes'],
            'transactions' => ['label' => 'Transactions', 'icon' => 'arrows-right-left'],
            'advance_analysis' => ['label' => 'Analisis Advance', 'icon' => 'chart-bar-square'],
            'pipeline' => ['label' => 'Pipeline', 'icon' => 'funnel'],
            'assets' => ['label' => 'Assets', 'icon' => 'cube'],
            'projects' => ['label' => 'Projects', 'icon' => 'folder'],
        ];
    }

    // ============================================
    // ADVANCE ANALYSIS DATA (By Project & Items)
    // ============================================
    public function getAdvanceAnalysisData(): array
    {
        $startDate = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : Carbon::now()->endOfDay();

        // Get advances with relations
        $advances = Advance::with(['project.deal', 'user', 'realizasis.items'])
            ->whereBetween('submission_date', [$startDate, $endDate])
            ->get();

        // Summary
        $paidAdvances = $advances; // Semua advance dihitung
        $totalPaid = $paidAdvances->sum('amount');
        $totalRealisasi = $advances->sum(fn($a) => $a->realizasis->flatMap(fn($r) => $r->items)->sum('subtotal'));
        $pendingAdvances = $advances->where('status', 'Pending');
        $pendingCount = $pendingAdvances->count();
        $pendingAmount = $pendingAdvances->sum('amount');

        // By Project
        $byProject = $advances->filter(fn($a) => $a->project_id)
            ->groupBy('project_id')
            ->map(fn($items, $id) => [
                'project_id' => $id,
                'project_name' => $items->first()->project?->nama_project ?? 'Tanpa Project',
                'client_name' => $items->first()->project?->deal?->nama_perusahaan ?? '-',
                'total_paid' => $items->sum('amount'),
                'total_realisasi' => $items->sum(fn($a) => $a->realizasis->flatMap(fn($r) => $r->items)->sum('subtotal')),
                'sisa' => $items->sum('amount') - $items->sum(fn($a) => $a->realizasis->flatMap(fn($r) => $r->items)->sum('subtotal')),
                'count' => $items->count(),
                'is_overrun' => $items->sum('amount') < $items->sum(fn($a) => $a->realizasis->flatMap(fn($r) => $r->items)->sum('subtotal')),
            ])
            ->sortByDesc('total_paid')
            ->take(20)
            ->values();

        // Top Items
        $allItems = RealisasiItem::whereHas('realizasi.advance', fn($q) => $q->whereBetween('submission_date', [$startDate, $endDate]))
            ->with('realizasi.advance.project.deal')
            ->get();

        $topItems = $allItems->groupBy('name')
            ->map(fn($items) => [
                'name' => $items->first()->name,
                'total_qty' => $items->sum('quantity'),
                'total_amount' => $items->sum('subtotal'),
                'avg_price' => $items->avg('price'),
                'count' => $items->count(),
            ])
            ->sortByDesc('total_amount')
            ->take(15)
            ->values();

        return [
            'summary' => [
                'total_paid' => $totalPaid,
                'total_realisasi' => $totalRealisasi,
                'sisa' => $totalPaid - $totalRealisasi,
                'pending_count' => $pendingCount,
                'pending_amount' => $pendingAmount,
                'project_count' => $byProject->count(),
            ],
            'by_project' => $byProject,
            'top_items' => $topItems,
        ];
    }
}
