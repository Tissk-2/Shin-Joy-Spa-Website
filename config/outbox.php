<?php

use App\Support\Outbox\Handlers\TelegramBookingNotificationHandler;
use App\Support\Telegram\TelegramReplyHandler;
use App\Support\Telegram\TelegramUpdateHandler;

return [
    'events' => [
        // The handler key is stable: deliveries persist it, so it must never be
        // renamed. An explicitly configured empty map is valid and distinct from
        // an unknown event type.
        'booking.created' => [
            'telegram' => TelegramBookingNotificationHandler::class,
        ],
        // Lifecycle events are recorded for later consumers; no delivery yet.
        'booking.approved' => [],
        'booking.confirmed' => [],
        'booking.cancelled' => [],
        'booking.completed' => [],
        'booking.no_show' => [],
        'telegram.update.received' => [
            'telegram' => TelegramUpdateHandler::class,
        ],
        'telegram.reply' => [
            'telegram' => TelegramReplyHandler::class,
        ],
    ],
    'lease_seconds' => 300,
    'max_attempts' => 5,
    'backoff_seconds' => [60, 300, 900, 3600],
];
