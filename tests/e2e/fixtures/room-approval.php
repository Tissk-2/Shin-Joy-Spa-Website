<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Scheduling\RoomAllocationTest;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(base_path('storage/framework/testing.sqlite'));
$actual = realpath(config('database.connections.sqlite.database'));
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! $expected || $actual !== $expected || ! config('booking.scheduling_enabled')) {
    throw new RuntimeException('Unsafe room approval fixture environment.');
}
Notification::fake();
Mail::fake();
[$first] = (new RoomAllocationTest('fixture'))->fixture();
$first->forceFill(['appointment_date' => '2099-10-25'])->save();
$second = $first->replicate(['reference', 'submission_key']);
$second->reference = 'B3C-SECOND';
$second->submission_key = 'B3C-SECOND';
$second->save();
$actor = User::factory()->bookingStaff()->create(['email' => 'approval@example.test', 'password' => 'calmspa9']);
echo json_encode(['first' => $first->reference, 'second' => $second->reference, 'email' => $actor->email]);
