<?php

namespace Tests\Feature;

use App\Actions\ApproveBooking;
use App\Actions\CancelBooking;
use App\Actions\CompleteBooking;
use App\Actions\RecordNoShow;
use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\OutboxMessage;
use App\Models\RoomReservation;
use App\Models\User;
use App\Support\ActionContext;
use App\Support\Bookings\BookingSchedule;
use App\Support\Bookings\BookingStateWriter;
use App\Support\Scheduling\RoomAllocator;
use App\Support\Scheduling\ScheduleLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\Scheduling\RoomAllocationTest;
use Tests\TestCase;

// DatabaseMigrations keeps each test outside a wrapping transaction so the
// state writer's own transaction guard (item 10) can be asserted directly.
class BookingLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    private const NOW = '2026-11-04 09:00:00';

    // The lifecycle statuses written here cannot be rolled back by the additive
    // migration's down path, so each test starts from a fresh schema instead.
    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);

        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_approval_confirms_at_spa_and_legacy_bookings_with_audit_and_event(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        $actor = User::factory()->bookingStaff()->create();

        foreach ([null, 'at_spa'] as $preference) {
            $booking = Booking::factory()->create(['payment_preference' => $preference]);

            (new ApproveBooking)($actor, $booking->id, BookingStatus::Pending, $this->context());

            $fresh = $booking->fresh();
            $this->assertSame(BookingStatus::Confirmed, $fresh->status);
            $this->assertSame(2, $fresh->booking_version);
            $this->assertTrue($fresh->approved_at->equalTo(CarbonImmutable::parse(self::NOW, 'UTC')));

            $change = BookingStatusChange::query()->where('booking_id', $booking->id)->latest('id')->firstOrFail();
            $this->assertSame(BookingStatus::Pending, $change->from_status);
            $this->assertSame(BookingStatus::Confirmed, $change->to_status);
            $this->assertSame($actor->id, $change->admin_user_id);
            $this->assertSame($actor->name, $change->actor_name);
            $this->assertSame($actor->email, $change->actor_email);
            $this->assertSame('staff', $change->actor_type);
            $this->assertSame('dashboard', $change->channel);
            $this->assertSame('203.0.113.10', $change->ip_address);
            $this->assertSame('Booking lifecycle test', $change->user_agent);

            $message = OutboxMessage::query()
                ->where('type', 'booking.approved')
                ->where('deduplication_key', "booking.approved:{$booking->id}:2")
                ->firstOrFail();
            $this->assertSame(['booking_id' => $booking->id, 'booking_version' => 2], $message->payload);
            $this->assertSame('{"booking_id":'.$booking->id.',"booking_version":2}', $message->getRawOriginal('payload'));
            $this->assertSame(0, $message->deliveries()->count());
        }

        $this->assertSame(2, OutboxMessage::query()->where('type', 'booking.approved')->count());
    }

    public function test_approval_with_scheduling_allocates_and_conflicts_roll_back(): void
    {
        config(['booking.scheduling_enabled' => true]);
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        $actor = User::factory()->bookingStaff()->create();

        (new ApproveBooking)($actor, $booking->id, BookingStatus::Pending, $this->context(), true);

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $reservation = RoomReservation::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($room->id, $reservation->room_id);
        $change = BookingStatusChange::query()->where('booking_id', $booking->id)->latest('id')->firstOrFail();
        $this->assertNotNull($change->operational_confirmed_at);
        $this->assertSame(0, $change->operational_confirmed_at->micro);
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.approved')->count());

        $second = $booking->replicate(['reference', 'submission_key']);
        $second->reference = 'conflicting-approval';
        $second->submission_key = 'conflicting-approval';
        $second->save();

        try {
            (new ApproveBooking)($actor, $second->id, BookingStatus::Pending, $this->context(), true);
            $this->fail('An overlapping approval should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('admin.status.room_unavailable')], $exception->errors()['room']);
        }

        $this->assertSame(BookingStatus::Pending, $second->fresh()->status);
        $this->assertSame(1, $second->fresh()->booking_version);
        $this->assertNull($second->fresh()->approved_at);
        $this->assertSame(0, RoomReservation::query()->where('booking_id', $second->id)->count());
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.approved')->count());
        $this->assertSame(0, BookingStatusChange::query()->where('booking_id', $second->id)->count());
    }

    public function test_stale_expected_status_fails_without_writes(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        try {
            (new ApproveBooking)(
                User::factory()->bookingStaff()->create(),
                $booking->id,
                BookingStatus::Pending,
                $this->context(),
            );
            $this->fail('A stale approval should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('admin.status.stale')], $exception->errors()['currentStatus']);
        }

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(1, $booking->fresh()->booking_version);
        $this->assertNull($booking->fresh()->approved_at);
        $this->assertDatabaseCount('booking_status_changes', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_online_payment_approval_is_rejected_while_payments_are_disabled(): void
    {
        config(['booking.online_payments_enabled' => false]);
        $booking = Booking::factory()->create(['payment_preference' => 'online']);

        try {
            (new ApproveBooking)(
                User::factory()->bookingStaff()->create(),
                $booking->id,
                BookingStatus::Pending,
                $this->context(),
            );
            $this->fail('Online payment approval should be rejected while payments are disabled.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('admin.status.online_payments_disabled')], $exception->errors()['nextStatus']);
        }

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertSame(1, $booking->fresh()->booking_version);
        $this->assertNull($booking->fresh()->approved_at);
        $this->assertDatabaseCount('room_reservations', 0);
        $this->assertDatabaseCount('booking_status_changes', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_cancellation_stamps_full_refund_and_records_event(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        (new CancelBooking)(
            User::factory()->bookingStaff()->create(),
            $booking->id,
            BookingStatus::Pending,
            'Customer requested cancellation',
            $this->context(),
        );

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Cancelled, $fresh->status);
        $this->assertSame(2, $fresh->booking_version);
        $this->assertTrue($fresh->refund_eligible_at->equalTo(CarbonImmutable::parse(self::NOW, 'UTC')));
        $this->assertSame('cancelled_by_staff', $fresh->refund_reason);

        $change = BookingStatusChange::query()->where('booking_id', $booking->id)->latest('id')->firstOrFail();
        $this->assertSame('Customer requested cancellation', $change->reason);
        $this->assertSame('dashboard', $change->channel);
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.cancelled')->count());
        $this->assertDatabaseHas('outbox_messages', [
            'type' => 'booking.cancelled',
            'deduplication_key' => "booking.cancelled:{$booking->id}:2",
            'payload' => '{"booking_id":'.$booking->id.',"booking_version":2}',
        ]);
    }

    public function test_cancellation_releases_future_at_spa_hold_but_retains_online_hold(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC'));
        $actor = User::factory()->bookingStaff()->create();
        [$atSpa] = (new RoomAllocationTest('fixture'))->fixture();
        DB::table('bookings')->where('id', $atSpa->id)->update(['status' => 'confirmed']);
        $atSpaHold = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($atSpa->fresh()));

        (new CancelBooking)($actor, $atSpa->id, BookingStatus::Confirmed, null, $this->context());

        $this->assertNotNull($atSpaHold->fresh()->released_at);
        $this->assertSame('booking_cancelled', $atSpaHold->fresh()->release_reason);

        $online = $atSpa->replicate(['reference', 'submission_key']);
        $online->reference = 'online-retained-hold';
        $online->submission_key = 'online-retained-hold';
        $online->status = BookingStatus::Confirmed;
        $online->payment_preference = 'online';
        $online->save();
        $onlineHold = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($online->fresh()));

        (new CancelBooking)($actor, $online->id, BookingStatus::Confirmed, null, $this->context());

        $this->assertSame(BookingStatus::Cancelled, $online->fresh()->status);
        $this->assertNotNull($online->fresh()->refund_eligible_at);
        $this->assertNull($onlineHold->fresh()->released_at);
        $this->assertNull($onlineHold->fresh()->release_reason);
    }

    public function test_no_show_requires_the_treatment_end_and_stamps_refund(): void
    {
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'confirmed']);
        $hold = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking->fresh()));
        $endsAt = (new BookingSchedule)->treatmentEndsAt($booking->fresh());
        $this->assertSame('2026-10-25 04:30:00', $endsAt->format('Y-m-d H:i:s'));
        $actor = User::factory()->bookingStaff()->create();

        $this->travelTo($endsAt->subSecond());
        try {
            (new RecordNoShow)($actor, $booking->id, BookingStatus::Confirmed, $this->context());
            $this->fail('A no-show before the treatment end should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('admin.status.no_show_too_early')], $exception->errors()['nextStatus']);
        }
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(1, $booking->fresh()->booking_version);
        $this->assertNull($booking->fresh()->refund_eligible_at);
        $this->assertSame(0, OutboxMessage::query()->where('type', 'booking.no_show')->count());

        $this->travelTo($endsAt);
        (new RecordNoShow)($actor, $booking->id, BookingStatus::Confirmed, $this->context());

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::NoShow, $fresh->status);
        $this->assertSame(2, $fresh->booking_version);
        $this->assertTrue($fresh->refund_eligible_at->equalTo($endsAt));
        $this->assertSame('no_show', $fresh->refund_reason);
        $this->assertNull($hold->fresh()->released_at);
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.no_show')->count());

        try {
            (new TransitionBookingStatus)($actor, $booking->fresh(), BookingStatus::NoShow, BookingStatus::Cancelled, null, null, null);
            $this->fail('A no-show booking must be terminal.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('admin.status.invalid')], $exception->errors()['nextStatus']);
        }
        $this->assertCancelRejectedByWriter($actor, $booking->id, BookingStatus::NoShow);
        $this->assertDatabaseCount('booking_status_changes', 1);
    }

    public function test_completion_records_event_and_is_terminal(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);
        $this->travelTo((new BookingSchedule)->startsAt($booking));

        (new CompleteBooking)(
            User::factory()->bookingStaff()->create(),
            $booking->id,
            BookingStatus::Confirmed,
            $this->context(),
        );

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Completed, $fresh->status);
        $this->assertSame(2, $fresh->booking_version);
        $this->assertNull($fresh->refund_eligible_at);
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.completed')->count());
        $this->assertDatabaseHas('outbox_messages', [
            'type' => 'booking.completed',
            'deduplication_key' => "booking.completed:{$booking->id}:2",
            'payload' => '{"booking_id":'.$booking->id.',"booking_version":2}',
        ]);

        try {
            (new TransitionBookingStatus)(
                User::factory()->bookingStaff()->create(),
                $fresh,
                BookingStatus::Completed,
                BookingStatus::Cancelled,
                null,
                null,
                null,
            );
            $this->fail('A completed booking must be terminal.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('admin.status.invalid')], $exception->errors()['nextStatus']);
        }

        $this->assertCancelRejectedByWriter(User::factory()->bookingStaff()->create(), $booking->id, BookingStatus::Completed);
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 1);
    }

    public function test_completion_is_rejected_before_the_appointment_starts(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);
        $start = (new BookingSchedule)->startsAt($booking);
        $this->travelTo($start->subSecond());

        try {
            (new CompleteBooking)(
                User::factory()->bookingStaff()->create(),
                $booking->id,
                BookingStatus::Confirmed,
                $this->context(),
            );
            $this->fail('A booking cannot be completed before its appointment starts.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('admin.status.complete_too_early')], $exception->errors()['nextStatus']);
        }

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(1, $booking->fresh()->booking_version);
        $this->assertDatabaseCount('booking_status_changes', 0);
        $this->assertSame(0, OutboxMessage::query()->where('type', 'booking.completed')->count());
    }

    public function test_completion_is_allowed_at_the_appointment_start(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);
        $this->travelTo((new BookingSchedule)->startsAt($booking));

        (new CompleteBooking)(
            User::factory()->bookingStaff()->create(),
            $booking->id,
            BookingStatus::Confirmed,
            $this->context(),
        );

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
        $this->assertSame(2, $booking->fresh()->booking_version);
    }

    public function test_status_endpoint_rejects_early_completion(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);
        $this->travelTo((new BookingSchedule)->startsAt($booking)->subSecond());

        $this->actingAs($staff)
            ->from(route('admin.bookings.show', $booking, absolute: false))
            ->patch(route('admin.bookings.status.update', $booking), [
                'currentStatus' => 'confirmed',
                'nextStatus' => 'completed',
                'reason' => null,
            ])
            ->assertRedirect(route('admin.bookings.show', $booking, absolute: false))
            ->assertSessionHasErrors(['nextStatus' => __('admin.status.complete_too_early')]);

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    // The dispatcher's own pair map rejects terminal pairs first, so call the
    // action directly to prove the enum keeps the status terminal.
    private function assertCancelRejectedByWriter(User $actor, int $bookingId, BookingStatus $terminal): void
    {
        try {
            (new CancelBooking)($actor, $bookingId, $terminal, null, $this->context());
            $this->fail("A {$terminal->value} booking must not be cancellable.");
        } catch (ValidationException $exception) {
            $this->assertSame([__('admin.status.invalid')], $exception->errors()['nextStatus']);
        }
        $this->assertSame(0, OutboxMessage::query()->where('type', 'booking.cancelled')->count());
    }

    public function test_unprivileged_admin_cannot_invoke_any_lifecycle_action(): void
    {
        $admin = User::factory()->admin()->create();
        $pendingForApproval = Booking::factory()->create();
        $pendingForCancellation = Booking::factory()->create();
        $confirmedForCompletion = Booking::factory()->create(['status' => BookingStatus::Confirmed]);
        $confirmedForNoShow = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        $operations = [
            fn () => (new ApproveBooking)($admin, $pendingForApproval->id, BookingStatus::Pending, $this->context()),
            fn () => (new CancelBooking)($admin, $pendingForCancellation->id, BookingStatus::Pending, null, $this->context()),
            fn () => (new CompleteBooking)($admin, $confirmedForCompletion->id, BookingStatus::Confirmed, $this->context()),
            fn () => (new RecordNoShow)($admin, $confirmedForNoShow->id, BookingStatus::Confirmed, $this->context()),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('An unprivileged admin reached a lifecycle mutation.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(BookingStatus::Pending, $pendingForApproval->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $pendingForCancellation->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $confirmedForCompletion->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $confirmedForNoShow->fresh()->status);

        foreach ([$pendingForApproval, $pendingForCancellation, $confirmedForCompletion, $confirmedForNoShow] as $booking) {
            $this->assertSame(1, $booking->fresh()->booking_version);
        }

        $this->assertDatabaseCount('booking_status_changes', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_state_writer_requires_an_open_transaction(): void
    {
        $booking = Booking::factory()->create();
        $this->assertSame(0, DB::transactionLevel());

        try {
            (new BookingStateWriter)->transitionLocked(
                $booking,
                BookingStatus::Cancelled,
                null,
                null,
                new ActionContext('system'),
            );
            $this->fail('The state writer must refuse to run outside a transaction.');
        } catch (LogicException $exception) {
            $this->assertSame('Booking state transitions require an active database transaction.', $exception->getMessage());
        }

        // The outbox writer would also throw, but only after the booking and
        // its history were written; the guard must stop before any write.
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertSame(1, $booking->fresh()->booking_version);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_action_context_rejects_unknown_channels_and_caps_metadata(): void
    {
        $context = new ActionContext('telegram', str_repeat('1', 60), str_repeat('a', 1100), 'external-1');
        $this->assertSame('telegram', $context->channel);
        $this->assertSame(45, strlen($context->ipAddress));
        $this->assertSame(1024, strlen($context->userAgent));
        $this->assertSame('external-1', $context->externalActorId);

        $this->expectException(InvalidArgumentException::class);

        new ActionContext('email');
    }

    public function test_status_endpoint_dispatches_no_show_through_the_action(): void
    {
        $actor = User::factory()->bookingStaff()->create();
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'appointment_date' => '2026-11-10',
            'appointment_time' => '10:30',
        ]);
        $endsAt = (new BookingSchedule)->treatmentEndsAt($booking);
        $this->travelTo($endsAt);

        $this->actingAs($actor)
            ->patch(route('admin.bookings.status.update', $booking), [
                'currentStatus' => 'confirmed',
                'nextStatus' => 'no_show',
                'reason' => 'Guest did not arrive',
            ])
            ->assertStatus(303);

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::NoShow, $fresh->status);
        $this->assertSame(2, $fresh->booking_version);
        $this->assertTrue($fresh->refund_eligible_at->equalTo($endsAt));
        $this->assertSame('no_show', $fresh->refund_reason);

        $change = BookingStatusChange::query()->where('booking_id', $booking->id)->latest('id')->firstOrFail();
        $this->assertSame('dashboard', $change->channel);
        $this->assertSame('staff', $change->actor_type);
        $this->assertSame('Guest did not arrive', $change->reason);
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.no_show')->count());
        $this->assertDatabaseHas('outbox_messages', [
            'type' => 'booking.no_show',
            'deduplication_key' => "booking.no_show:{$booking->id}:2",
        ]);
    }

    private function context(): ActionContext
    {
        return new ActionContext('dashboard', '203.0.113.10', 'Booking lifecycle test');
    }
}
