<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedScheduleDatabase.php';

use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Scheduling\IsolatedScheduleDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase {};
$test->createApplication();
$directory = $argv[1] ?? '';
$role = $argv[2] ?? '';
if (! in_array($role, ['first', 'second'], true) || ! ctype_digit($argv[3] ?? '')) {
    throw new RuntimeException('Invalid worker arguments.');
}
IsolatedScheduleDatabase::connect($directory);
$booking = Booking::findOrFail((int) $argv[3]);
file_put_contents($directory.'/'.$role.'-ready', 'ready');
IsolatedScheduleDatabase::wait($directory.'/'.$role.'-go');
DB::connection()->beforeExecuting(function ($query) use ($directory, $role): void {
    if (str_starts_with(strtolower($query), 'update "staff_access_guards"')) {
        file_put_contents($directory.'/'.$role.'-attempting', 'attempting staff write lock');
    }
});
config(['booking.scheduling_enabled' => true]);
CarbonImmutable::setTestNow('2026-10-24 00:00:00 UTC');
DB::connection()->beforeExecuting(function ($query) use ($directory, $role): void {
    if (str_starts_with(strtolower($query), 'select') && str_contains($query, '"bookings"') && DB::transactionLevel() > 0) {
        file_put_contents($directory.'/'.$role.'-acquired', 'guard acquired');
        if ($role === 'first') {
            IsolatedScheduleDatabase::wait($directory.'/release-first');
        }
    }
});
try {
    $actor = User::where('role', 'admin')->orderBy('id')->get()[$role === 'first' ? 0 : 1];
    (new TransitionBookingStatus)($actor, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null, true);
    echo 'confirmed';
} catch (ValidationException $exception) {
    if (! isset($exception->errors()['room'])) {
        throw $exception;
    }
    echo 'unavailable';
}
