<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedScheduleDatabase.php';

use App\Models\Booking;
use App\Support\Scheduling\RoomAllocator;
use App\Support\Scheduling\ScheduleLock;
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
    if (str_starts_with(strtolower($query), 'update "schedule_guards"')) {
        file_put_contents($directory.'/'.$role.'-attempting', 'attempting guard update');
    }
});
try {
    (new ScheduleLock)->run(function () use ($directory, $role, $booking): void {
        file_put_contents($directory.'/'.$role.'-acquired', 'guard acquired');
        if ($role === 'first') {
            IsolatedScheduleDatabase::wait($directory.'/release-first');
        }
        (new RoomAllocator)->allocateLocked($booking);
    });
    echo 'allocated';
} catch (ValidationException $exception) {
    if (! isset($exception->errors()['room'])) {
        throw $exception;
    }
    echo 'unavailable';
}
