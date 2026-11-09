<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingDetailPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_shows_the_reserved_room_and_payment_choice(): void
    {
        $customer = User::factory()->create();
        $booking = Booking::factory()->for($customer)->create([
            'status' => BookingStatus::Confirmed,
            'payment_preference' => 'at_spa',
            'pricing_basis' => 'per_booking',
        ]);
        $this->reserve($this->room('Anggrek couple suite'), $booking);

        $detail = $this->actingAs($customer)
            ->get('/bookings/'.$booking->reference)
            ->assertOk()
            ->inertiaProps('booking');

        $this->assertSame('Anggrek couple suite', $detail['room']);
        $this->assertSame('at_spa', $detail['paymentPreference']);
        // Review addition: only a verified pricing basis makes the total a fixed price.
        $this->assertTrue($detail['pricingVerified']);
    }

    public function test_detail_hides_rooms_that_are_not_held_for_the_customer(): void
    {
        $customer = User::factory()->create();

        $pending = Booking::factory()->for($customer)->create([
            'status' => BookingStatus::Pending,
        ]);

        $cancelled = Booking::factory()->for($customer)->create([
            'status' => BookingStatus::Cancelled,
        ]);
        $this->reserve($this->room('Anggrek couple suite'), $cancelled);

        $released = Booking::factory()->for($customer)->create([
            'status' => BookingStatus::Confirmed,
        ]);
        $this->reserve($this->room('Melati family room'), $released, '2099-11-20 00:00:00');

        $otherCustomer = User::factory()->create();
        $other = Booking::factory()->for($otherCustomer)->create([
            'status' => BookingStatus::Confirmed,
        ]);
        $this->reserve($this->room('Kenanga quiet room'), $other);

        $pendingDetail = $this->actingAs($customer)
            ->get('/bookings/'.$pending->reference)
            ->assertOk()
            ->inertiaProps('booking');
        $this->assertNull($pendingDetail['room']);
        $this->assertNull($pendingDetail['paymentPreference']);
        $this->assertFalse($pendingDetail['pricingVerified']);

        $cancelledDetail = $this->actingAs($customer)
            ->get('/bookings/'.$cancelled->reference)
            ->assertOk()
            ->inertiaProps('booking');
        $this->assertNull($cancelledDetail['room']);

        $releasedDetail = $this->actingAs($customer)
            ->get('/bookings/'.$released->reference)
            ->assertOk()
            ->inertiaProps('booking');
        $this->assertNull($releasedDetail['room']);

        $this->actingAs($customer)
            ->get('/bookings/'.$other->reference)
            ->assertNotFound();
    }

    private function room(string $name): Room
    {
        return Room::create([
            'code' => strtoupper(substr($name, 0, 3)),
            'name' => $name,
            'capacity' => 2,
            'is_active' => true,
        ]);
    }

    private function reserve(Room $room, Booking $booking, ?string $releasedAt = null): void
    {
        RoomReservation::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'starts_at' => '2099-12-01 03:00:00',
            'treatment_ends_at' => '2099-12-01 04:30:00',
            'ends_at' => '2099-12-01 04:45:00',
            'cleaning_minutes' => 15,
            'released_at' => $releasedAt,
            'release_reason' => $releasedAt === null ? null : 'cancelled_before_start',
        ]);
    }
}
