<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateStaffBooking;
use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminBookingIndexRequest;
use App\Http\Requests\Admin\StoreStaffBookingRequest;
use App\Http\Requests\Admin\UpdateBookingStatusRequest;
use App\Models\Booking;
use App\Queries\AdminBookingIndexQuery;
use App\Queries\PublicCatalogQuery;
use App\Support\ActionContext;
use App\Support\AdminBookingPresenter;
use App\Support\ServiceCatalogPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
            'canCreate' => Gate::allows('create', Booking::class),
        ]);
    }

    public function create(PublicCatalogQuery $catalog, ServiceCatalogPresenter $presenter): Response
    {
        Gate::authorize('create', Booking::class);
        $services = $catalog->categories()->flatMap->services;

        return inertia('admin/bookings/Create', [
            'packages' => $services->map($presenter->package(...))->values(),
            'submissionKey' => (string) Str::uuid(),
            'availabilityEnabled' => config('booking.scheduling_enabled') === true,
            'sources' => StoreStaffBookingRequest::SOURCES,
        ]);
    }

    public function show(
        Request $request,
        Booking $booking,
        AdminBookingPresenter $presenter,
    ): Response {
        Gate::authorize('view', $booking);
        $booking->load('statusChanges');

        return inertia('admin/bookings/Show', [
            'booking' => $presenter->detail($booking),
            'backUrl' => $request->session()->get('admin.bookings.index_url', '/admin/bookings'),
        ]);
    }

    public function store(StoreStaffBookingRequest $request, CreateStaffBooking $create): RedirectResponse
    {
        $validated = $request->validated();
        $booking = $create(
            $request->user(),
            $request->validatedStaffBookingData(),
            (bool) $validated['approve'],
            ActionContext::dashboard($request),
            (bool) ($validated['operationalConfirmed'] ?? false),
        );

        return to_route('admin.bookings.show', $booking, 303)->with('status', __('admin.manual.created'));
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
            (bool) ($validated['operationalConfirmed'] ?? false),
        );

        return to_route('admin.bookings.show', $booking, 303)
            ->with('status', __('admin.status.updated'));
    }
}
