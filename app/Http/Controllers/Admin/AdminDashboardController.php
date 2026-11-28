<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Service;
use App\Queries\AdminRoomIndexQuery;
use App\Queries\PublicCatalogQuery;
use App\Support\AdminBookingPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/** The Today page: requests waiting for an answer, live room state, and the day's appointments. */
final class AdminDashboardController extends Controller
{
    private const QUEUE_SIZE = 8;

    private const NEXT_APPOINTMENTS_SIZE = 3;

    public function __invoke(
        Request $request,
        AdminBookingPresenter $presenter,
        PublicCatalogQuery $catalog,
        AdminRoomIndexQuery $roomIndex,
    ): Response {
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

        // Only look beyond today when the day's schedule is empty, so quiet days still show who comes next.
        $nextAppointments = $todayBookings === []
            ? Booking::query()
                ->whereDate('appointment_date', '>', $today)
                ->where('status', '!=', BookingStatus::Cancelled->value)
                ->orderBy('appointment_date')
                ->orderBy('appointment_time')
                ->orderBy('id')
                ->limit(self::NEXT_APPOINTMENTS_SIZE)
                ->get()
                ->map(fn (Booking $booking): array => $presenter->summary($booking))
                ->all()
            : [];

        $roomSchedulingEnabled = (bool) config('booking.scheduling_enabled');

        return inertia('admin/Dashboard', [
            'queue' => $queue,
            'queueTotal' => (clone $pending)->count(),
            'today' => $todayBookings,
            'nextAppointments' => $nextAppointments,
            'roomSummary' => $roomSchedulingEnabled ? $this->roomSummary($roomIndex) : null,
            // Unverified pricing books one guest only, and only the owner can verify it.
            'pricingUnverified' => $request->user()?->isOwner()
                ? $catalog->categories()->flatMap->services
                    ->reject(fn (Service $service): bool => $service->hasVerifiedPricing())
                    ->count()
                : null,
            'roomSchedulingEnabled' => $roomSchedulingEnabled,
            'canCreate' => Gate::allows('create', Booking::class),
        ]);
    }

    /**
     * @return array{totalActive: int, occupied: int, cleaning: int, blocked: int, free: int}|null
     */
    private function roomSummary(AdminRoomIndexQuery $roomIndex): ?array
    {
        $inventory = $roomIndex->get();
        if (($inventory['state'] ?? null) !== 'ready') {
            return null;
        }

        $totalActive = 0;
        $occupied = 0;
        $cleaning = 0;
        $blocked = 0;
        $free = 0;

        foreach ($inventory['rooms'] ?? [] as $room) {
            if (($room['isActive'] ?? false) !== true) {
                continue;
            }
            $totalActive++;
            if (($room['currentBlockCount'] ?? 0) > 0) {
                $blocked++;
            } elseif (($room['occupancy']['state'] ?? null) === 'guest') {
                $occupied++;
            } elseif (($room['occupancy']['state'] ?? null) === 'cleaning') {
                $cleaning++;
            } else {
                $free++;
            }
        }

        if ($totalActive === 0) {
            return null;
        }

        return [
            'totalActive' => $totalActive,
            'occupied' => $occupied,
            'cleaning' => $cleaning,
            'blocked' => $blocked,
            'free' => $free,
        ];
    }
}
