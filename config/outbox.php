<?php

use App\Support\Outbox\Handlers\TelegramBookingNotificationHandler;

return [
    'events' => [
        // The handler key is stable: deliveries persist it, so it must never be
        // renamed. An explicitly configured empty map is valid and distinct from
        // an unknown event type.
        'booking.created' => [
            'telegram' => TelegramBookingNotificationHandler::class,
        ],
    ],
    'lease_seconds' => 300,
    'max_attempts' => 5,
    'backoff_seconds' => [60, 300, 900, 3600],
];
