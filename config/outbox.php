<?php

use App\Support\Outbox\Handlers\TelegramBookingNotificationHandler;
use App\Support\Telegram\TelegramCallbackAnswerHandler;
use App\Support\Telegram\TelegramDeclinePromptHandler;
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
        // Lifecycle events share booking.created's telegram key, so one handler owns the staff message: it edits
        // that message to the booking's current status.
        'booking.approved' => [
            'telegram' => TelegramBookingNotificationHandler::class,
        ],
        'booking.confirmed' => [
            'telegram' => TelegramBookingNotificationHandler::class,
        ],
        'booking.cancelled' => [
            'telegram' => TelegramBookingNotificationHandler::class,
        ],
        'booking.completed' => [
            'telegram' => TelegramBookingNotificationHandler::class,
        ],
        'booking.no_show' => [
            'telegram' => TelegramBookingNotificationHandler::class,
        ],
        'telegram.update.received' => [
            'telegram' => TelegramUpdateHandler::class,
        ],
        'telegram.reply' => [
            'telegram' => TelegramReplyHandler::class,
        ],
        'telegram.callback.answer' => [
            'telegram' => TelegramCallbackAnswerHandler::class,
        ],
        'telegram.decline.prompt' => [
            'telegram' => TelegramDeclinePromptHandler::class,
        ],
    ],
    'lease_seconds' => 300,
    'max_attempts' => 5,
    'backoff_seconds' => [60, 300, 900, 3600],
];
