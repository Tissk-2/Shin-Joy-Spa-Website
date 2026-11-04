<?php

use App\Actions\Staff\SetBookingPermission;
use App\Models\Booking;
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
    throw new RuntimeException('Unsafe staff permission fixture environment.');
}
Notification::fake();
Mail::fake();
if (($argv[1] ?? null) === 'facts') {
    $staff = User::where('email', 'b1b-staff@example.test')->sole();
    $booking = Booking::where('reference', 'B1B-STAFF-PERMISSION')->sole();
    echo json_encode([
        'allowed' => $staff->can_manage_bookings, 'status' => $booking->status->value,
        'audit' => DB::table('staff_permission_changes')->where('target_user_id', $staff->id)->orderBy('id')->get(['was_allowed', 'is_allowed', 'channel']),
        'statusChanges' => $booking->statusChanges()->count(),
        'reservations' => DB::table('room_reservations')->where('booking_id', $booking->id)->count(),
        'schedulingEnabled' => (bool) config('booking.scheduling_enabled'),
    ]);
} elseif (($argv[1] ?? null) === 'booking-console-staff') {
    // Only the existing isolated booking-console browser scenario uses this
    // account. Keep admin:grant itself unprivileged, then explicitly authorize.
    $staff = User::where('email', 'admin-flow-staff@example.com')->sole();
    $owner = User::factory()->owner()->create(['email' => 'b1b-console-owner@example.test']);
    (new SetBookingPermission)($owner, $staff->id, true);
    echo json_encode(['allowed' => $staff->fresh()->can_manage_bookings]);
} elseif (($argv[1] ?? null) === null) {
    $owner = User::factory()->owner()->create(['name' => 'B1b Owner', 'email' => 'b1b-owner@example.test', 'password' => 'calmspa9']);
    $staff = User::factory()->admin()->create(['name' => 'Ayu Staff', 'email' => 'b1b-staff@example.test', 'password' => 'calmspa9']);
    User::factory()->admin()->unverified()->create(['name' => 'Waiting verification', 'email' => 'b1b-unverified@example.test']);
    $booking = Booking::factory()->create(['reference' => 'B1B-STAFF-PERMISSION']);
    echo json_encode(['ownerEmail' => $owner->email, 'staffEmail' => $staff->email, 'staffId' => $staff->id, 'reference' => $booking->reference]);
} else {
    throw new RuntimeException('Unknown staff fixture operation.');
}
