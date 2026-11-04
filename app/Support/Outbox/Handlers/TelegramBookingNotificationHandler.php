<?php

namespace App\Support\Outbox\Handlers;

use App\Contracts\OutboxHandler;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Support\TelegramBookingSender;
use App\Support\TelegramDeliveryException;
use Illuminate\Support\Facades\Log;
use LogicException;

final class TelegramBookingNotificationHandler implements OutboxHandler
{
    public function __construct(private readonly TelegramBookingSender $sender) {}

    public function handle(OutboxMessage $message): void
    {
        if (! config('services.telegram.booking_notifications_enabled')) {
            return;
        }

        $bookingId = $message->payload['booking_id'] ?? null;

        if (! is_int($bookingId) || $bookingId <= 0) {
            throw new OutboxPermanentException('payload');
        }

        $booking = Booking::query()->find($bookingId);

        if (! $booking instanceof Booking) {
            Log::error('Telegram booking notification failed permanently.', [
                'category' => 'booking_missing',
            ]);

            throw new OutboxPermanentException('booking_missing');
        }

        try {
            $messageId = $this->sender->send($booking);
        } catch (LogicException $exception) {
            // Only the sender's own permanent categories are absorbed; anything
            // else is unexpected and must stay retryable.
            if (! in_array($exception->getMessage(), ['configuration', 'formatting'], true)) {
                throw $exception;
            }

            Log::error('Telegram booking notification failed permanently.', [
                'category' => $exception->getMessage(),
                'booking_reference' => $booking->reference,
            ]);

            throw new OutboxPermanentException($exception->getMessage());
        } catch (TelegramDeliveryException $exception) {
            $category = $exception->httpStatus() === null ? 'network' : 'telegram_http';
            $context = [
                'category' => $category,
                'booking_reference' => $booking->reference,
            ];

            if ($exception->httpStatus() !== null) {
                $context['http_status'] = $exception->httpStatus();
            }

            if ($exception->telegramErrorCode() !== null) {
                $context['error_code'] = $exception->telegramErrorCode();
            }

            Log::warning('Telegram booking notification delivery failed.', $context);

            if ($exception->permanent()) {
                throw new OutboxPermanentException($category);
            }

            throw new OutboxRetryableException($category);
        }

        Log::info('Telegram booking notification sent.', [
            'booking_reference' => $booking->reference,
            'message_id' => $messageId,
        ]);
    }
}
