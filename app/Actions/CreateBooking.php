<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Support\BookingQuoteResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class CreateBooking
{
    public function __construct(private readonly BookingQuoteResolver $quoteResolver) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    public function __invoke(User $user, array $data): Booking
    {
        $fingerprint = $this->fingerprint($data);

        return DB::transaction(function () use ($user, $data, $fingerprint): Booking {
            $existing = $this->existingBooking($user, (string) $data['submission_key']);

            if ($existing instanceof Booking) {
                return $this->matchingBooking($existing, $fingerprint);
            }

            $bookingAttributes = $this->quoteResolver->resolve($user, $data)->bookingAttributes();

            try {
                return Booking::query()->create([
                    'user_id' => $user->id,
                    'reference' => $this->reference(),
                    'submission_key' => (string) $data['submission_key'],
                    'request_fingerprint' => $fingerprint,
                    'status' => BookingStatus::Pending,
                    ...$bookingAttributes,
                ]);
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
        });
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
    private function fingerprint(array $attributes): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($attributes),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->canonicalize($nested);
            }
        }

        return $value;
    }

    private function reference(): string
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
