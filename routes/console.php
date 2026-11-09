<?php

use App\Jobs\ExpirePendingBookings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:dispatch --limit=100')->everyMinute()->withoutOverlapping(5);

Schedule::call(fn () => app(ExpirePendingBookings::class)->handle())
    ->name('bookings:expire-pending')
    ->everyMinute()
    ->withoutOverlapping(5);
