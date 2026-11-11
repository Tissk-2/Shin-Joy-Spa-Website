<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Support\BookingQuoteResolver;
use App\Support\Bookings\BookingSchedule;
use App\Support\Outbox\BookingCreatedOutbox;
use App\Support\Scheduling\BookingSubmissionAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class CreateBooking
{
    public function __construct(
        private readonly BookingQuoteResolver $quoteResolver,
        private readonly BookingSubmissionAvailability $availability,
        private readonly BookingCreatedOutbox $bookingOutbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    public function __invoke(User $user, array $data): Booking
    {
        $fingerprint = $this->fingerprint($data);

        try {
            return DB::transaction(function () use ($user, $data, $fingerprint): Booking {
                $existing = $this->existingBooking($user, (string) $data['submission_key']);

                if ($existing instanceof Booking) {
                    return $this->matchingBooking($existing, $fingerprint);
                }

                $this->availability->validate($data);

                $bookingAttributes = $this->quoteResolver->resolve($user, $data)->bookingAttributes();

                if ($bookingAttributes['total_price_idr'] !== $data['expected_total_price_idr']) {
                    throw ValidationException::withMessages([
                        'expected_total_price_idr' => __('validation.booking.price_changed'),
                    ]);
                }

                $booking = (new Booking)->forceFill([
                    'user_id' => $user->id,
                    'reference' => $this->reference(),
                    'submission_key' => (string) $data['submission_key'],
                    'request_fingerprint' => $fingerprint,
                    'status' => BookingStatus::Pending,
                    ...$bookingAttributes,
                    'payment_preference' => 'at_spa',
                    'source_channel' => 'website',
                    'locale' => app()->getLocale(),   // SetLocale has set the visitor's site language (id|en)
                ]);
                $schedule = new BookingSchedule;
                $booking->forceFill([
                    'starts_at' => $schedule->startsAt($booking),
                    'treatment_ends_at' => $schedule->treatmentEndsAt($booking),
                ])->save();

                if (config('services.telegram.booking_notifications_enabled')) {
                    $this->bookingOutbox->record($booking);
                }

                return $booking;
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraint($exception)) {
                throw $exception;
            }

            $existing = $this->existingBooking($user, (string) $data['submission_key']);

            if (! $existing instanceof Booking) {
                throw $exception;
            }

            return $this->matchingBooking($existing, $fingerprint);
        }
    }

    private function existingBooking(User $user, string $submissionKey): ?Booking
    {
        return Booking::query()
            ->where('user_id', $user->id)
            ->where('submission_key', $submissionKey)
            ->first();
    }

    private function matchingBooking(Booking $booking, string $fingerprint): Booking
    {
        if (hash_equals($booking->request_fingerprint, $fingerprint)) {
            return $booking;
        }

        throw ValidationException::withMessages([
            'submission_key' => __('validation.unique', ['attribute' => 'submission key']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public static function fingerprint(array $attributes): string
    {
        unset($attributes['submission_key'], $attributes['expected_total_price_idr']);

        return hash('sha256', json_encode(
            self::canonicalize($attributes),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function canonicalize(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = self::canonicalize($nested);
            }
        }

        return $value;
    }

    public static function reference(): string
    {
        return 'SJ-'.Str::ulid()->toBase32();
    }

    private function isUniqueConstraint(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }
}
