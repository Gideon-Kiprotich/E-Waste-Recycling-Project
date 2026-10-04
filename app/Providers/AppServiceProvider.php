<?php

namespace App\Providers;

use App\Http\Controllers\Auth\PasswordRecoveryController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('password-recovery', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip())
            ->response(fn () => PasswordRecoveryController::acknowledgeRequest()));
    }
}
