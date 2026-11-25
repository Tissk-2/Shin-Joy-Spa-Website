<?php

use App\Http\Middleware\EnsureEmailIsVerified as RememberTreatmentUntilVerified;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\BookingDraft;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            // Keeps a chosen treatment remembered until the email is verified.
            'verified' => RememberTreatmentUntilVerified::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        // A booking sent after the session ended keeps its choices for after sign-in.
        $middleware->redirectGuestsTo(function (Request $request): string {
            BookingDraft::keep($request);

            return route('login');
        });
        $beforeBindingsAndThrottling = [
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            SubstituteBindings::class,
        ];
        $middleware->prependToPriorityList($beforeBindingsAndThrottling, EnsureEmailIsVerified::class);
        $middleware->prependToPriorityList($beforeBindingsAndThrottling, EnsureUserIsAdmin::class);

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AuthenticateSession::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
