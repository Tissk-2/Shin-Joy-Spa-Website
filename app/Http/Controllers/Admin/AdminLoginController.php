<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AdminLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (! $request->user()) {
            $request->session()->put('url.intended', '/admin/bookings');

            return to_route('login');
        }

        return to_route('admin.bookings.index');
    }
}
