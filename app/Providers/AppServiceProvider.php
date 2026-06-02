<?php

namespace App\Providers;

use Filament\Tables\Table;
use App\Models\Permission;
use App\Models\Role;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use TomatoPHP\FilamentInvoices\Facades\FilamentInvoices;
use TomatoPHP\FilamentInvoices\Services\Contracts\InvoiceFor;
use TomatoPHP\FilamentInvoices\Services\Contracts\InvoiceFrom;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use App\Models\PaymentAccount;
use App\Models\Company;
use App\Models\Account;
use App\Models\Invoice;
use App\Policies\InvoicePolicy;
use App\Policies\ContractPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\DealPolicy;
use App\Policies\LeadsPolicy;
use App\Policies\QuotationPolicy;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\AnnouncementPolicy;
use App\Policies\EventPolicy;
use App\Policies\AssetPolicy;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Deal;
use App\Models\Leads;
use App\Models\Quotation;
use App\Models\PurchaseRequest;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\Asset;
use App\Models\SocialAccount;
use App\Observers\TaskObserver;
use App\Observers\AnnouncementObserver;
use App\Observers\PaymentAccountObserver;
use App\Observers\ContractObserver;
use App\Observers\DealObserver;
use App\Observers\SocialAccountObserver;
use App\Observers\InvoiceObserver;



class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Deal::class, DealPolicy::class);
        Gate::policy(Leads::class, LeadsPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(PurchaseRequest::class, PurchaseRequestPolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);

        // Register Observers
        PaymentAccount::observe(PaymentAccountObserver::class);
        Contract::observe(ContractObserver::class);
        Deal::observe(DealObserver::class);
        SocialAccount::observe(SocialAccountObserver::class);
        Task::observe(TaskObserver::class);
        Announcement::observe(AnnouncementObserver::class);
        Invoice::observe(InvoiceObserver::class);

        // Dark Mode Configuration
        try {
            $darkModeEnabled = app(\App\Settings\GeneralSettings::class)->dark_mode_enabled ?? false;
            FilamentView::registerRenderHook('styles.before', function () use ($darkModeEnabled) {
                return $this->getDarkModeStyles($darkModeEnabled);
            });
        } catch (\Exception $e) {
            // Settings loading failed, skip dark mode
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
