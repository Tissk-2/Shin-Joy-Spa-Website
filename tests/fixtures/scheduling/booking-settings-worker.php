<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedScheduleDatabase.php';

use App\Actions\SaveBookingSettings;
use App\Models\User;
use App\Support\Staff\StaffAccessLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Admin\BookingSettingsTest;
use Tests\Fixtures\Scheduling\IsolatedScheduleDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase {};
$test->createApplication();
[$script, $directory, $role, $operation, $ownerId] = $argv;
if (! in_array($role, ['first', 'second'], true) || ! in_array($operation, ['create', 'update', 'demote', 'unverify', 'delete'], true) || ! ctype_digit($ownerId)) {
    throw new RuntimeException('Invalid settings worker arguments.');
}
IsolatedScheduleDatabase::connect($directory);
$actor = User::findOrFail((int) $ownerId);
file_put_contents($directory.'/'.$role.'-ready', 'ready with original actor');
IsolatedScheduleDatabase::wait($directory.'/'.$role.'-go');
DB::connection()->beforeExecuting(function ($query) use ($directory, $role): void {
    if (str_starts_with(strtolower($query), 'update "staff_access_guards"')) {
        file_put_contents($directory.'/'.$role.'-attempting', 'attempting staff guard');
    }
});
$acquired = false;
DB::connection()->beforeExecuting(function ($query) use ($directory, $role, &$acquired): void {
    if (! $acquired && DB::transactionLevel() > 0 && str_starts_with(strtolower($query), 'select') && str_contains($query, 'from "booking_settings"')) {
        $acquired = true;
        file_put_contents($directory.'/'.$role.'-acquired', 'guards held before singleton current read');
        if ($role === 'first') {
            IsolatedScheduleDatabase::wait($directory.'/release-first');
        }
    }
});
try {
    if ($role === 'first' && in_array($operation, ['demote', 'unverify', 'delete'], true)) {
        (new StaffAccessLock(requireSchema: true))->run(function () use ($directory, $ownerId, $operation): void {
            DB::table('users')->where('id', $ownerId)->lockForUpdate()->first();
            file_put_contents($directory.'/first-acquired', 'staff guard held');
            IsolatedScheduleDatabase::wait($directory.'/release-first');
            $query = DB::table('users')->where('id', $ownerId);
            match ($operation) {
                'demote' => $query->update(['role' => 'admin']),
                'unverify' => $query->update(['email_verified_at' => null]),
                'delete' => $query->delete(),
            };
        });
        echo 'authority changed';
    } else {
        (new SaveBookingSettings)($actor, BookingSettingsTest::input(['version' => $operation === 'update' ? 1 : 0,
            'booking_horizon_days' => $role === 'first' ? 31 : 32]));
        echo 'saved';
    }
} catch (AuthorizationException) {
    echo 'denied';
} catch (ValidationException $error) {
    if (! isset($error->errors()['version'])) {
        throw $error;
    }
    echo 'stale';
}
