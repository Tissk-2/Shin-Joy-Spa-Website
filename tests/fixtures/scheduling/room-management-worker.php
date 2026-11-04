<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedScheduleDatabase.php';

use App\Actions\SaveRoom;
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
[$script, $directory, $role, $operation, $roomId, $bookingId] = $argv;
if (! in_array($role, ['first', 'second'], true) || ! in_array($operation, ['edit', 'approve', 'deactivate', 'incompatible'], true)
    || ! ctype_digit($roomId) || ! ctype_digit($bookingId)) {
    throw new RuntimeException('Invalid worker arguments.');
}
IsolatedScheduleDatabase::connect($directory);
$actor = User::where('role', 'owner')->firstOrFail();
$booking = Booking::findOrFail((int) $bookingId);
config(['booking.scheduling_enabled' => true]);
CarbonImmutable::setTestNow('2026-10-24 00:00:00 UTC');
file_put_contents($directory.'/'.$role.'-ready', 'ready');
IsolatedScheduleDatabase::wait($directory.'/'.$role.'-go');
DB::connection()->beforeExecuting(function ($query) use ($directory, $role): void {
    if (str_starts_with(strtolower($query), 'update "staff_access_guards"')) {
        file_put_contents($directory.'/'.$role.'-attempting', 'attempting staff guard');
    }
});
$acquired = false;
DB::connection()->beforeExecuting(function ($query) use ($directory, $role, $operation, &$acquired): void {
    $table = $operation === 'approve' ? 'bookings' : 'rooms';
    if (! $acquired && DB::transactionLevel() > 0 && str_starts_with(strtolower($query), 'select') && str_contains($query, 'from "'.$table.'"')) {
        $acquired = true;
        file_put_contents($directory.'/'.$role.'-acquired', 'guards held');
        if ($role === 'first') {
            IsolatedScheduleDatabase::wait($directory.'/release-first');
        }
    }
});
try {
    if ($operation === 'approve') {
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null, true);
        echo 'confirmed';
    } else {
        (new SaveRoom)($actor, (int) $roomId, ['code' => 'small', 'name' => 'Edited '.$role, 'type' => null,
            'capacity' => 2, 'cleaningMinutes' => 15, 'isActive' => $operation !== 'deactivate', 'version' => 1,
            'compatibleVariantIds' => $operation === 'incompatible' ? [] : [$booking->service_variant_id]]);
        echo 'saved';
    }
} catch (ValidationException $error) {
    if (isset($error->errors()['version'])) {
        echo 'stale';
    } elseif (isset($error->errors()['room'])) {
        echo $operation === 'approve' ? 'unavailable' : 'conflict';
    } else {
        throw $error;
    }
}
