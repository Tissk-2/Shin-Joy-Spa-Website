<?php

namespace App\Http\Middleware;

use App\Support\SafeIntendedPath;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as BaseEnsureEmailIsVerified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

/**
 * Laravel's gate, except that a chosen treatment stays remembered while an unverified customer opens
 * other member pages, so the email link still brings them back to it. A newer treatment choice wins.
 * Laravel orders middleware by parent class too, so this keeps the base gate's place in the priority list.
 */
final class EnsureEmailIsVerified extends BaseEnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail() && ! $request->expectsJson()
            && self::isTreatmentChoice(SafeIntendedPath::peek($request))
            && ! self::isTreatmentChoice($request->getRequestUri())) {
            return Redirect::to(URL::route($redirectToRoute ?: 'verification.notice'));
        }

        return parent::handle($request, $next, $redirectToRoute);
    }

    private static function isTreatmentChoice(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        $query = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return parse_url($path, PHP_URL_PATH) === route('booking', absolute: false)
            && is_string($query['service'] ?? null)
            && $query['service'] !== '';
    }
}
