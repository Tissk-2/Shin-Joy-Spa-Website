<?php

namespace App\Queries;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;

/** Aggregates booking volume, outcomes, popular treatments, peak hours, and estimated value for owners. */
final class AdminReportQuery
{
    public const PERIODS = ['7d', '30d', 'month'];

    private const TOP_PACKAGES_LIMIT = 6;

    /**
     * @return array<string, mixed>
     */
    public function get(?string $requestedPeriod): array
    {
        $period = in_array($requestedPeriod, self::PERIODS, true) ? $requestedPeriod : '30d';
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $from = match ($period) {
            '7d' => $today->subDays(6),
            'month' => $today->startOfMonth(),
            default => $today->subDays(29),
        };
        $fromDate = $from->toDateString();
        $toDate = $today->toDateString();

        // Equal-length preceding window for honest period-over-period deltas.
        $spanDays = $from->diffInDays($today) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $period === 'month'
            ? $today->subMonthNoOverflow()->startOfMonth()
            : $previousTo->subDays($spanDays - 1);
        if ($period === 'month') {
            $previousTo = $previousFrom->addDays(min($spanDays - 1, $previousFrom->daysInMonth - 1));
        }
        $previousFromDate = $previousFrom->toDateString();
        $previousToDate = $previousTo->toDateString();

        $bookings = Booking::query()
            ->select([
                'status',
                'refund_reason',
                'source_channel',
                'appointment_date',
                'appointment_time',
                'package_slug',
                'package_name_id',
                'package_name_en',
                'package_is_vip',
                'treatment_mode',
                'base_duration_minutes',
                'total_price_idr',
            ])
            ->whereDate('appointment_date', '>=', $fromDate)
            ->whereDate('appointment_date', '<=', $toDate)
            ->get();

        $previousBookings = Booking::query()
            ->select(['status', 'total_price_idr'])
            ->whereDate('appointment_date', '>=', $previousFromDate)
            ->whereDate('appointment_date', '<=', $previousToDate)
            ->get();

        $prevCompletedCount = 0;
        $prevLostCount = 0;
        $prevEstimatedCompletedRevenueIdr = 0;
        foreach ($previousBookings as $prev) {
            if ($prev->status === BookingStatus::Completed) {
                $prevCompletedCount++;
                $prevEstimatedCompletedRevenueIdr += (int) $prev->total_price_idr;
            } elseif ($prev->status === BookingStatus::Cancelled || $prev->status === BookingStatus::NoShow) {
                $prevLostCount++;
            }
        }

        $daily = [];
        for ($cursor = $from; $cursor->lessThanOrEqualTo($today); $cursor = $cursor->addDay()) {
            $iso = $cursor->toDateString();
            $daily[$iso] = [
                'date' => $iso,
                'total' => 0,
                'completed' => 0,
                'active' => 0,
                'lost' => 0,
            ];
        }

        $hours = [];
        for ($hour = 9; $hour <= 20; $hour++) {
            $label = sprintf('%02d:00', $hour);
            $hours[$label] = ['hour' => $label, 'count' => 0];
        }

        $channels = [
            'website' => 0,
            'walk_in' => 0,
            'phone' => 0,
            'whatsapp' => 0,
        ];

        $completedCount = 0;
        $confirmedCount = 0;
        $pendingCount = 0;
        $awaitingPaymentCount = 0;
        $noShowCount = 0;
        $expiredCount = 0;
        $slotFullCount = 0;
        $manualCancelledCount = 0;
        $estimatedCompletedRevenueIdr = 0;
        $confirmedPipelineIdr = 0;

        /** @var array<string, array{slug: string, nameId: string, nameEn: string, isVip: bool, treatmentMode: string, durationMinutes: int, count: int, estimatedValueIdr: int}> $packages */
        $packages = [];

        foreach ($bookings as $booking) {
            $status = $booking->status;
            $dateKey = $booking->appointment_date->toDateString();
            $isLost = $status === BookingStatus::Cancelled || $status === BookingStatus::NoShow;

            if (isset($daily[$dateKey])) {
                $daily[$dateKey]['total']++;
                if ($status === BookingStatus::Completed) {
                    $daily[$dateKey]['completed']++;
                } elseif ($isLost) {
                    $daily[$dateKey]['lost']++;
                } else {
                    $daily[$dateKey]['active']++;
                }
            }

            $channel = (string) ($booking->source_channel ?: 'website');
            if (array_key_exists($channel, $channels)) {
                $channels[$channel]++;
            }

            if ($status === BookingStatus::Completed) {
                $completedCount++;
                $estimatedCompletedRevenueIdr += (int) $booking->total_price_idr;
            } elseif ($status === BookingStatus::Confirmed) {
                $confirmedCount++;
                $confirmedPipelineIdr += (int) $booking->total_price_idr;
            } elseif ($status === BookingStatus::Pending) {
                $pendingCount++;
            } elseif ($status === BookingStatus::AwaitingPayment) {
                $awaitingPaymentCount++;
            } elseif ($status === BookingStatus::NoShow) {
                $noShowCount++;
            } elseif ($status === BookingStatus::Cancelled) {
                if ($booking->refund_reason === 'expired') {
                    $expiredCount++;
                } elseif ($booking->refund_reason === 'slot_full') {
                    $slotFullCount++;
                } else {
                    $manualCancelledCount++;
                }
            }

            if (! $isLost) {
                $hourKey = substr((string) $booking->appointment_time, 0, 2).':00';
                if (isset($hours[$hourKey])) {
                    $hours[$hourKey]['count']++;
                }

                $packageKey = implode('|', [
                    (string) $booking->package_slug,
                    (string) $booking->treatment_mode,
                    (int) $booking->base_duration_minutes,
                ]);
                if (! isset($packages[$packageKey])) {
                    $packages[$packageKey] = [
                        'slug' => (string) $booking->package_slug,
                        'nameId' => (string) $booking->package_name_id,
                        'nameEn' => (string) $booking->package_name_en,
                        'isVip' => (bool) $booking->package_is_vip,
                        'treatmentMode' => (string) $booking->treatment_mode,
                        'durationMinutes' => (int) $booking->base_duration_minutes,
                        'count' => 0,
                        'estimatedValueIdr' => 0,
                    ];
                }
                $packages[$packageKey]['count']++;
                $packages[$packageKey]['estimatedValueIdr'] += (int) $booking->total_price_idr;
            }
        }

        usort($packages, static function (array $a, array $b): int {
            return [$b['count'], $b['estimatedValueIdr'], $a['slug'], $a['durationMinutes']]
                <=> [$a['count'], $a['estimatedValueIdr'], $b['slug'], $b['durationMinutes']];
        });

        $cancelledCount = $manualCancelledCount + $expiredCount + $slotFullCount;
        $decidedCount = $completedCount + $noShowCount + $cancelledCount;
        $completionRate = $decidedCount > 0 ? (int) round(($completedCount / $decidedCount) * 100) : null;

        return [
            'period' => $period,
            'range' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'previousRange' => [
                'from' => $previousFromDate,
                'to' => $previousToDate,
            ],
            'summary' => [
                'totalBookings' => $bookings->count(),
                'completedCount' => $completedCount,
                'confirmedCount' => $confirmedCount,
                'pendingCount' => $pendingCount,
                'awaitingPaymentCount' => $awaitingPaymentCount,
                'cancelledCount' => $cancelledCount,
                'noShowCount' => $noShowCount,
                'expiredCount' => $expiredCount,
                'slotFullCount' => $slotFullCount,
                'manualCancelledCount' => $manualCancelledCount,
                'estimatedCompletedRevenueIdr' => $estimatedCompletedRevenueIdr,
                'confirmedPipelineIdr' => $confirmedPipelineIdr,
                'completionRate' => $completionRate,
            ],
            'previousSummary' => [
                'totalBookings' => $previousBookings->count(),
                'completedCount' => $prevCompletedCount,
                'lostCount' => $prevLostCount,
                'estimatedCompletedRevenueIdr' => $prevEstimatedCompletedRevenueIdr,
            ],
            'dailyTrend' => array_values($daily),
            'topPackages' => array_slice($packages, 0, self::TOP_PACKAGES_LIMIT),
            'peakHours' => array_values($hours),
            'channels' => [
                ['key' => 'website', 'count' => $channels['website']],
                ['key' => 'walk_in', 'count' => $channels['walk_in']],
                ['key' => 'phone', 'count' => $channels['phone']],
                ['key' => 'whatsapp', 'count' => $channels['whatsapp']],
            ],
        ];
    }
}
