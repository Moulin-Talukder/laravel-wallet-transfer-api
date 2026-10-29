<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Named limiters get separate counters; plain throttle:X,Y middleware would share one per user.
        RateLimiter::for('transfers', fn (Request $request) => Limit::perMinute(config('wallet.rate_limits.transfers_per_minute'))
            ->by($request->user()->id));

        RateLimiter::for('wallet-reads', fn (Request $request) => Limit::perMinute(config('wallet.rate_limits.reads_per_minute'))
            ->by($request->user()->id));

        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input('email');
            $email = is_string($email) ? Str::transliterate(Str::lower(trim($email))) : '';

            return Limit::perMinute(config('wallet.rate_limits.login_attempts_per_minute'))
                ->by($email.'|'.$request->ip());
        });
    }
}
