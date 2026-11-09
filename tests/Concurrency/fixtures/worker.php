<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Actions\SaveRoomBlock;
use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
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

if (! in_array($scenario, ['approve', 'block', 'complete', 'no_show'], true) || ! in_array($role, ['first', 'second'], true) || ! ctype_digit($bookingId)) {
    throw new RuntimeException('Invalid worker arguments.');
}

$booking = Booking::findOrFail((int) $bookingId);
$actor = $scenario === 'block'
    ? User::where('role', 'owner')->orderBy('id')->firstOrFail()
    : User::where('role', 'admin')->orderBy('id')->get()[$role === 'first' ? 0 : 1];
$roomId = $scenario === 'block' ? Room::query()->where('code', 'small')->value('id') : null;

if ($scenario === 'block' && $roomId === null) {
    throw new RuntimeException('The fixture room is missing.');
}

CarbonImmutable::setTestNow(in_array($scenario, ['complete', 'no_show'], true) ? '2026-10-25 06:00:00 UTC' : '2026-10-24 00:00:00 UTC');

file_put_contents($dir.'/'.$role.'-ready', 'ready');
ConcurrencyDatabase::wait($dir.'/'.$role.'-go');

$acquired = false;
$table = $scenario === 'block' ? 'rooms' : 'bookings';
DB::connection()->beforeExecuting(function (string $query) use ($dir, $role, $table, &$acquired): void {
    if (preg_match('/\Aupdate [`"]?staff_access_guards[`"]?/i', $query)) {
        file_put_contents("{$dir}/{$role}-attempting", 'attempting');
    }

    if (! $acquired && preg_match('/\Aselect .*[`"]'.$table.'[`"]/is', $query) && DB::transactionLevel() > 0) {
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
