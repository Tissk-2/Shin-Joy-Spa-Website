<?php

namespace App\Support;

use App\Models\Booking;
use LogicException;

final class TelegramBookingSender
{
    public function __construct(
        private readonly TelegramBookingMessageFormatter $formatter,
        private readonly TelegramClient $client,
    ) {}

    public function send(Booking $booking): int
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.staff_chat_id');

        if (! $this->isNonEmptyString($botToken) || ! $this->isNonEmptyString($chatId)) {
            throw new LogicException('configuration');
        }

        try {
            $message = $this->formatter->format($booking);
        } catch (LogicException $exception) {
            throw new LogicException('formatting', 0, $exception);
        }

        return $this->client->sendMessage(
            $message,
            $chatId,
            $this->messageThreadId(),
        );
    }

    private function messageThreadId(): ?int
    {
        $messageThreadId = config('services.telegram.message_thread_id');

        if ($messageThreadId === null || $messageThreadId === '') {
            return null;
        }

        return (int) $messageThreadId;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
