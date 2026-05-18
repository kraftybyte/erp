<?php

namespace App\Providers;
use App\Policies\RolePolicy;
use App\Policies\PermissionPolicy;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use App\Policies\ActivityPolicy;
use App\Policies\ExceptionPolicy;
use App\Policies\TaskPolicy;
use BezhanSalleh\FilamentExceptions\Models\Exception;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use TomatoPHP\FilamentInvoices\Models\Invoice;
use App\Policies\InvoicePolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \TomatoPHP\FilamentInvoices\Models\Invoice::class => \App\Policies\InvoicePolicy::class,
        Invoice::class => InvoicePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Exception::class, ExceptionPolicy::class);

        Gate::before(function (User $user, string $ability) {
            return $user->isSuperAdmin() ? true : null;
        });
    }
}
