<?php

namespace App\Providers;

use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;
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
        // Les KPI du tableau de bord sont mis en cache. Sans invalidation,
        // ils restent figes jusqu'a 30 minutes apres une modification.
        Ticket::saved(function (): void {
            Cache::forget('dashboard.kpis');
        });

        Ticket::deleted(function (): void {
            Cache::forget('dashboard.kpis');
        });
    }
}
