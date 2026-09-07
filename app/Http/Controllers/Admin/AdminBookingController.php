<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

final class AdminBookingController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Booking::class);

        return inertia('admin/bookings/Index');
    }

    public function show(Booking $booking): Response
    {
        Gate::authorize('view', $booking);

        return inertia('admin/bookings/Show');
    }
}
