<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\TelegramBookingMessage;
use App\Support\Telegram\BookingActionKeyboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TelegramBookingSender
{
    public function __construct(
        private readonly TelegramBookingMessageFormatter $formatter,
        private readonly TelegramClient $client,
        private readonly BookingActionKeyboard $keyboard,
    ) {}

    public function send(Booking $booking): int
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.staff_chat_id');

        if (! $this->isNonEmptyString($botToken) || ! $this->isNonEmptyString($chatId)) {
            throw new LogicException('configuration');
        }

        $threadId = $this->messageThreadId();

        try {
            $message = $this->formatter->format($booking);
        } catch (LogicException $exception) {
            throw new LogicException('formatting', 0, $exception);
        }

        // Two attempts can overlap: a job that starts late in its lease is still sending when the
        // delivery is leased again. Write first, so SQLite takes its write lock before any read,
        // then lock the row. A delivered message is never sent again, and each render gets a key.
        [$delivered, $recordId, $renderKey, $keyboard] = DB::transaction(function () use ($booking, $chatId, $threadId): array {
            $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
            DB::table('telegram_booking_messages')->insertOrIgnore([
                'booking_id' => $booking->id,
                'chat_id' => $chatId,
                'message_thread_id' => $threadId,
                'booking_version' => $booking->booking_version,
                'state' => 'sending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $record = TelegramBookingMessage::query()->where('booking_id', $booking->id)->where('chat_id', $chatId)
                ->lockForUpdate()->firstOrFail();
            if ($record->message_id !== null) {
                return [$record->message_id, $record->id, null, null];
            }

            $renderKey = bin2hex(random_bytes(16));
            $record->forceFill([
                'message_thread_id' => $threadId,
                'booking_version' => $booking->booking_version,
                'render_key' => $renderKey,
            ])->save();

            return [null, $record->id, $renderKey, $this->keyboard->forBooking($booking, $record)];
        }, 3);

        if ($delivered !== null) {
            return $delivered;
        }

        $messageId = $this->client->sendMessage($message, $chatId, $threadId, $keyboard);

        // Only the latest render may record its message. An attempt that rendered after this one
        // replaced these buttons' tokens, so the row must keep pointing at that attempt's copy.
        TelegramBookingMessage::query()->whereKey($recordId)->where('render_key', $renderKey)->update([
            'message_id' => $messageId,
            'state' => 'sent',
            'sent_at' => CarbonImmutable::now('UTC')->setMicrosecond(0),
        ]);

        return $messageId;
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
