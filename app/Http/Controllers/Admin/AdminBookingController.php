<?php

namespace App\Http\Controllers\Admin;

use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBookingStatusRequest;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
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

    public function updateStatus(
        UpdateBookingStatusRequest $request,
        Booking $booking,
        TransitionBookingStatus $transition,
    ): RedirectResponse {
        $validated = $request->validated();

        $transition(
            $request->user(),
            $booking,
            BookingStatus::from($validated['currentStatus']),
            BookingStatus::from($validated['nextStatus']),
            $validated['reason'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        return to_route('admin.bookings.show', $booking, 303)
            ->with('status', __('admin.status.updated'));
    }
}
