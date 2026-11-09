<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class TelegramBookingMessageFormatter
{
    private const NOTES_LIMIT = 500;

    private const MESSAGE_LIMIT = 4000;

    private const TRUNCATION_MARKER = '[truncated]';

    private const TIMEZONE_LABELS = [
        'Asia/Jakarta' => 'WIB',
    ];

    public function format(Booking $booking): string
    {
        $notes = $this->prepareNotes((string) $booking->notes);
        $message = $this->build($booking, $notes);

        return $this->fitWithinLimit($message, $booking);
    }

    private function build(Booking $booking, ?string $notes): string
    {
        $lines = [
            'NEW BOOKING — SHIN JOY SPA',
            '',
            'Reference: '.$booking->reference,
            'Status: '.$this->statusLabel($booking->status),
            '',
            'CUSTOMER',
            'Name: '.$this->sanitizeField((string) $booking->customer_name),
            'Email: '.$this->sanitizeField((string) $booking->customer_email),
            'Phone/WhatsApp: '.$this->phoneLabel($booking),
            '',
            'APPOINTMENT',
            'Service: '.$this->serviceLabel($booking),
            'Mode: '.$this->modeLabel((string) $booking->treatment_mode),
            'Date: '.$this->dateLabel($booking->appointment_date),
            'Time: '.$booking->appointment_time.' '.$this->timezoneLabel((string) $booking->appointment_timezone),
            'Guests: '.$booking->guests,
            '',
            'DETAILS',
            'Base treatment: '.$booking->base_duration_minutes.' minutes — '.$this->price((int) $booking->base_price_idr),
        ];

        if ($this->hasExtension($booking)) {
            $lines[] = 'Extension: '.$booking->extension_choice_code
                .' — '.$booking->extension_duration_minutes
                .' minutes — '.$this->price((int) $booking->extension_price_idr);
        }

        $lines[] = 'Total duration: '.$booking->total_duration_minutes.' minutes';
        $lines[] = 'Total price: '.$this->price((int) $booking->total_price_idr);

        if ($notes !== null) {
            $lines[] = '';
            $lines[] = 'Notes:';
            $lines[] = $notes;
        }

        $lines[] = '';
        $lines[] = 'Created: '.$this->createdLabel($booking);
        $lines[] = 'Admin: '.$this->adminUrl($booking);

        return implode("\n", $lines);
    }

    /**
     * The assembled message must never exceed Telegram's 4,000-character
     * limit. Notes shrink first and are the only field the formatter may
     * reduce; every other field is bounded by the bookings column limits,
     * which keep the non-note portion well below the limit. If an invalid
     * non-note state ever produced an over-limit message, fail explicitly
     * instead of returning a structurally incomplete payload.
     */
    private function fitWithinLimit(string $message, Booking $booking): string
    {
        if (mb_strlen($message, 'UTF-8') <= self::MESSAGE_LIMIT) {
            return $message;
        }

        $rawNotes = $booking->notes === null
            ? null
            : $this->sanitizeField((string) $booking->notes);
        $cap = self::NOTES_LIMIT;
        $current = $message;

        while ($rawNotes !== null && $cap > 0 && mb_strlen($current, 'UTF-8') > self::MESSAGE_LIMIT) {
            $excess = mb_strlen($current, 'UTF-8') - self::MESSAGE_LIMIT;
            $cap = $excess >= $cap ? 0 : $cap - $excess;
            $current = $this->build($booking, $this->truncate($rawNotes, $cap));
        }

        if (mb_strlen($current, 'UTF-8') > self::MESSAGE_LIMIT) {
            throw new \LogicException(
                'Telegram booking notification exceeds 4,000 characters without notes; persisted data or configuration is invalid.',
            );
        }

        return $current;
    }

    private function prepareNotes(string $notes): ?string
    {
        $sanitized = $this->sanitizeField($notes);

        if ($sanitized === '') {
            return null;
        }

        return $this->truncate($sanitized, self::NOTES_LIMIT);
    }

    private function truncate(string $value, int $limit): ?string
    {
        if ($limit < 1 || $value === '') {
            return null;
        }

        $marker = self::TRUNCATION_MARKER;
        $markerLength = mb_strlen($marker, 'UTF-8');

        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        if ($limit <= $markerLength) {
            return mb_substr($marker, 0, $limit, 'UTF-8');
        }

        return mb_substr($value, 0, $limit - $markerLength, 'UTF-8').$marker;
    }

    private function statusLabel(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Pending => 'Pending',
            BookingStatus::Confirmed => 'Confirmed',
            BookingStatus::Completed => 'Completed',
            BookingStatus::Cancelled => 'Cancelled',
            BookingStatus::AwaitingPayment => 'Awaiting payment',
            BookingStatus::NoShow => 'No-show',
        };
    }

    private function phoneLabel(Booking $booking): string
    {
        $phone = $this->sanitizeField((string) $booking->customer_phone);

        return $phone === '' ? '—' : $phone;
    }

    private function serviceLabel(Booking $booking): string
    {
        $name = $this->sanitizeField((string) $booking->package_name_en);

        if ($name === '') {
            $name = $this->sanitizeField((string) $booking->package_name_id);
        }

        if ($booking->package_is_vip && $name !== '') {
            $name .= ' (VIP)';
        }

        return $name;
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            'regular' => 'Regular',
            'double_therapist' => 'Double therapist',
            default => ucfirst(str_replace('_', ' ', $mode)),
        };
    }

    private function dateLabel(CarbonInterface $date): string
    {
        // Staff-facing month names stay English regardless of app locale.
        return $date->locale('en')->format('j F Y');
    }

    private function createdLabel(Booking $booking): string
    {
        // created_at is stored as a UTC instant; render it in the
        // staff-facing Asia/Jakarta timezone regardless of the persisted
        // appointment timezone.
        return CarbonImmutable::parse($booking->created_at)
            ->setTimezone('Asia/Jakarta')
            ->locale('en')
            ->format('j F Y, H:i')
            .' '.$this->timezoneLabel('Asia/Jakarta');
    }

    private function timezoneLabel(string $timezone): string
    {
        return self::TIMEZONE_LABELS[$timezone] ?? $timezone;
    }

    private function price(int $idr): string
    {
        return 'Rp '.number_format($idr, 0, ',', '.');
    }

    private function hasExtension(Booking $booking): bool
    {
        return $booking->extension_choice_code !== null
            && $booking->extension_duration_minutes !== null
            && $booking->extension_price_idr !== null;
    }

    private function adminUrl(Booking $booking): string
    {
        return rtrim((string) config('app.url'), '/').'/admin/bookings/'.$booking->reference;
    }

    /**
     * Strip Unicode control (Cc) and format (Cf) characters from
     * customer-controlled text, replacing them with a space so words do not
     * join, then collapse every whitespace run to a single space.
     */
    private function sanitizeField(string $value): string
    {
        $value = preg_replace('/\p{Cc}|\p{Cf}/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
