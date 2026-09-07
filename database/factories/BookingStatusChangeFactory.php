<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingStatusChange>
 */
class BookingStatusChangeFactory extends Factory
{
    protected $model = BookingStatusChange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'admin_user_id' => User::factory()->admin(),
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'from_status' => BookingStatus::Pending,
            'to_status' => BookingStatus::Confirmed,
            'reason' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
