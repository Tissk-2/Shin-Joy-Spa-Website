<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomReservation;
use App\Queries\RoomAssignmentAudit;
use App\Support\Bookings\BookingMutation;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Scheduling\RoomAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ReconcileRooms extends Command
{
    /** @var string */
    protected $signature = 'bookings:reconcile-rooms
        {--report : List bookings that need a room (default; read-only)}
        {--booking= : Booking reference to assign}
        {--room= : Room code to assign}
        {--apply : Write the assignment; without it the check is a read-only preview}';

    /** @var string */
    protected $description = 'Report bookings that need a room, or check and apply one room assignment';

    public function handle(RoomAssignmentAudit $audit): int
    {
        // An empty "--booking=" is no reference at all.
        $reference = $this->option('booking') ?: null;
        $code = $this->option('room') ?: null;
        $apply = (bool) $this->option('apply');

        if ($this->option('report') && ($reference !== null || $code !== null || $apply)) {
            // --report promises read-only output, so it must never fall through to an assignment.
            $this->line('--report cannot be combined with --booking, --room or --apply.');

            return self::FAILURE;
        }

        if ($reference === null && $code === null && ! $apply) {
            return $this->report($audit);
        }

        if ($reference === null || $code === null) {
            $this->line('Pass both --booking=REFERENCE and --room=CODE.');

            return self::FAILURE;
        }

        $booking = Booking::query()->where('reference', $reference)->first();
        if ($booking === null) {
            $this->line("Booking {$reference} was not found.");

            return self::FAILURE;
        }

        $room = Room::query()->where('code', $code)->first();
        if ($room === null) {
            $this->line("Room {$code} was not found.");

            return self::FAILURE;
        }

        $existing = RoomReservation::query()->with('room')->where('booking_id', $booking->id)->first();
        if ($existing !== null) {
            if ($existing->released_at !== null) {
                $this->line("Not assigned: booking {$booking->reference}'s earlier room reservation was released and cannot be reopened. Cancel it and record a new booking.");

                return self::FAILURE;
            }
            if ((int) $existing->room_id === (int) $room->id) {
                $this->line("Booking {$booking->reference} already holds room {$room->code}. Nothing was changed.");

                return self::SUCCESS;
            }
            $this->line("Not assigned: booking {$booking->reference} already holds room {$existing->room->code}. Nothing was changed.");

            return self::FAILURE;
        }

        $problem = $this->problem($booking, $room, $audit);
        if ($problem !== null) {
            $this->line("Not assigned: {$problem}");

            return self::FAILURE;
        }

        $when = $this->when($booking);
        if (! $apply) {
            $this->line("Room {$room->code} passes the checks for booking {$when}. Rerun with --apply to assign it; --apply checks everything again under the schedule lock.");

            return self::SUCCESS;
        }

        try {
            $reservation = (new BookingMutation)->run($booking->id,
                fn (Booking $locked) => (new RoomAllocator)->allocateLocked($locked, $room->id));
        } catch (ValidationException $error) {
            $this->line('Not assigned: '.collect($error->errors())->flatten()->first().' Nothing was changed.');

            return self::FAILURE;
        }

        if (! $reservation->wasRecentlyCreated) {
            // Another run assigned a room between the pre-checks and the lock; the allocator returned that reservation.
            if ((int) $reservation->room_id === (int) $room->id) {
                $this->line("Booking {$booking->reference} already holds room {$room->code}. Nothing was changed.");

                return self::SUCCESS;
            }
            $this->line("Not assigned: booking {$booking->reference} already holds room {$reservation->room->code}. Nothing was changed.");

            return self::FAILURE;
        }

        Log::info('Room assigned by reconciliation.', [
            'booking' => $booking->reference,
            'room' => $room->code,
            'reservation' => $reservation->id,
        ]);

        $this->line("Assigned room {$room->code} to booking {$when}. Status and price are unchanged.");

        return self::SUCCESS;
    }

    private function report(RoomAssignmentAudit $audit): int
    {
        $now = CarbonImmutable::now('UTC');
        $this->line('Room reconciliation report (read-only).');

        $bookings = $audit->unassigned($now);
        $this->line('Bookings that need a room: '.count($bookings));
        foreach ($bookings as $booking) {
            $when = $this->when($booking);
            if ($booking->status->value === 'awaiting_payment') {
                $this->line("- Booking {$when} is awaiting payment; online bookings get a room after payment, which is not available yet.");

                continue;
            }
            $rooms = $audit->candidates($booking);
            $guests = RoomAssignmentAudit::guests((int) $booking->guests);
            // Same causes, same words as the readiness [pending] lines: null is a missing treatment or rejected input,
            // [] is a retired treatment or no room that fits.
            if ($rooms === null) {
                $this->line("- Booking {$when} has no catalog treatment or an invalid group or duration. Cancel it and record a new booking.");
            } elseif ($rooms === []) {
                $this->line("- Booking {$when}, {$guests}: no offered treatment or active compatible room. Cancel it and record a new booking.");
            } else {
                $freeCodes = array_values(array_map(fn ($room) => $room['code'],
                    array_filter($rooms, fn ($room) => $room['available'] === true)));
                $busyCodes = array_values(array_map(fn ($room) => $room['code'],
                    array_filter($rooms, fn ($room) => $room['available'] === false)));
                $free = $freeCodes === [] ? 'none' : implode(', ', $freeCodes);
                $busy = $busyCodes === [] ? 'none' : implode(', ', $busyCodes);
                $this->line("- Booking {$when}, {$guests}: free rooms {$free}; busy rooms {$busy}.");
            }
        }

        $conflicts = $audit->conflicts($now);
        $this->line('Conflicts: '.count($conflicts));
        foreach ($conflicts as $conflict) {
            $this->line('- '.$conflict);
        }

        $this->line('To assign one, rerun with --booking=REFERENCE --room=CODE to check it, then add --apply. This report changes nothing.');

        return $bookings->isEmpty() && $conflicts === [] ? self::SUCCESS : self::FAILURE;
    }

    private function problem(Booking $booking, Room $room, RoomAssignmentAudit $audit): ?string
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            if ($booking->status === BookingStatus::AwaitingPayment) {
                return "booking {$booking->reference} is awaiting payment; online bookings get a room after payment, which is not available yet.";
            }

            return "booking {$booking->reference} is ".str_replace('_', ' ', $booking->status->value).'; only confirmed bookings can be assigned a room here.';
        }

        if ($booking->starts_at === null || $booking->treatment_ends_at === null) {
            return "booking {$booking->reference} has no valid start time.";
        }
        if ((new RoomBlockInterval)->instant($booking, 'treatment_ends_at') <= CarbonImmutable::now('UTC')) {
            return "booking {$booking->reference} has already finished.";
        }

        $rooms = $audit->candidates($booking);
        if ($rooms === null) {
            return "booking {$booking->reference} has no catalog treatment or an invalid group or duration. Cancel it and record a new booking.";
        }

        $match = collect($rooms)->firstWhere('code', $room->code);
        if ($match === null) {
            if (! $room->is_active) {
                return "room {$room->code} is inactive.";
            }
            $attached = DB::table('room_service_variant')->where('room_id', $room->id)
                ->where('service_variant_id', $booking->service_variant_id)->exists();
            if (! $attached) {
                return "room {$room->code} is not set up for this treatment.";
            }
            if ($room->capacity < $booking->guests) {
                return "room {$room->code} holds {$room->capacity}, but the booking has ".RoomAssignmentAudit::guests((int) $booking->guests).'.';
            }
            if ($booking->requested_room_type !== null && $room->type !== $booking->requested_room_type) {
                return "room {$room->code} is not of the requested type {$booking->requested_room_type}.";
            }

            return "the treatment of booking {$booking->reference} is no longer offered. Cancel it and record a new booking.";
        }

        if ($match['available'] !== true) {
            return "room {$room->code} is not free for the whole treatment and cleaning time. Run bookings:reconcile-rooms to see free rooms.";
        }

        return null;
    }

    private function when(Booking $booking): string
    {
        return "{$booking->reference} on {$booking->appointment_date->format('Y-m-d')} {$booking->appointment_time} WIB";
    }
}
