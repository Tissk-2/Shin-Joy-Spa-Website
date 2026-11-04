<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedScheduleDatabase.php';

use App\Actions\SaveRoomBlock;
use App\Actions\TerminateRoomBlock;
use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Support\Scheduling\ScheduleLock;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Scheduling\IsolatedScheduleDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase {};
$test->createApplication();
[$script, $directory, $role, $operation, $roomId, $bookingId, $blockId, $actorId] = $argv;
if (! in_array($role, ['first', 'second'], true)
    || ! in_array($operation, ['cancel', 'end', 'edit', 'edit-active', 'approve', 'hold', 'revoke', 'cross-start', 'cross-end', 'cross-time'], true)
    || ! ctype_digit($roomId) || ! ctype_digit($bookingId) || ! ctype_digit($blockId) || ! ctype_digit($actorId)) {
    throw new RuntimeException('Invalid termination worker arguments.');
}
IsolatedScheduleDatabase::connect($directory);
$actor = User::findOrFail((int) $actorId);
$booking = Booking::findOrFail((int) $bookingId);
config(['booking.scheduling_enabled' => true]);
CarbonImmutable::setTestNow(in_array($operation, ['end', 'edit-active', 'cross-end', 'cross-time'], true) ? '2026-10-25 03:00:00 UTC' : '2026-10-24 00:00:00 UTC');
file_put_contents($directory.'/'.$role.'-ready', 'ready');
IsolatedScheduleDatabase::wait($directory.'/'.$role.'-go');
$acquired = false;
$signal = function () use ($directory, $role, &$acquired): void {
    if ($acquired) {
        return;
    }
    $acquired = true;
    file_put_contents($directory.'/'.$role.'-acquired', 'guards held');
    if ($role === 'first') {
        IsolatedScheduleDatabase::wait($directory.'/release-first');
    }
};
DB::connection()->beforeExecuting(function ($query) use ($directory, $role, $operation, $signal): void {
    $sql = strtolower($query);
    if (str_starts_with($sql, 'update "staff_access_guards"')) {
        file_put_contents($directory.'/'.$role.'-attempting', 'attempting guard');
    }
    if (str_starts_with($sql, 'update "schedule_guards"')) {
        $later = match ($operation) {
            'cross-start' => '2026-10-25 03:00:00.500000 UTC',
            'cross-end' => '2026-10-25 04:00:00.500000 UTC',
            'cross-time', 'end' => '2026-10-25 03:30:00.500000 UTC',
            default => null,
        };
        if ($later) {
            CarbonImmutable::setTestNow($later);
        }
    }
    $table = $operation === 'approve' ? 'bookings' : 'room_blocks';
    if (DB::transactionLevel() > 0 && str_starts_with($sql, 'select') && str_contains($sql, 'from "'.$table.'"')) {
        $signal();
    }
});
try {
    if (in_array($operation, ['hold', 'revoke'], true)) {
        (new StaffAccessLock(requireSchema: true))->run(function () use ($operation, $actor, $signal): void {
            (new ScheduleLock)->run(function () use ($operation, $actor, $signal): void {
                $signal();
                if ($operation === 'revoke') {
                    DB::table('users')->where('id', $actor->id)->update(['can_manage_bookings' => false]);
                }
            });
        });
        echo 'held';
    } elseif ($operation === 'approve') {
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null, true);
        echo 'confirmed';
    } elseif (in_array($operation, ['edit', 'edit-active'], true)) {
        (new SaveRoomBlock)($actor, (int) $blockId, ['version' => 1, 'roomId' => (int) $roomId, 'startsAt' => '2026-10-25T10:00', 'endsAt' => '2026-10-25T11:00', 'reason' => 'Saved '.$role]);
        echo 'saved';
    } else {
        $intent = in_array($operation, ['end', 'cross-end', 'cross-time'], true) ? 'end_now' : 'cancel_future';
        (new TerminateRoomBlock)($actor, (int) $blockId, ['intent' => $intent, 'version' => 1, 'reason' => 'Terminal '.$role]);
        echo $intent === 'end_now' ? 'ended' : 'cancelled';
    }
} catch (AuthorizationException) {
    echo 'forbidden';
} catch (ValidationException $error) {
    $field = array_key_first($error->errors());
    echo match ($field) {
        'version' => 'stale', 'block' => 'terminated', 'intent' => 'lifecycle', 'room' => 'unavailable', default => throw $error
    };
}
