<?php

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;

trait UsesUtcScheduleTimestamps
{
    /**
     * Store timestamps as UTC wall-clock values without mutating inputs.
     * Hydration uses the application's existing fixed UTC timezone configuration.
     */
    public function fromDateTime($value)
    {
        if (empty($value)) {
            return $value;
        }

        return CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat());
    }
}
