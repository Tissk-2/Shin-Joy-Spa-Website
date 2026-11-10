<?php

namespace App\Support\Telegram;

use App\Contracts\OutboxHandler;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\OutboxMessage;
use App\Support\TelegramClient;
use App\Support\TelegramDeliveryException;
use Illuminate\Support\Facades\Log;

final class TelegramCallbackAnswerHandler implements OutboxHandler
{
    public function __construct(private readonly TelegramClient $client) {}

    public function handle(OutboxMessage $message): void
    {
        $callbackQueryId = $message->payload['callback_query_id'] ?? null;
        $text = $message->payload['text'] ?? null;
        $showAlert = $message->payload['show_alert'] ?? null;
        if (! is_string($callbackQueryId) || $callbackQueryId === '' || ! is_string($text) || $text === '' || ! is_bool($showAlert)) {
            throw new OutboxPermanentException('payload');
        }
        if (TelegramUpdateRecorder::botId() === null) {
            throw new OutboxPermanentException('configuration');
        }

        try {
            $this->client->answerCallbackQuery($callbackQueryId, $text, $showAlert);
        } catch (TelegramDeliveryException $exception) {
            // Telegram refuses a late answer with HTTP 400. That ends this delivery only; the
            // booking decision has already committed and is never retried to fix an answer.
            $category = $exception->httpStatus() === null ? 'network' : 'telegram_http';
            Log::warning('telegram.callback_answer.failed', ['category' => $category, 'http_status' => $exception->httpStatus()]);

            throw $exception->permanent()
                ? new OutboxPermanentException($category)
                : new OutboxRetryableException($category);
        }
    }
}
