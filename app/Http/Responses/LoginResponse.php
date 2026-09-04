<?php

namespace App\Http\Responses;

use App\Support\SafeIntendedPath;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

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

            return redirect()->route('verification.notice');
        }

        return redirect(SafeIntendedPath::pull($request) ?? Fortify::redirects('login'));
    }
}
