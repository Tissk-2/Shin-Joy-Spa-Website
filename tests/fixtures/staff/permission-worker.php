<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedStaffDatabase.php';

use App\Actions\Staff\SetBookingPermission;
use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Staff\IsolatedStaffDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase
{
    public function boot(): void
    {
        $this->app = $this->createApplication();
        $this->withoutVite();
    }
};
$test->boot();
[$script, $directory, $slot, $operation, $ownerId, $targetId, $bookingId] = $argv;
if (! in_array($slot, ['first', 'second'], true) || ! in_array($operation, ['grant', 'revoke', 'transition'], true)
    || ! ctype_digit($ownerId) || ! ctype_digit($targetId) || ! ctype_digit($bookingId)) {
    throw new RuntimeException('Invalid permission fixture arguments.');
}
IsolatedStaffDatabase::connect($directory);
$owner = User::findOrFail((int) $ownerId);
$staff = User::findOrFail((int) $targetId); // Deliberately retained across revocation.
$booking = Booking::findOrFail((int) $bookingId);
touch($directory.'/'.$slot.'-ready');
IsolatedStaffDatabase::wait($directory.'/'.$slot.'-go');
DB::connection()->beforeExecuting(function ($query) use ($directory, $slot): void {
    if (str_starts_with(strtolower($query), 'update "staff_access_guards"')) {
        touch($directory.'/'.$slot.'-attempting');
    }
    if (str_starts_with(strtolower($query), 'select * from "users"') && DB::transactionLevel() > 0) {
        touch($directory.'/'.$slot.'-acquired');
        if ($slot === 'first') {
            IsolatedStaffDatabase::wait($directory.'/release-first');
        }
    }
});
try {
    if ($operation === 'transition') {
        (new TransitionBookingStatus)($staff, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null);
        echo 'confirmed';
    } else {
        (new SetBookingPermission)($owner, $staff->id, $operation === 'grant');
        echo $operation;
    }
} catch (AuthorizationException) {
    echo 'denied';
}
