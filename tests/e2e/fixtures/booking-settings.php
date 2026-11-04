<?php

use App\Models\BookingSettings;
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
    || config('booking.scheduling_enabled') || config('booking.online_payments_enabled')) {
    throw new RuntimeException('Unsafe booking settings fixture environment.');
}
Notification::fake();
Mail::fake();
if (($argv[1] ?? null) === 'facts') {
    $settings = BookingSettings::findOrFail(1);
    echo json_encode(['version' => $settings->version, 'horizon' => $settings->booking_horizon_days,
        'intervals' => $settings->review_intervals, 'schedulingEnabled' => config('booking.scheduling_enabled')]);
} else {
    if (BookingSettings::query()->exists()) {
        throw new RuntimeException('The dedicated scenario requires unconfigured settings.');
    }
    $owner = User::factory()->owner()->create(['email' => 'b3h-owner@example.test', 'password' => 'calmspa9']);
    $staff = User::factory()->admin()->create(['email' => 'b3h-staff@example.test', 'password' => 'calmspa9', 'can_manage_bookings' => true]);
    echo json_encode(['ownerEmail' => $owner->email, 'staffEmail' => $staff->email]);
}
