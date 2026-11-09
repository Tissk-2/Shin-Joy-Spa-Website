<?php

use App\Jobs\ExpirePendingBookings;
use App\Jobs\PruneTelegramData;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every 10 seconds, so bot replies and booking notifications go out within seconds.
Schedule::command('outbox:dispatch --limit=100')->everyTenSeconds()->withoutOverlapping(5);

Schedule::call(fn () => app(ExpirePendingBookings::class)->handle())
    ->name('bookings:expire-pending')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::call(fn () => app(PruneTelegramData::class)->handle())
    ->name('telegram:prune-data')
    ->daily()
    ->withoutOverlapping(60);
