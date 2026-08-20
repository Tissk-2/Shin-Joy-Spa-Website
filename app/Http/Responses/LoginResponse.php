<?php

namespace App\Http\Responses;

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
            $request->session()->forget('url.intended');

            return redirect()->route('verification.notice');
        }

        return redirect($this->safeIntendedPath($request) ?? Fortify::redirects('login'));
    }

    private function safeIntendedPath(Request $request): ?string
    {
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '' || str_contains($intended, '\\')) {
            return null;
        }

        if (str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            return $intended;
        }

        $intendedOrigin = $this->origin($intended);
        $applicationOrigin = $this->origin((string) config('app.url'));

        if ($intendedOrigin === null || $applicationOrigin === null || $intendedOrigin !== $applicationOrigin) {
            return null;
        }

        $parts = parse_url($intended);

        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $path.$query.$fragment;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
