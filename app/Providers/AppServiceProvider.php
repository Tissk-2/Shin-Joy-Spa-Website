<?php

namespace App\Providers;

use App\Http\Responses\NeutralPasswordResetLinkResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            NeutralPasswordResetLinkResponse::class,
        );
        $this->app->singleton(
            SuccessfulPasswordResetLinkRequestResponse::class,
            NeutralPasswordResetLinkResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::forceRootUrl((string) config('app.url'));

        RateLimiter::for('booking-store', fn (Request $request): array => [
            Limit::perMinute(6)->by('user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);
    }
}
