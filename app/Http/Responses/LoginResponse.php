<?php

namespace App\Http\Responses;

use App\Support\SafeIntendedPath;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            SafeIntendedPath::retain($request);

            // The email link opened on a signed-out device leads here first: finish that
            // verification instead of asking the customer to open the email again.
            $link = self::ownVerificationLink(SafeIntendedPath::peek($request), $user);

            if ($link !== null) {
                $request->session()->forget('url.intended');

                return redirect($link);
            }

            return redirect()->route('verification.notice');
        }

        return redirect(SafeIntendedPath::pull($request) ?? Fortify::redirects('login'));
    }

    private static function ownVerificationLink(?string $intended, Authenticatable $user): ?string
    {
        if ($intended === null) {
            return null;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($intended));
        } catch (Throwable) {
            return null;
        }

        return $route->getName() === 'verification.verify'
            && (string) $route->parameter('id') === (string) $user->getAuthIdentifier()
            ? $intended
            : null;
    }
}
