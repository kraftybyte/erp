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
use App\Models\Budget;
use App\Observers\BudgetObserver;
use App\Models\Company;
use App\Models\Account;
use TomatoPHP\FilamentInvoices\Models\Invoice;
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
use App\Observers\TaskObserver;
use App\Observers\AnnouncementObserver;



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
        Budget::observe(BudgetObserver::class);
        Task::observe(TaskObserver::class);
        Announcement::observe(AnnouncementObserver::class);
        
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
}
