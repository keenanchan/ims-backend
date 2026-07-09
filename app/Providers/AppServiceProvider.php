<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Role;
use App\Models\SyncConflict;
use App\Models\Team;
use App\Models\User;
use App\Policies\DepartmentPolicy;
use App\Policies\FormSubmissionPolicy;
use App\Policies\FormTemplatePolicy;
use App\Policies\RolePolicy;
use App\Policies\SyncConflictPolicy;
use App\Policies\TeamPolicy;
use App\Policies\UserPolicy;
use App\Services\Identity\IdentityService;
use App\Services\Identity\IdentityServiceInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(IdentityServiceInterface::class, IdentityService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Morph map for ABAC permission subjects.
        // Stored as short aliases in form_template_permissions.permissible_type.
        Relation::morphMap([
            'user' => User::class,
            'role' => Role::class,
            'department' => Department::class,
            'team' => Team::class,
        ]);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(FormTemplate::class, FormTemplatePolicy::class);
        Gate::policy(FormSubmission::class, FormSubmissionPolicy::class);
        Gate::policy(SyncConflict::class, SyncConflictPolicy::class);

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->user()?->id ?? $request->ip());
        });
    }
}
