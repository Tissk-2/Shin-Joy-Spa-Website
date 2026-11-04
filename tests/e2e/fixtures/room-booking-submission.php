<?php

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(base_path('storage/framework/testing.sqlite'));
$actual = realpath(config('database.connections.sqlite.database'));
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! $expected || $actual !== $expected
    || DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== $expected
    || config('booking.scheduling_enabled') !== true || config('booking.online_payments_enabled') !== false
    || config('services.telegram.booking_notifications_enabled') !== false || config('mail.default') !== 'array'
    || config('app.config_cache') === base_path('bootstrap/cache/config.php')) {
    throw new RuntimeException('Unsafe submission fixture environment.');
}
$action = $argv[1] ?? '';
if (! in_array($action, ['block', 'unblock', 'inspect'], true)) {
    throw new InvalidArgumentException('Expected a synthetic fixture action.');
}
$room = Room::query()->where('code', 'B3J-A')->sole();
$customer = User::query()->where('email', 'b3j-customer@example.test')->sole();
$date = CarbonImmutable::now('Asia/Jakarta')->addDays(2)->startOfDay();
$start = $date->setTime(9, 0)->utc();
$blocks = RoomBlock::query()->where('room_id', $room->id)
    ->where('reason', 'B3K synthetic stale submission')->where('starts_at', $start);
if ($action === 'block') {
    if ($blocks->exists()) {
        throw new RuntimeException('Synthetic block already exists.');
    }
    RoomBlock::create(['room_id' => $room->id, 'starts_at' => $start,
        'ends_at' => $start->addHour(), 'reason' => 'B3K synthetic stale submission']);
} elseif ($action === 'unblock') {
    if ($blocks->count() !== 1) {
        throw new RuntimeException('Expected one synthetic block.');
    }
    $blocks->delete();
}
$bookings = Booking::query()->where('user_id', $customer->id)->get();
echo json_encode(['database' => $actual, 'bookings' => $bookings->count(),
    'statuses' => $bookings->map(fn (Booking $booking) => $booking->status->value)->all(),
    'reservations' => RoomReservation::query()->count(),
    'references' => $bookings->pluck('reference')->all()]);
