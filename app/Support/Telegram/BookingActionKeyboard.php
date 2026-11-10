<?php

namespace App\Support\Telegram;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\TelegramActionToken;
use App\Models\TelegramBookingMessage;
use Carbon\CarbonImmutable;

final class BookingActionKeyboard
{
    public const CONFIRM_LABEL = 'Confirm: therapists checked for all guests';

    /** The one strict flag check, shared with callback processing: only the value true enables actions. */
    public static function actionsEnabled(): bool
    {
        return config('services.telegram.actions_enabled') === true;
    }

    /**
     * Replaces this message's unconsumed tokens and returns its inline keyboard, or null when the
     * message must carry no buttons. Call inside a transaction. Because a retried send always gets
     * fresh tokens, a copy left behind by a lost response only has dead buttons.
     *
     * @return array{inline_keyboard: list<list<array<string, string>>>}|null
     */
    public function forBooking(Booking $booking, TelegramBookingMessage $message): ?array
    {
        $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
        TelegramActionToken::query()->where('telegram_booking_message_id', $message->id)
            ->whereNull('consumed_at')->delete();

        // Callbacks carry the chat ID as a canonical integer, so a padded or named chat could never match.
        if (! self::actionsEnabled()
            || $booking->status !== BookingStatus::Pending
            || $booking->starts_at === null || $booking->starts_at <= $now
            || preg_match('/\A-?[1-9]\d{0,19}\z/', $message->chat_id) !== 1) {
            return null;
        }

        $approve = $this->issue($booking, $message, 'approve');
        $decline = $this->issue($booking, $message, 'decline_prompt');
        $second = [['text' => 'Decline', 'callback_data' => 't:'.$decline]];
        // Same URL as the message's "Admin:" line.
        $url = rtrim((string) config('app.url'), '/').'/admin/bookings/'.$booking->reference;
        if (self::linkable($url)) {
            $second[] = ['text' => 'View booking', 'url' => $url];
        }

        return ['inline_keyboard' => [
            [['text' => self::CONFIRM_LABEL, 'callback_data' => 't:'.$approve]],
            $second,
        ]];
    }

    /**
     * Telegram rejects the whole message (HTTP 400) when a button links to localhost or an IP
     * address, and the booking notification would then never arrive. Only https on a dotted name.
     */
    private static function linkable(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($url, 'https://')
            && str_contains($host, '.')
            && ! str_ends_with($host, '.localhost')
            && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false;
    }

    /** Stores the token and returns its nonce, which only the button carries. */
    private function issue(Booking $booking, TelegramBookingMessage $message, string $action): string
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        TelegramActionToken::query()->create([
            'telegram_booking_message_id' => $message->id,
            'booking_id' => $booking->id,
            'booking_version' => $booking->booking_version,
            'action' => $action,
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => $booking->starts_at,
        ]);

        return $nonce;
    }
}
