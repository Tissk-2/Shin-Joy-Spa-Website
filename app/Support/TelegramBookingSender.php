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

    /**
     * Edits the recorded staff message to the booking's latest state and removes its buttons.
     * Returns true when the message is up to date, and false when every round was overtaken.
     *
     * With $onlyIfBehind (the catch-up after a first send), it edits only when a decision committed while that
     * message was rendered or in flight; a routine first send makes no extra call.
     */
    public function refresh(Booking $booking, bool $onlyIfBehind = false): bool
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.staff_chat_id');

        if (! $this->isNonEmptyString($botToken) || ! $this->isNonEmptyString($chatId)) {
            throw new LogicException('configuration');
        }

        // Each round renders the latest booking under the row lock and edits outside it. A round whose render was
        // overtaken by a newer one may have landed its edit after that one's, so it renders and edits again.
        for ($round = 1; $round <= 3; $round++) {   // mutation R6
            $render = DB::transaction(function () use ($booking, $chatId, $onlyIfBehind, $round): ?array {
                // Write first, as send() does, so SQLite takes its write lock before any read. Only a recorded message
                // is edited: a send still in flight renders the latest state itself and then catches up.
                $renderKey = bin2hex(random_bytes(16));
                $marked = TelegramBookingMessage::query()->where('booking_id', $booking->id)->where('chat_id', $chatId)
                    ->whereNotNull('message_id')->update(['render_key' => $renderKey]);   // mutation R4
                if ($marked !== 1) {
                    return null;
                }

                $record = TelegramBookingMessage::query()->where('booking_id', $booking->id)->where('chat_id', $chatId)
                    ->lockForUpdate()->firstOrFail();
                $fresh = Booking::query()->findOrFail($booking->id);
                // Only the first round may skip: a later round exists because an older edit may have landed last.
                if ($onlyIfBehind && $round === 1 && $record->booking_version >= $fresh->booking_version) {   // mutations B, B2
                    return null;
                }

                try {
                    $text = $this->formatter->format($fresh);
                } catch (LogicException $exception) {
                    throw new LogicException('formatting', 0, $exception);
                }

                return [$record, $fresh, $renderKey, $text, $this->keyboard->forBooking($fresh, $record)];
            }, 3);

            if ($render === null) {
                return true;
            }
            [$record, $fresh, $renderKey, $text, $keyboard] = $render;

            // A booking that can't be acted on any more gets an empty keyboard, which removes the buttons.
            $outcome = $this->client->editMessage($record->chat_id, $record->message_id, $text, $keyboard ?? ['inline_keyboard' => []]);

            if ($outcome === 'missing') {   // mutation R5
                // Someone deleted the message. It is never re-posted: only a pending booking could use its buttons, and a
                // refresh only follows a decision. The dashboard stays the source of truth.
                TelegramBookingMessage::query()->whereKey($record->id)->where('render_key', $renderKey)
                    ->update(['message_id' => null, 'state' => 'deleted']);

                return true;
            }

            $saved = TelegramBookingMessage::query()->whereKey($record->id)->where('render_key', $renderKey)
                ->update(['booking_version' => $fresh->booking_version]);
            if ($saved === 1) {   // mutation R2
                return true;
            }
        }

        return false;
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
