<?php

namespace App\Providers;

use App\Http\Responses\NeutralPasswordResetLinkResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
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
        // The E2E runner passes its own hot file so browser tests never read or
        // clobber the developer's public/hot. env() is used because this is a
        // test-runner process variable, not app configuration; the redirect is
        // testing-only because production can never be redirected.
        if ($this->app->environment('testing')) {
            $hotFile = env('LARAVEL_VITE_HOT_FILE');

            if (is_string($hotFile) && $hotFile !== '') {
                Vite::useHotFile($hotFile);
            }
        }

        URL::forceRootUrl((string) config('app.url'));
        // Without this the request's own scheme wins, so a reset requested over
        // plain HTTP would mail an http:// link.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Escapes Markdown in mail echoes, so a customer name such as
        // "[Verify here](https://…)" stays text instead of becoming a link.
        Markdown::withSecuredEncoding();

        RateLimiter::for('booking-store', fn (Request $request): array => [
            Limit::perMinute(6)->by('user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('booking-availability', fn (Request $request): array => [
            Limit::perMinute(60)->by('availability-user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(120)->by('availability-ip:'.$request->ip()),
        ]);

        RateLimiter::for('profile-destroy', fn (Request $request): array => [
            Limit::perMinute(5)->by('user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-update', fn (Request $request): array => [
            Limit::perMinute(5)->by('user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('admin-booking-status', fn (Request $request): array => [
            Limit::perMinute(30)->by('admin:'.$request->user()?->getAuthIdentifier().'|ip:'.$request->ip()),
            Limit::perHour(100)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('catalog-write', fn (Request $request): array => [
            Limit::perMinute(20)->by('admin:'.$request->user()?->getAuthIdentifier().'|ip:'.$request->ip()),
            Limit::perHour(100)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('room-write', fn (Request $request): array => [
            Limit::perMinute(20)->by('room-user:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour(100)->by('room-ip:'.$request->ip()),
        ]);
    }
}
