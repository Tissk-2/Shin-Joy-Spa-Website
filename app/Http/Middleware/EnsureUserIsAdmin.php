<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $currentUser = $request->user()?->fresh();

        abort_unless($currentUser?->isAdmin(), 403);

        return $next($request);
    }
}
