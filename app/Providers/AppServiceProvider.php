<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\Advance;
use App\Models\Announcement;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Leads;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\TaskStage;
use App\Models\Company;
use App\Models\Account;
use App\Policies\AdvancePolicy;
use App\Policies\AnnouncementPolicy;
use App\Policies\AssetPolicy;
use App\Policies\ContractPolicy;
use App\Policies\DealPolicy;
use App\Policies\EventPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\LeadsPolicy;
use App\Policies\PaymentAccountPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\QuotationPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TaskStagePolicy;
use App\Policies\ProjectTimelineBoardPolicy;
use App\Filament\Pages\ProjectTimelineBoard;
use App\Observers\ContractObserver;
use App\Observers\DealObserver;
use App\Observers\InvoiceObserver;
use App\Observers\PaymentAccountObserver;
use Filament\Tables\Table;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use TomatoPHP\FilamentInvoices\Facades\FilamentInvoices;
use TomatoPHP\FilamentInvoices\Services\Contracts\InvoiceFor;
use TomatoPHP\FilamentInvoices\Services\Contracts\InvoiceFrom;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Policies with Gate
        Gate::policy(Activity::class, \App\Policies\ActivityPolicy::class);
        Gate::policy(Advance::class, AdvancePolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(Deal::class, DealPolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Leads::class, LeadsPolicy::class);
        Gate::policy(PaymentAccount::class, PaymentAccountPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(PurchaseRequest::class, PurchaseRequestPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(TaskStage::class, TaskStagePolicy::class);
        Gate::policy(ProjectTimelineBoard::class, ProjectTimelineBoardPolicy::class);

        // Register Observers
        PaymentAccount::observe(PaymentAccountObserver::class);
        Contract::observe(ContractObserver::class);
        Deal::observe(DealObserver::class);
        // Task, Announcement, Advance, Expense, dan Absence mendaftarkan observer-nya
        // sendiri di model; mendaftarkannya lagi di sini membuat notifikasi terkirim dobel.
        Invoice::observe(InvoiceObserver::class);

        // Dark Mode Configuration
        try {
            $darkModeEnabled = app(\App\Settings\GeneralSettings::class)->dark_mode_enabled ?? false;
            FilamentView::registerRenderHook('styles.before', function () use ($darkModeEnabled) {
                return $this->getDarkModeStyles($darkModeEnabled);
            });
        } catch (\Exception $e) {
            FilamentView::registerRenderHook('styles.before', function () {
                return '';
            });
        }

        FilamentInvoices::registerFor([
            InvoiceFor::make(Account::class)
                ->label('Account')
        ]);
        FilamentInvoices::registerFrom([
            InvoiceFrom::make(Company::class)
                ->label('Company')
        ]);

        // Format Indonesia untuk semua tabel: "1.250.000" dan "24 Okt 2026", bukan
        // "1,250,000.00" dan "Oct 24, 2026". ->rupiah() untuk kolom nominal:
        // "Rp 1.250.000" tanpa ",00", sama dengan bagian lain aplikasi.
        Table::$defaultNumberLocale = 'id';
        Table::$defaultDateDisplayFormat = 'j M Y';
        Table::$defaultDateTimeDisplayFormat = 'j M Y, H.i';
        \Filament\Tables\Columns\TextColumn::macro('rupiah', function () {
            /** @var \Filament\Tables\Columns\TextColumn $this */
            return $this->formatStateUsing(fn ($state) => is_numeric($state) ? \App\Helpers\Money::signed($state) : $state);
        });

        Table::configureUsing(function (Table $table): void {
            $table
                ->emptyStateHeading('No data yet')
                ->defaultPaginationPageOption(10)
                ->paginated([10, 25, 50, 100])
                ->extremePaginationLinks()
                ->defaultSort('created_at', 'desc');
        });

        // # \Opcodes\LogViewer
        LogViewer::auth(function ($request) {
            $role = auth()?->user()?->roles?->first()->name;
            return $role == config('filament-shield.super_admin.name');
        });

        // # Hooks
        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            fn (): View => view('filament.components.panel-footer'),
        );
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn (): View => view('filament.components.button-website'),
        );
    }

    private function getDarkModeStyles(bool $enabled): string
    {
        if (!$enabled) {
            return '';
        }

        return <<<CSS
        <style>
            html.dark {
                color-scheme: dark;
            }
            html.dark body {
                background-color: #18181b;
                color: #fafafa;
            }
            html.dark .fi-body {
                background-color: #18181b;
            }
            html.dark .fi-sidebar {
                background-color: #1f1f23;
                border-color: #27272a;
            }
            html.dark .fi-topbar {
                background-color: rgba(31, 31, 35, 0.95);
                border-color: #27272a;
            }
            html.dark .fi-card {
                background-color: #1f1f23;
                border-color: #27272a;
            }
            html.dark .fi-ta {
                background-color: #1f1f23;
                border-color: #27272a;
            }
            html.dark .fi-ta-header {
                background-color: #27272a;
            }
            html.dark .fi-ta-row {
                border-color: #27272a;
            }
            html.dark .fi-ta-row:hover {
                background-color: rgba(139, 92, 246, 0.1);
            }
            html.dark .fi-input,
            html.dark .fi-select {
                background-color: #27272a;
                border-color: #3f3f46;
                color: #fafafa;
            }
            html.dark .fi-section {
                background-color: #1f1f23;
                border-color: #27272a;
            }
            html.dark .fi-dropdown {
                background-color: #27272a;
                border-color: #3f3f46;
            }
            html.dark .fi-dropdown-item:hover {
                background-color: rgba(139, 92, 246, 0.15);
            }
        </style>
        CSS;
    }
}
