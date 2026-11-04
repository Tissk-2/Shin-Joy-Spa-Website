<?php

use App\Models\Room;
use App\Models\RoomBlock;
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
    || DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== $expected || ! config('booking.scheduling_enabled')) {
    throw new RuntimeException('Unsafe terminal browser fixture environment.');
}
if (($argv[1] ?? '') === 'cross-start') {
    $block = RoomBlock::where('reason', 'Boundary maintenance')->sole();
    DB::table('room_blocks')->where('id', $block->id)->update(['starts_at' => CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d H:i:s')]);
    echo 'crossed';
    exit;
}
$staff = User::factory()->admin()->create(['name' => '<script>Terminal staff</script>', 'can_manage_bookings' => true, 'email' => 'b3g-capable@example.test', 'password' => 'calmspa9']);
$denied = User::factory()->admin()->create(['can_manage_bookings' => false, 'email' => 'b3g-denied@example.test', 'password' => 'calmspa9']);
$room = Room::create(['code' => 'B3G-ROOM', 'name' => 'Maintenance acceptance room', 'capacity' => 2, 'is_active' => true]);
$future = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2099-10-25 03:00:00', 'ends_at' => '2099-10-25 04:00:00', 'reason' => 'Future maintenance', 'created_by' => $staff->id]);
$active = RoomBlock::create(['room_id' => $room->id, 'starts_at' => CarbonImmutable::now('UTC')->subMinutes(30), 'ends_at' => CarbonImmutable::now('UTC')->addMinutes(30), 'reason' => 'Active maintenance', 'created_by' => $staff->id]);
$boundary = RoomBlock::create(['room_id' => $room->id, 'starts_at' => CarbonImmutable::now('UTC')->addMinutes(10), 'ends_at' => CarbonImmutable::now('UTC')->addMinutes(20), 'reason' => 'Boundary maintenance', 'created_by' => $staff->id]);
echo json_encode(['staffEmail' => $staff->email, 'deniedEmail' => $denied->email, 'roomId' => $room->id, 'futureId' => $future->id, 'activeId' => $active->id, 'boundaryId' => $boundary->id]);
