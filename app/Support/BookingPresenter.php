<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\RoomReservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class BookingPresenter
{
    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>}
     */
    public function groupedIndex(Collection $bookings): array
    {
        $grouped = $bookings->partition(
            fn (Booking $booking): bool => $this->appointmentInstant($booking)
                ->greaterThanOrEqualTo(CarbonImmutable::now($booking->appointment_timezone))
        );

        return [
            'upcoming' => $grouped[0]
                ->sortBy(fn (Booking $booking): int => $this->appointmentInstant($booking)->getTimestamp())
                ->map($this->summary(...))
                ->values()
                ->all(),
            'past' => $grouped[1]
                ->sortByDesc(fn (Booking $booking): int => $this->appointmentInstant($booking)->getTimestamp())
                ->map($this->summary(...))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Booking $booking): array
    {
        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'package' => [
                'slug' => $booking->package_slug,
                'nameId' => $booking->package_name_id,
                'nameEn' => $booking->package_name_en,
                'isVip' => $booking->package_is_vip,
            ],
            'treatmentMode' => $booking->treatment_mode,
            'appointment' => [
                'date' => $booking->appointment_date->format('Y-m-d'),
                'time' => $booking->appointment_time,
                'timezone' => $booking->appointment_timezone,
            ],
            'guests' => $booking->guests,
            'totalDurationMinutes' => $booking->total_duration_minutes,
            'totalPriceIdr' => $booking->total_price_idr,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Booking $booking): array
    {
        return [
            ...$this->summary($booking),
            'customer' => [
                'name' => $booking->customer_name,
                'email' => $booking->customer_email,
                'phone' => $booking->customer_phone,
            ],
            'base' => [
                'choiceCode' => $booking->base_choice_code,
                'durationMinutes' => $booking->base_duration_minutes,
                'priceIdr' => $booking->base_price_idr,
            ],
            'extension' => $booking->extension_choice_code === null ? null : [
                'choiceCode' => $booking->extension_choice_code,
                'durationMinutes' => $booking->extension_duration_minutes,
                'priceIdr' => $booking->extension_price_idr,
            ],
            'notes' => $booking->notes,
            'paymentPreference' => $booking->payment_preference,
            // Unverified pricing was never multiplied by guests, so it stays an estimate.
            'pricingVerified' => $booking->pricing_basis !== null,
            'room' => $this->assignedRoomName($booking),
        ];
    }

    private function assignedRoomName(Booking $booking): ?string
    {
        if ($booking->status === BookingStatus::Cancelled || ! Schema::hasTable('room_reservations')) {
            return null;
        }

        return RoomReservation::query()->with('room')->where('booking_id', $booking->getKey())
            ->whereNull('released_at')->first()?->room->name;
    }

    private function appointmentInstant(Booking $booking): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $booking->appointment_date->format('Y-m-d').' '.$booking->appointment_time,
            $booking->appointment_timezone,
        );
    }
}
