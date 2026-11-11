<?php

return [
    'scheduling_enabled' => env('BOOKING_SCHEDULING_ENABLED', false),
    // Customer status emails; off until real mail delivery has been checked. true/1/yes/on turn them on, anything
    // else is off: the handler checks for exactly true, so a "1" left as a string would skip every email.
    'customer_notices_enabled' => filter_var(env('BOOKING_CUSTOMER_NOTICES_ENABLED', false), FILTER_VALIDATE_BOOL),
    'online_payments_enabled' => false,
    'minimum_provider_checkout_minutes' => null,
];
