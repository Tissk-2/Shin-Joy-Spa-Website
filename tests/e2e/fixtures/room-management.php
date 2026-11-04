<?php

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomReservation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(base_path('storage/framework/testing.sqlite'));
$actual = realpath(config('database.connections.sqlite.database'));
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! $expected || $actual !== $expected
    || DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== $expected
    || config('booking.scheduling_enabled')) {
    throw new RuntimeException('Unsafe room management fixture environment.');
}
Notification::fake();
Mail::fake();
$category = ServiceCategory::create(['slug' => 'b3e-management', 'name_id' => 'Kategori Ruangan', 'name_en' => 'Room category', 'display_order' => 0, 'published_at' => now()]);
$service = Service::create(['category_id' => $category->id, 'slug' => 'b3e-management', 'name_id' => 'Pijat Ruangan', 'name_en' => 'Room massage', 'display_order' => 0, 'published_at' => now()]);
$variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'B3E-ARCHIVED', 'treatment_mode' => 'regular', 'duration_minutes' => 90, 'price_idr' => 123000, 'display_order' => 0, 'published_at' => now(), 'is_active' => false]);
$room = Room::create(['code' => 'B3E-PROTECTED', 'name' => 'Protected physical room', 'capacity' => 2, 'cleaning_minutes' => 15, 'is_active' => true]);
$room->variants()->attach($variant);
$booking = Booking::factory()->create(['reference' => 'B3E-PROTECTED-BOOKING', 'service_id' => $service->id, 'service_variant_id' => $variant->id, 'guests' => 2, 'appointment_date' => '2099-10-25', 'appointment_time' => '10:00', 'status' => 'completed']);
RoomReservation::create(['room_id' => $room->id, 'booking_id' => $booking->id, 'starts_at' => '2099-10-25 03:00:00', 'treatment_ends_at' => '2099-10-25 04:30:00', 'ends_at' => '2099-10-25 04:45:00', 'cleaning_minutes' => 15]);
$owner = User::factory()->owner()->create(['email' => 'b3e-owner@example.test', 'password' => 'calmspa9']);
$staff = User::factory()->admin()->create(['email' => 'b3e-staff@example.test', 'password' => 'calmspa9']);
echo json_encode(['ownerEmail' => $owner->email, 'staffEmail' => $staff->email, 'protectedRoomId' => $room->id, 'variantId' => $variant->id]);
