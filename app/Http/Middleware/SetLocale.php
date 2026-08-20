<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionLocale = $request->session()->get('locale', 'id');
        $locale = in_array($sessionLocale, ['id', 'en'], true) ? $sessionLocale : 'id';

        App::setLocale($locale);

        return $next($request);
    }
}
