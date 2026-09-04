<?php

namespace App\Support;

use Illuminate\Http\Request;

class SafeIntendedPath
{
    public static function peek(Request $request): ?string
    {
        return self::validate($request->session()->get('url.intended'));
    }

    public static function pull(Request $request): ?string
    {
        return self::validate($request->session()->pull('url.intended'));
    }

    public static function retain(Request $request): void
    {
        if ($request->session()->has('url.intended') && self::peek($request) === null) {
            $request->session()->forget('url.intended');
        }
    }

    private static function validate(mixed $intended): ?string
    {
        if (! is_string($intended) || $intended === '' || str_contains($intended, '\\')) {
            return null;
        }

        if (str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            return $intended;
        }

        $parts = parse_url($intended);

        if ($parts === false || isset($parts['user'], $parts['pass']) || self::origin($intended) !== self::origin((string) config('app.url'))) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $path.$query.$fragment;
    }

    private static function origin(string $url): ?string
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
