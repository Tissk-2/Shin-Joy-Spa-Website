<?php

use App\Models\BookingSettings;
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
    || config('booking.scheduling_enabled') !== true || config('booking.online_payments_enabled') !== false
    || config('app.config_cache') === base_path('bootstrap/cache/config.php')) {
    throw new RuntimeException('Unsafe customer availability fixture environment.');
}
Notification::fake();
Mail::fake();
if (BookingSettings::query()->exists()) {
    throw new RuntimeException('Dedicated customer availability fixture requires fresh isolated settings.');
}
BookingSettings::create(['id' => 1, 'version' => 1, 'review_intervals' => [
    ['weekday' => 1, 'start_minute' => 540, 'end_minute' => 1260]],
    'review_target_minutes' => 30, 'booking_horizon_days' => 30,
    'minimum_lead_minutes' => 0, 'minimum_checkout_minutes' => 5]);
$category = ServiceCategory::create(['slug' => 'b3j-category', 'name_id' => 'Kategori uji', 'name_en' => 'Test category', 'image_path' => '', 'display_order' => 99, 'is_active' => true, 'published_at' => now()]);
$owner = User::factory()->owner()->create(['email' => 'b3j-owner@example.test']);
$service = Service::create(['category_id' => $category->id, 'slug' => 'b3j-treatment', 'name_id' => 'Perawatan uji', 'name_en' => 'Test treatment', 'description_id' => '', 'description_en' => '', 'long_description_id' => '', 'long_description_en' => '', 'duration_minutes' => 90, 'price_idr' => 265000, 'image_path' => '', 'gallery_paths' => [], 'popularity' => 0, 'display_order' => 99, 'is_active' => true, 'published_at' => now(), 'pricing_basis' => 'per_booking', 'min_guests' => 1, 'max_guests' => 4, 'pricing_verified_at' => now(), 'pricing_verified_by' => $owner->id]);
$variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'b3j-regular-90', 'treatment_mode' => 'regular', 'duration_minutes' => 90, 'price_idr' => 265000, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
$date = CarbonImmutable::now('Asia/Jakarta')->addDays(2)->startOfDay();
$occupiedDate = $date->addDay();
foreach (['A' => 'Quiet room', 'B' => 'Garden room'] as $code => $name) {
    $room = Room::create(['code' => 'B3J-'.$code, 'name' => $name, 'type' => $code === 'A' ? 'Private' : null, 'capacity' => 2, 'is_active' => true, 'cleaning_minutes' => 15]);
    $room->variants()->attach($variant);
    RoomBlock::create(['room_id' => $room->id, 'starts_at' => $occupiedDate->utc(), 'ends_at' => $occupiedDate->addDay()->utc(), 'reason' => 'Synthetic occupied day']);
    if ($code === 'B') {
        RoomBlock::create(['room_id' => $room->id, 'starts_at' => $date->setTime(9, 0)->utc(), 'ends_at' => $date->setTime(10, 0)->utc(), 'reason' => 'Synthetic partial occupation']);
    }
}
$customer = User::factory()->create(['email' => 'b3j-customer@example.test', 'password' => 'calmspa9']);
echo json_encode(['email' => $customer->email, 'date' => $date->format('Y-m-d'), 'occupiedDate' => $occupiedDate->format('Y-m-d'), 'database' => $actual, 'schedulingEnabled' => config('booking.scheduling_enabled')]);
