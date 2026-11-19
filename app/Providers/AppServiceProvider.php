<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
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
        Paginator::useBootstrapFive();

        // Starting a payment is limited per member, not per address (many members share one mobile network)
        RateLimiter::for('portal-payments', fn (Request $request) => Limit::perMinute(6)->by('member:'.($request->user('member')?->id ?? $request->ip())));
    }
}
