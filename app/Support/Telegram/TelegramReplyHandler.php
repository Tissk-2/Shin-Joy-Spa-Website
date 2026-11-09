<?php

namespace App\Support\Telegram;

use App\Contracts\OutboxHandler;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\OutboxMessage;
use App\Support\TelegramClient;
use App\Support\TelegramDeliveryException;
use Illuminate\Support\Facades\Log;

final class TelegramReplyHandler implements OutboxHandler
{
    public function __construct(private readonly TelegramClient $client) {}

    public function handle(OutboxMessage $message): void
    {
        $chatId = $message->payload['chat_id'] ?? null;
        $text = $message->payload['text'] ?? null;
        if (! is_int($chatId) || $chatId <= 0 || ! is_string($text) || $text === '') {
            throw new OutboxPermanentException('payload');
        }
        if (TelegramUpdateRecorder::botId() === null) {
            throw new OutboxPermanentException('configuration');
        }

        try {
            $this->client->sendMessage($text, (string) $chatId);
        } catch (TelegramDeliveryException $exception) {
            $category = $exception->httpStatus() === null ? 'network' : 'telegram_http';
            Log::warning('telegram.reply.failed', ['category' => $category, 'http_status' => $exception->httpStatus()]);

            throw $exception->permanent()
                ? new OutboxPermanentException($category)
                : new OutboxRetryableException($category);
        }
    }
}
