<?php

namespace App\Http\Controllers\Admin;

use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminBookingIndexRequest;
use App\Http\Requests\Admin\UpdateBookingStatusRequest;
use App\Models\Booking;
use App\Queries\AdminBookingIndexQuery;
use App\Support\AdminBookingPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

final class AdminBookingController extends Controller
{
    public function index(
        AdminBookingIndexRequest $request,
        AdminBookingIndexQuery $query,
        AdminBookingPresenter $presenter,
    ): Response {
        $filters = $request->filters();
        $now = CarbonImmutable::now('Asia/Jakarta');
        $paginator = $query->paginate($filters, $now);

        $request->session()->put(
            'admin.bookings.index_url',
            route(
                'admin.bookings.index',
                array_filter($filters, fn ($value): bool => $value !== null && $value !== ''),
                false,
            ),
        );

        return inertia('admin/bookings/Index', [
            'counts' => $query->counts($now),
            'filters' => $filters,
            'bookings' => $presenter->pagination($paginator),
        ]);
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
