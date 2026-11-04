<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedScheduleDatabase.php';

use App\Actions\SaveRoomBlock;
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
[$script, $directory, $role, $operation, $roomId, $bookingId, $blockId] = $argv;
if (! in_array($role, ['first', 'second'], true) || ! in_array($operation, ['create', 'approve', 'edit', 'later', 'cross-start'], true)
    || ! ctype_digit($roomId) || ! ctype_digit($bookingId) || ! ctype_digit($blockId)) {
    throw new RuntimeException('Invalid worker arguments.');
}
IsolatedScheduleDatabase::connect($directory);
$actor = User::where('role', 'owner')->firstOrFail();
$booking = Booking::findOrFail((int) $bookingId);
config(['booking.scheduling_enabled' => true]);
CarbonImmutable::setTestNow($operation === 'cross-start' ? '2026-10-25 02:59:30 UTC' : '2026-10-24 00:00:00 UTC');
file_put_contents($directory.'/'.$role.'-ready', 'ready');
IsolatedScheduleDatabase::wait($directory.'/'.$role.'-go');
$acquired = false;
DB::connection()->beforeExecuting(function ($query) use ($directory, $role, $operation, &$acquired): void {
    if (str_starts_with(strtolower($query), 'update "staff_access_guards"')) {
        file_put_contents($directory.'/'.$role.'-attempting', 'attempting guard');
    }
    if ($operation === 'cross-start' && str_starts_with(strtolower($query), 'update "schedule_guards"')) {
        CarbonImmutable::setTestNow('2026-10-25 03:00:30 UTC');
    }
    $table = $operation === 'approve' ? 'bookings' : ($operation === 'edit' || $operation === 'cross-start' ? 'room_blocks' : 'rooms');
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
        $input = ['roomId' => (int) $roomId, 'startsAt' => $operation === 'later' ? '2026-10-26T10:00' : ($operation === 'cross-start' ? '2026-10-25T10:01' : '2026-10-25T10:00'),
            'endsAt' => $operation === 'later' ? '2026-10-26T12:00' : '2026-10-25T12:00', 'reason' => 'Saved '.$role];
        $edit = in_array($operation, ['edit', 'cross-start'], true);
        (new SaveRoomBlock)($actor, $edit ? (int) $blockId : null, [...$input, ...($edit ? ['version' => 1] : [])]);
        echo 'saved';
    }
} catch (ValidationException $error) {
    if (isset($error->errors()['version'])) {
        echo 'stale';
    } elseif (isset($error->errors()['startsAt'])) {
        echo 'active';
    } elseif (isset($error->errors()['room'])) {
        echo $operation === 'approve' ? 'unavailable' : 'conflict';
    } else {
        throw $error;
    }
}
