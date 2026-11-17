<?php

namespace App\Support;

final class BookingSelectionLimits
{
    public const CODE_LENGTH = 80;

    public const BASE_DURATION_MINUTES = 480;

    public const TOTAL_DURATION_MINUTES = 720;

    // The furthest a booking may be made ahead, in days; resources/js/lib/booking.ts mirrors it.
    public const ADVANCE_DAYS = 365;
}
