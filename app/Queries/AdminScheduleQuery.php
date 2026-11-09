<?php

namespace App\Queries;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Support\Rooms\RoomBlockInterval;
use Carbon\CarbonImmutable;

final class AdminScheduleQuery
{
    private const WITHOUT_ROOM = ['pending', 'awaiting_payment', 'confirmed'];

    public function day(CarbonImmutable $day, CarbonImmutable $today): array
    {
        $interval = new RoomBlockInterval;
        $start = $day->utc();
        $end = $day->addDay()->utc();

        $reservations = RoomReservation::query()->with('booking')->whereNull('released_at')
            ->where('ends_at', '>=', $start->startOfSecond()->format('Y-m-d H:i:s'))
            ->where('starts_at', '<=', $end->endOfSecond()->format('Y-m-d H:i:s.u'))
            ->orderBy('starts_at')->orderBy('id')->get()
            ->filter(fn (RoomReservation $reservation) => $interval->overlaps($reservation, null, $start, $end))
            ->groupBy('room_id');

        $candidates = RoomBlock::query()
            ->where('ends_at', '>=', $start->startOfSecond()->format('Y-m-d H:i:s'))
            ->where('starts_at', '<=', $end->endOfSecond()->format('Y-m-d H:i:s.u'))
            ->orderBy('starts_at')->orderBy('id')->get();
        $terminal = $interval->loadTerminations($candidates->modelKeys());
        $blocks = $candidates->filter(fn (RoomBlock $block) => $interval->overlaps($block, $terminal[$block->id] ?? null, $start, $end))->groupBy('room_id');

        $rooms = Room::query()->orderBy('code')->orderBy('id')
            ->get(['id', 'code', 'name', 'capacity', 'is_active'])
            ->map(function (Room $room) use ($blocks, $reservations, $terminal, $interval): array {
                $blockItems = $blocks->get($room->id, collect())->map(fn (RoomBlock $block) => [
                    'kind' => 'block',
                    'id' => $block->id,
                    'reason' => $block->reason,
                    'startsAt' => $interval->instant($block, 'starts_at')->toIso8601ZuluString(),
                    'endsAt' => $interval->effectiveEnd($block, $terminal[$block->id] ?? null)->toIso8601ZuluString(),
                ])->values()->all();
                $reservationItems = $reservations->get($room->id, collect())->map(function (RoomReservation $reservation) use ($interval): array {
                    $booking = $reservation->booking;

                    return [
                        'kind' => 'booking',
                        'reference' => $booking->reference,
                        'status' => $booking->status->value,
                        'customerName' => $booking->customer_name,
                        'guests' => $booking->guests,
                        'packageNameId' => $booking->package_name_id,
                        'packageNameEn' => $booking->package_name_en,
                        'startsAt' => $interval->instant($reservation, 'starts_at')->toIso8601ZuluString(),
                        'treatmentEndsAt' => $interval->instant($reservation, 'treatment_ends_at')->toIso8601ZuluString(),
                        'endsAt' => $interval->instant($reservation, 'ends_at')->toIso8601ZuluString(),
                    ];
                })->values()->all();

                return [
                    'id' => $room->id,
                    'code' => $room->code,
                    'name' => $room->name,
                    'capacity' => $room->capacity,
                    'isActive' => $room->is_active,
                    'items' => collect([...$blockItems, ...$reservationItems])->sortBy('startsAt')->values()->all(),
                ];
            })->all();

        $unassigned = Booking::query()
            ->whereIn('status', self::WITHOUT_ROOM)
            ->whereDate('appointment_date', $day->toDateString())
            ->whereNotIn('id', RoomReservation::query()->whereNull('released_at')->select('booking_id'))
            ->orderBy('appointment_time')->orderBy('id')->get()
            ->map(fn (Booking $booking): array => [
                'reference' => $booking->reference,
                'status' => $booking->status->value,
                'customerName' => $booking->customer_name,
                'guests' => $booking->guests,
                'packageNameId' => $booking->package_name_id,
                'packageNameEn' => $booking->package_name_en,
                'time' => $booking->appointment_time,
                'durationMinutes' => $booking->total_duration_minutes,
            ])->all();

        return [
            'date' => $day->toDateString(),
            'previousDate' => $day->subDay()->toDateString(),
            'nextDate' => $day->addDay()->toDateString(),
            'today' => $today->toDateString(),
            'schedulingEnabled' => config('booking.scheduling_enabled') === true,
            'rooms' => $rooms,
            'unassigned' => $unassigned,
        ];
    }
}
