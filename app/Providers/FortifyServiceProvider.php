<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\RejectArrayIdentityInput;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Support\BookingAuthContextResolver;
use App\Support\CustomerIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            'bookingContext' => app(BookingAuthContextResolver::class)->resolve($request),
        ]));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'bookingContext' => app(BookingAuthContextResolver::class)->resolve($request),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', CustomerIdentity::email((string) $request->input('email')))
                ->first();

            return $user && Hash::check((string) $request->input('password'), $user->password)
                ? $user
                : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $key = (is_string($email) ? CustomerIdentity::email($email) : '').'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->ip()));

        RateLimiter::for('registration', function (Request $request): array {
            $email = $request->input('email');
            $key = (is_string($email) ? CustomerIdentity::email($email) : '').'|'.$request->ip();

            return [
                Limit::perMinute(3)->by($key),
                Limit::perHour(20)->by($request->ip()),
            ];
        });

        Route::matched(function (RouteMatched $event): void {
            if ($event->route->getName() === 'password.email') {
                $event->route->middleware('throttle:password-reset');
            }

            if ($event->route->getName() === 'register.store') {
                $event->route->middleware('throttle:registration');
                $event->route->middleware(RejectArrayIdentityInput::class);
            }

            if ($event->route->getName() === 'password.update') {
                $event->route->middleware(RejectArrayIdentityInput::class);
            }

            // Unused by the app, but Fortify always registers it. It checks the
            // password, so it shares the settings password-guessing budget.
            if ($event->route->getName() === 'password.confirm.store') {
                $event->route->middleware('throttle:password-update');
            }
        });
    }
}
