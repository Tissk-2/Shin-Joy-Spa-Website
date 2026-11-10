<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Actions\SaveRoomBlock;
use App\Actions\Staff\SetBookingPermission;
use App\Actions\Telegram\RevokeStaffLink;
use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\Room;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\Telegram\TelegramUpdateHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concurrency\ConcurrencyDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase {};
$test->createApplication();

$env = ConcurrencyDatabase::environment();
ConcurrencyDatabase::connect($env);
$dir = ConcurrencyDatabase::barrier($env);

$scenario = $argv[1] ?? '';
$role = $argv[2] ?? '';
$bookingId = $argv[3] ?? '';

if (! in_array($scenario, ['approve', 'block', 'complete', 'no_show', 'telegram', 'unlink', 'unpermit'], true) || ! in_array($role, ['first', 'second'], true) || ! ctype_digit($bookingId)) {
    throw new RuntimeException('Invalid worker arguments.');
}

// Telegram, unlink and unpermit act on a staff member's authority, not on a booking row they are given.
$staffSide = in_array($scenario, ['telegram', 'unlink', 'unpermit'], true);
$booking = $staffSide ? null : Booking::findOrFail((int) $bookingId);
$actor = match (true) {
    $scenario === 'telegram' => null,   // a press acts as the linked staff member, whom the callback finds itself
    in_array($scenario, ['block', 'unlink', 'unpermit'], true) => User::where('role', 'owner')->orderBy('id')->firstOrFail(),
    default => User::where('role', 'admin')->orderBy('id')->get()[$role === 'first' ? 0 : 1],
};
$roomId = $scenario === 'block' ? Room::query()->where('code', 'small')->value('id') : null;

if ($scenario === 'block' && $roomId === null) {
    throw new RuntimeException('The fixture room is missing.');
}

CarbonImmutable::setTestNow(in_array($scenario, ['complete', 'no_show'], true) ? '2026-10-25 06:00:00 UTC' : '2026-10-24 00:00:00 UTC');
config(['services.telegram.actions_enabled' => true, 'services.telegram.staff_chat_id' => '-1001234567890']);

file_put_contents($dir.'/'.$role.'-ready', 'ready');
ConcurrencyDatabase::wait($dir.'/'.$role.'-go');

$acquired = false;
$guarded = false;
$table = $scenario === 'block' ? 'rooms' : 'bookings';
// A Telegram update's transaction writes its telegram_updates row first. On SQLite that write is where it waits, behind
// the database-wide write lock; on MySQL that row isn't contended, so the worker only waits at the staff guard.
$contended = DB::connection()->getDriverName() === 'sqlite' ? 'staff_access_guards|telegram_updates' : 'staff_access_guards';
DB::connection()->beforeExecuting(function (string $query) use ($dir, $role, $table, $staffSide, $contended, &$acquired, &$guarded): void {
    if (preg_match('/\Aupdate [`"]?('.$contended.')[`"]?/i', $query)) {   // mutation H1
        file_put_contents("{$dir}/{$role}-attempting", 'attempting');
    }
    if (preg_match('/\Aupdate [`"]?staff_access_guards[`"]?/i', $query)) {
        $guarded = true;

        return;
    }

    // Not every staff-side path reads bookings, so those pause at their first read under the staff guard.
    $hit = $staffSide   // mutation H2
        ? $guarded && preg_match('/\Aselect /i', $query) === 1
        : preg_match('/\Aselect .*[`"]'.$table.'[`"]/is', $query) === 1 && DB::transactionLevel() > 0;

    if (! $acquired && $hit) {
        $acquired = true;
        file_put_contents("{$dir}/{$role}-acquired", 'acquired');

        if ($role === 'first') {
            ConcurrencyDatabase::wait("{$dir}/release-first");
        }
    }
});

config(['booking.scheduling_enabled' => true]);

try {
    if ($scenario === 'approve') {
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null, true);
        echo 'confirmed';
    } elseif ($scenario === 'block') {
        (new SaveRoomBlock)($actor, null, ['roomId' => $roomId, 'startsAt' => '2026-10-25T10:00', 'endsAt' => '2026-10-25T12:00', 'reason' => 'Race '.$role]);
        echo 'saved';
    } elseif ($scenario === 'complete') {
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Confirmed, BookingStatus::Completed, null, null, null, false);
        echo 'completed';
    } elseif ($scenario === 'telegram') {
        $message = OutboxMessage::query()->findOrFail((int) $bookingId);
        app(TelegramUpdateHandler::class)->handle($message);
        $update = TelegramUpdate::query()->findOrFail($message->payload['telegram_update_id']);
        echo $update->outcome.'/'.$update->outcome_reason;
    } elseif ($scenario === 'unlink') {
        (new RevokeStaffLink)($actor, (int) $bookingId);
        echo 'unlinked';
    } elseif ($scenario === 'unpermit') {
        (new SetBookingPermission)($actor, (int) $bookingId, false);
        echo 'unpermitted';
    } else {
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Confirmed, BookingStatus::NoShow, null, null, null, false);
        echo 'no_show';
    }
} catch (ValidationException $exception) {
    $errors = $exception->errors();

    if (isset($errors['room'])) {
        echo $scenario === 'block' ? 'conflict' : 'unavailable';
    } elseif (isset($errors['currentStatus'])) {
        echo 'stale';
    } else {
        throw $exception;
    }
}
