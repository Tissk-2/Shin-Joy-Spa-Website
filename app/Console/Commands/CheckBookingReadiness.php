<?php

namespace App\Console\Commands;

use App\Queries\BookingReadinessQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class CheckBookingReadiness extends Command
{
    /** @var string */
    protected $signature = 'bookings:readiness';

    /** @var string */
    protected $description = 'Check whether room scheduling can be switched on (read-only)';

    public function handle(BookingReadinessQuery $query): int
    {
        $issues = $query->issues(CarbonImmutable::now('UTC'));

        if ($issues === []) {
            $this->line('Booking readiness: ready.');
        } else {
            $this->line('Booking readiness: not ready ('.count($issues).' issues).');
            foreach ($issues as $issue) {
                $this->line('- '.$issue);
            }
        }

        $this->line('Room scheduling is currently '.(config('booking.scheduling_enabled') === true ? 'on' : 'off').'. This command never changes it.');
        $this->line('This check does not confirm therapist availability or payment readiness.');

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
