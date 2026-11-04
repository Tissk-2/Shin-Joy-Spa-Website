<?php

use App\Models\Booking;
use App\Models\Service;
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
    || config('booking.scheduling_enabled') || config('booking.online_payments_enabled')
    || config('services.telegram.booking_notifications_enabled')) {
    throw new RuntimeException('Unsafe package pricing fixture environment.');
}
Notification::fake();
Mail::fake();
$service = Service::where('slug', 'nezuko')->sole();
if (($argv[1] ?? null) === 'facts') {
    $booking = Booking::where('customer_email', 'b2-customer@example.test')->first();
    echo json_encode([
        'price' => $service->variants()->where('code', 'nezuko-regular-60')->sole()->price_idr,
        'basis' => $service->pricing_basis?->value,
        'verified' => $service->hasVerifiedPricing(),
        'verifier' => $service->pricingVerifiedBy?->email,
        'bookingTotal' => $booking?->total_price_idr,
        'bookingBasis' => $booking?->pricing_basis?->value,
        'bookingGuests' => $booking?->guests,
        'reservationCount' => DB::table('room_reservations')->count(),
    ]);
} elseif (($argv[1] ?? null) === null) {
    if ($service->hasVerifiedPricing()) {
        throw new RuntimeException('Package pricing fixture requires fresh unverified catalog.');
    }
    $owner = User::factory()->owner()->create(['email' => 'b2-owner@example.test', 'password' => 'calmspa9']);
    $customer = User::factory()->create(['email' => 'b2-customer@example.test', 'password' => 'calmspa9']);
    echo json_encode(['owner' => $owner->email, 'customer' => $customer->email]);
} else {
    throw new RuntimeException('Unknown package pricing fixture operation.');
}
