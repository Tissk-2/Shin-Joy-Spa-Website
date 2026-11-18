<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Service;
use App\Queries\PublicCatalogQuery;
use App\Support\AdminBookingPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/** The Today page: requests waiting for an answer, then the day's appointments. */
final class AdminDashboardController extends Controller
{
    private const QUEUE_SIZE = 8;

    public function __invoke(Request $request, AdminBookingPresenter $presenter, PublicCatalogQuery $catalog): Response
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $pending = Booking::query()->where('status', BookingStatus::Pending->value);

        $queue = (clone $pending)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::QUEUE_SIZE)
            ->get()
            ->map(fn (Booking $booking): array => $presenter->summary($booking))
            ->all();

        $todayBookings = Booking::query()
            ->whereDate('appointment_date', $today)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->orderBy('appointment_time')
            ->orderBy('id')
            ->get()
            ->map(fn (Booking $booking): array => $presenter->summary($booking))
            ->all();

        return inertia('admin/Dashboard', [
            'queue' => $queue,
            'queueTotal' => (clone $pending)->count(),
            'today' => $todayBookings,
            // Unverified pricing books one guest only, and only the owner can verify it.
            'pricingUnverified' => $request->user()?->isOwner()
                ? $catalog->categories()->flatMap->services
                    ->reject(fn (Service $service): bool => $service->hasVerifiedPricing())
                    ->count()
                : null,
            'roomSchedulingEnabled' => (bool) config('booking.scheduling_enabled'),
            'canCreate' => Gate::allows('create', Booking::class),
        ]);
    }
}
