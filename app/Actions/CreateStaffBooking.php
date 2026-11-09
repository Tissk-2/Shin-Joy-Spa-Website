<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\ActionContext;
use App\Support\BookingQuoteResolver;
use App\Support\Bookings\BookingSchedule;
use App\Support\Scheduling\BookingSubmissionAvailability;
use App\Support\Staff\StaffAccessLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateStaffBooking
{
    public function __construct(
        private readonly BookingQuoteResolver $quoteResolver,
        private readonly BookingSubmissionAvailability $availability,
    ) {}

    /** @param array<string, mixed> $data StoreStaffBookingRequest::validatedStaffBookingData() */
    public function __invoke(User $actor, array $data, bool $approve, ActionContext $context, bool $operationalConfirmed = false): Booking
    {
        $fingerprint = CreateBooking::fingerprint([...$data, 'approve' => $approve]);

        // ponytail: the staff guard serialises every staff writer, so a same-key race never reaches the unique index;
        // no QueryException replay fallback. Add one if staff writes ever leave the guard.
        return (new StaffAccessLock)->run(function () use ($actor, $data, $approve, $context, $operationalConfirmed, $fingerprint): Booking {
            if (! $actor->exists || ! $actor->getKey() || $actor->getConnection() !== DB::connection()) {
                throw new AuthorizationException;
            }
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            if (! $lockedActor || ! (new BookingPolicy)->create($lockedActor)) {
                throw new AuthorizationException;
            }

            $existing = Booking::query()
                ->where('created_by_staff_id', $lockedActor->id)
                ->where('submission_key', (string) $data['submission_key'])
                ->first();
            if ($existing instanceof Booking) {
                if (hash_equals($existing->request_fingerprint, $fingerprint)) {
                    return $existing; // a replay returns the booking as it is now and never reruns approval
                }
                throw ValidationException::withMessages([
                    'submission_key' => __('validation.unique', ['attribute' => 'submission key']),
                ]);
            }

            $this->availability->validate($data);
            $attributes = $this->quoteResolver->resolveForContact($data, $data['email'])->bookingAttributes();
            if ($attributes['total_price_idr'] !== $data['expected_total_price_idr']) {
                throw ValidationException::withMessages([
                    'expected_total_price_idr' => __('validation.booking.price_changed'),
                ]);
            }

            $booking = (new Booking)->forceFill([
                ...$attributes,
                'user_id' => null,
                'created_by_staff_id' => $lockedActor->id,
                'reference' => CreateBooking::reference(),
                'submission_key' => (string) $data['submission_key'],
                'request_fingerprint' => $fingerprint,
                'status' => BookingStatus::Pending,
                'payment_preference' => 'at_spa', // ponytail: no accountless online checkout in this release
                'source_channel' => $data['source'],
            ]);
            $schedule = new BookingSchedule;
            $booking->forceFill([
                'starts_at' => $schedule->startsAt($booking),
                'treatment_ends_at' => $schedule->treatmentEndsAt($booking),
            ])->save();

            if (! $approve) {
                return $booking;
            }

            // Same staff guard and transaction: an approval failure rolls back the new booking too.
            return (new ApproveBooking)($lockedActor, $booking->id, BookingStatus::Pending, $context, $operationalConfirmed);
        });
    }
}
