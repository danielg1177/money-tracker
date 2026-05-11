<?php

namespace App\Providers;

use App\Models\User;
use App\Services\PlaidClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PlaidClient::class, fn (): PlaidClient => PlaidClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', function (User $user): bool {
            return $user->is_admin;
        });

        Gate::define('head_of_household', function (User $user): bool {
            return $user->is_head_of_household;
        });

        Gate::define('manage_family', function (User $user): bool {
            return $user->role === 'head_of_household' || $user->is_admin;
        });
    }
}
