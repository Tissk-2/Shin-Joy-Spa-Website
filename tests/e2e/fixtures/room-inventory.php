<?php

use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
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
    throw new RuntimeException('Unsafe room inventory fixture environment.');
}
Notification::fake();
Mail::fake();
$category = ServiceCategory::create(['slug' => 'b3d-inventory', 'name_id' => 'Kategori Uji', 'name_en' => 'Inventory category', 'display_order' => 0, 'published_at' => now()]);
$service = Service::create(['category_id' => $category->id, 'slug' => 'b3d-inventory', 'name_id' => 'Pijat Inventaris', 'name_en' => 'Inventory massage', 'display_order' => 0, 'published_at' => now()]);
$variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'B3D-INVENTORY', 'treatment_mode' => 'double_therapist', 'duration_minutes' => 90, 'price_idr' => 123000, 'display_order' => 0, 'published_at' => now(), 'is_active' => false]);
$now = CarbonImmutable::now('UTC')->startOfSecond();
foreach ([['A', true, null], ['B', false, 'all']] as [$suffix, $enabled, $type]) {
    $room = Room::create(['code' => 'B3D-'.$suffix, 'name' => 'Inventory room '.$suffix, 'type' => $type, 'capacity' => 2, 'cleaning_minutes' => 15, 'is_active' => $enabled]);
    $room->variants()->attach($variant);
    RoomBlock::create(['room_id' => $room->id, 'starts_at' => $now->subHour(), 'ends_at' => $now->addHour(), 'reason' => 'B3D saved current repair']);
    RoomBlock::create(['room_id' => $room->id, 'starts_at' => $now->addDay(), 'ends_at' => $now->addDay()->addHour(), 'reason' => 'B3D future cleaning']);
}
$admin = User::factory()->admin()->create(['email' => 'b3d-inventory@example.test', 'password' => 'calmspa9']);
echo json_encode(['email' => $admin->email]);
