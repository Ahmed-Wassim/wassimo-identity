<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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
        // MySQL 8 + utf8mb4 caps an index key at 3072 bytes (InnoDB DYNAMIC row
        // format). Spatie's compound indexes over varchar(255) columns hit that
        // limit (255 × 4 bytes × 3 columns ≈ 3060 + overhead → ERROR 1071).
        // Capping at 191 keeps every indexed column inside the limit without
        // altering the published Spatie migration. See ADR-014 §Consequences.
        Schema::defaultStringLength(191);

        // Super-admin bypass: returning null (not true) lets other Gate checks
        // still run for the admin role, rather than short-circuiting everything.
        // Returning true here would make can() always true, which prevents
        // testing whether a specific permission exists. ADR-016.
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('admin')) {
                return null; // fall through to normal permission checks
            }
        });
    }
}
