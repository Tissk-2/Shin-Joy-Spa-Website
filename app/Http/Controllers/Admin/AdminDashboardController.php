<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use Carbon\CarbonImmutable;
use Inertia\Response;

final class AdminDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $now = CarbonImmutable::now('Asia/Jakarta');
        $today = $now->startOfDay();
        $todayIso = $today->toDateString();
        $currentTime = $now->format('H:i');
        $weekIso = $today->addDays(7)->toDateString();
        $horizonIso = $today->addDays(14)->toDateString();

        $activeServices = Service::query()
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->count();

        $categoryCount = ServiceCategory::query()
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->count();

        $bookingsTotal = Booking::query()->count();
        $bookingsPending = Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->count();
        $bookingsToday = Booking::query()
            ->whereDate('appointment_date', $todayIso)
            ->count();
        $bookingsWeek = Booking::query()
            ->whereBetween('appointment_date', [$todayIso, $weekIso])
            ->count();

        $settled = Booking::query()->whereIn('status', [
            BookingStatus::Confirmed->value,
            BookingStatus::Completed->value,
        ]);

        $revenue = (int) (clone $settled)->sum('total_price_idr');
        $revenueBookings = (clone $settled)->count();

        $upcoming = Booking::query()
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->whereDate('appointment_date', '>=', $todayIso)
            ->whereDate('appointment_date', '<=', $horizonIso)
            ->where(function ($query) use ($todayIso, $currentTime): void {
                $query->whereDate('appointment_date', '>', $todayIso)
                    ->orWhere(function ($todayQuery) use ($todayIso, $currentTime): void {
                        $todayQuery->whereDate('appointment_date', $todayIso)
                            ->where('appointment_time', '>=', $currentTime);
                    });
            })
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->orderBy('id')
            ->limit(6)
            ->get()
            ->map(fn (Booking $booking): array => $this->present($booking))
            ->all();

        $recent = Booking::query()
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Booking $booking): array => $this->present($booking))
            ->all();

        $categoryStats = ServiceCategory::query()
            ->active()
            ->withCount([
                'services as servicesCount' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('published_at'),
            ])
            ->get()
            ->map(fn (ServiceCategory $category): array => [
                'slug' => $category->slug,
                'nameId' => $category->name_id,
                'nameEn' => $category->name_en,
                'count' => (int) $category->servicesCount,
            ])
            ->all();

        return inertia('admin/Dashboard', [
            'stats' => [
                'services' => $activeServices,
                'categories' => $categoryCount,
                'bookingsTotal' => $bookingsTotal,
                'bookingsPending' => $bookingsPending,
                'bookingsToday' => $bookingsToday,
                'bookingsWeek' => $bookingsWeek,
                'revenue' => $revenue,
                'revenueBookings' => $revenueBookings,
            ],
            'upcoming' => $upcoming,
            'recent' => $recent,
            'categoryStats' => $categoryStats,
        ]);
    }

    /**
     * @return array{reference: string, customerName: string, packageNameId: string, packageNameEn: string, date: string, time: string, status: string, totalPrice: int}
     */
    private function present(Booking $booking): array
    {
        return [
            'reference' => $booking->reference,
            'customerName' => $booking->customer_name,
            'packageNameId' => $booking->package_name_id,
            'packageNameEn' => $booking->package_name_en,
            'date' => (string) $booking->appointment_date?->toDateString(),
            'time' => $booking->appointment_time,
            'status' => $booking->status->value,
            'totalPrice' => (int) $booking->total_price_idr,
        ];
    }
}
