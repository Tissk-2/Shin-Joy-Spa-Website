<?php

namespace App\Queries;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\ServiceVariant;
use App\Support\Rooms\RoomBlockInterval;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class RoomAssignmentAudit
{
    private const HOLDS_ROOM = ['awaiting_payment', 'confirmed'];

    /**
     * Confirmed or awaiting-payment bookings that have not finished and hold no room.
     *
     * @return Collection<int, Booking>
     */
    public function unassigned(CarbonImmutable $now): Collection
    {
        $nowUtc = $now->utc()->format('Y-m-d H:i:s');

        return Booking::query()
            ->whereIn('status', self::HOLDS_ROOM)
            ->whereNotNull('starts_at')
            // A treatment already under way still needs its room until it ends.
            ->where('treatment_ends_at', '>', $nowUtc)
            ->whereNotIn('id', RoomReservation::query()->whereNull('released_at')->select('booking_id'))
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->orderBy('id')
            ->get();
    }

    /** @return list<string> */
    public function pendingIssues(CarbonImmutable $now): array
    {
        $nowUtc = $now->utc()->format('Y-m-d H:i:s');
        $issues = [];

        $bookings = Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->whereNotNull('starts_at')
            ->where('treatment_ends_at', '>', $nowUtc)
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->orderBy('id')
            ->get();

        foreach ($bookings as $booking) {
            $rooms = $this->candidates($booking);
            if ($rooms === null) {
                $issues[] = "[pending] Booking {$this->when($booking)} has no catalog treatment or an invalid group or duration; approval will be refused. Decline it and record a new booking.";
            } elseif ($rooms === []) {
                $issues[] = "[pending] Booking {$this->when($booking)} has no offered treatment or active compatible room for ".self::guests((int) $booking->guests).'; approval will be refused.';
            }
        }

        return $issues;
    }

    /** @return list<string> */
    public function conflicts(CarbonImmutable $now): array
    {
        $nowUtc = $now->utc()->format('Y-m-d H:i:s');
        $interval = new RoomBlockInterval;
        $items = [];

        $reservations = RoomReservation::query()->with('booking')->whereNull('released_at')
            ->where('ends_at', '>', $nowUtc)
            ->orderBy('room_id')->orderBy('starts_at')->orderBy('id')->get();
        foreach ($reservations as $reservation) {
            $items[] = [
                'room_id' => $reservation->room_id,
                'kind' => 'reservation',
                'id' => $reservation->id,
                'start' => $interval->instant($reservation, 'starts_at'),
                'end' => $interval->instant($reservation, 'ends_at'),
                'label' => 'booking '.$reservation->booking->reference,
            ];
        }

        $blocks = RoomBlock::query()->where('ends_at', '>', $nowUtc)
            ->orderBy('room_id')->orderBy('starts_at')->orderBy('id')->get();
        $terminal = $interval->loadTerminations($blocks->modelKeys());
        foreach ($blocks as $block) {
            $start = $interval->instant($block, 'starts_at');
            $end = $interval->effectiveEnd($block, $terminal[$block->id] ?? null);
            if ($end <= $start || $end <= $now) {
                continue;
            }
            $items[] = [
                'room_id' => $block->room_id,
                'kind' => 'block',
                'id' => $block->id,
                'start' => $start,
                'end' => $end,
                'label' => 'maintenance block #'.$block->id,
            ];
        }

        $grouped = collect($items)->groupBy('room_id');
        $rooms = Room::query()->whereIn('id', $grouped->keys())->orderBy('code')->orderBy('id')->get();
        $issues = [];
        foreach ($rooms as $room) {
            $roomItems = $grouped->get($room->id, collect())->values()->all();
            usort($roomItems, fn (array $a, array $b): int => $a['start'] <=> $b['start']
                ?: ($a['kind'] === 'reservation' ? 0 : 1) <=> ($b['kind'] === 'reservation' ? 0 : 1)
                ?: $a['id'] <=> $b['id']);

            $open = null;
            foreach ($roomItems as $item) {
                if ($open !== null && $item['start'] < $open['end']) {
                    $issues[] = "[conflicts] Room {$room->code}: {$open['label']} overlaps {$item['label']} at {$item['start']->setTimezone('Asia/Jakarta')->format('Y-m-d H:i')} WIB.";
                }
                if ($open === null || $item['end'] > $open['end']) {
                    $open = $item;
                }
            }
        }

        return $issues;
    }

    /** Compatible rooms for this booking, from forInterval(); null when it cannot be checked. */
    public function candidates(Booking $booking): ?array
    {
        if ($booking->service_variant_id === null) {
            return null;
        }
        $variant = ServiceVariant::query()->find($booking->service_variant_id);
        if ($variant === null) {
            return null;
        }

        try {
            return (new RoomAvailabilityQuery)->forInterval(
                $variant,
                (int) $booking->guests,
                (new RoomBlockInterval)->instant($booking, 'starts_at'),
                (int) $booking->total_duration_minutes,
                $booking->requested_room_type,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function guests(int $count): string
    {
        return $count.' guest'.($count === 1 ? '' : 's');
    }

    private function when(Booking $booking): string
    {
        return "{$booking->reference} on {$booking->appointment_date->format('Y-m-d')} {$booking->appointment_time} WIB";
    }
}
