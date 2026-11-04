<?php

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Scheduling\RoomAllocationTest;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(base_path('storage/framework/testing.sqlite'));
$actual = realpath(config('database.connections.sqlite.database'));
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! $expected || $actual !== $expected
    || DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== $expected || ! config('booking.scheduling_enabled')) {
    throw new RuntimeException('Unsafe room block fixture environment.');
}
Notification::fake();
Mail::fake();
[$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
$booking->forceFill(['reference' => 'B3F-APPROVAL', 'appointment_date' => '2099-10-25'])->save();
$protected = Booking::factory()->create(['reference' => 'B3F-PROTECTED', 'service_id' => $booking->service_id, 'service_variant_id' => $booking->service_variant_id, 'appointment_date' => '2099-11-01', 'status' => 'completed']);
RoomReservation::create(['room_id' => $room->id, 'booking_id' => $protected->id, 'starts_at' => '2099-11-01 03:00:00', 'treatment_ends_at' => '2099-11-01 04:30:00', 'ends_at' => '2099-11-01 04:45:00', 'cleaning_minutes' => 15]);
$inactive = Room::create(['code' => 'B3F-INACTIVE', 'name' => 'Inactive existing room', 'capacity' => 2, 'is_active' => false]);
RoomBlock::create(['room_id' => $inactive->id, 'starts_at' => '2099-12-20 03:00:00', 'ends_at' => '2099-12-20 04:00:00', 'reason' => 'Far future recorded period']);
$staff = User::factory()->admin()->create(['can_manage_bookings' => true, 'email' => 'b3f-capable@example.test', 'password' => 'calmspa9']);
$denied = User::factory()->admin()->create(['can_manage_bookings' => false, 'email' => 'b3f-denied@example.test', 'password' => 'calmspa9']);
echo json_encode(['staffEmail' => $staff->email, 'deniedEmail' => $denied->email, 'roomId' => $room->id, 'inactiveRoomId' => $inactive->id, 'roomCount' => Room::count(), 'bookingReference' => $booking->reference]);
