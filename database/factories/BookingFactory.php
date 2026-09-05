<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\TreatmentMode;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'service_id' => null,
            'service_variant_id' => null,
            'service_extension_id' => null,
            'reference' => 'SJ-'.Str::ulid()->toBase32(),
            'submission_key' => fake()->uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'status' => BookingStatus::Pending,
            'customer_name' => fake()->name(),
            'customer_email' => fake()->unique()->safeEmail(),
            'customer_phone' => '+6281234567890',
            'appointment_date' => fake()->dateTimeBetween('+1 day', '+30 days')->format('Y-m-d'),
            'appointment_time' => '10:30',
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 1,
            'notes' => null,
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko',
            'package_name_en' => 'Nezuko',
            'package_is_vip' => false,
            'treatment_mode' => TreatmentMode::Regular->value,
            'base_choice_code' => 'nezuko-regular-90',
            'base_duration_minutes' => 90,
            'base_price_idr' => 265000,
            'extension_choice_code' => null,
            'extension_duration_minutes' => null,
            'extension_price_idr' => null,
            'total_duration_minutes' => 90,
            'total_price_idr' => 265000,
        ];
    }
}
