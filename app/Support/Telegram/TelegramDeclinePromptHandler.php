<?php

namespace App\Support\Telegram;

use App\Contracts\OutboxHandler;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\OutboxMessage;
use App\Models\TelegramActionToken;
use App\Support\TelegramClient;
use App\Support\TelegramDeliveryException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

final class TelegramDeclinePromptHandler implements OutboxHandler
{
    public function __construct(private readonly TelegramClient $client) {}

    public function handle(OutboxMessage $message): void
    {
        $chatId = $message->payload['chat_id'] ?? null;
        $threadId = $message->payload['message_thread_id'] ?? null;
        $text = $message->payload['text'] ?? null;
        $sealed = $message->payload['nonce'] ?? null;
        if (! is_string($chatId) || $chatId === '' || ($threadId !== null && ! is_int($threadId))
            || ! is_string($text) || $text === '' || ! is_string($sealed)) {
            throw new OutboxPermanentException('payload');
        }
        if (TelegramUpdateRecorder::botId() === null) {
            throw new OutboxPermanentException('configuration');
        }

        try {
            $nonce = Crypt::decryptString($sealed);
        } catch (DecryptException) {
            throw new OutboxPermanentException('payload');
        }

        // A used or expired confirmation would only post a dead button.
        $live = TelegramActionToken::query()->where('nonce_hash', hash('sha256', $nonce))->whereNull('consumed_at')
            ->where('expires_at', '>', CarbonImmutable::now('UTC')->setMicrosecond(0))->exists();
        if (! $live) {
            return;
        }

        try {
            $this->client->sendMessage($text, $chatId, $threadId, ['inline_keyboard' => [[
                ['text' => ProcessBookingCallback::DECLINE_CONFIRM_LABEL, 'callback_data' => 't:'.$nonce],
            ]]]);
        } catch (TelegramDeliveryException $exception) {
            $category = $exception->httpStatus() === null ? 'network' : 'telegram_http';
            Log::warning('telegram.decline_prompt.failed', ['category' => $category, 'http_status' => $exception->httpStatus()]);

            throw $exception->permanent()
                ? new OutboxPermanentException($category)
                : new OutboxRetryableException($category);
        }
    }
}
