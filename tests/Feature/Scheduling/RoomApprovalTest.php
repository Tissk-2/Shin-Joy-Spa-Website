<?php

namespace Tests\Feature\Scheduling;

use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\RoomReservation;
use App\Models\User;
use App\Support\AdminBookingPresenter;
use App\Support\Scheduling\RoomAllocator;
use App\Support\Scheduling\ScheduleLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoomApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function transition(User $actor, Booking $booking, bool $confirmed = false, BookingStatus $next = BookingStatus::Confirmed)
    {
        return (new TransitionBookingStatus)($actor, $booking, $booking->status, $next, null, null, null, $confirmed);
    }

    public function test_customer_direct_action_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->transition(User::factory()->create(), Booking::factory()->create());
    }

    public function test_enabled_approval_requires_attestation(): void
    {
        config(['booking.scheduling_enabled' => true]);
        $this->expectException(ValidationException::class);
        $this->transition(User::factory()->bookingStaff()->create(), Booking::factory()->create());
    }

    public function test_enabled_approval_allocates_and_attests_atomically(): void
    {
        config(['booking.scheduling_enabled' => true]);
        [$booking,$room] = (new RoomAllocationTest('fixture'))->fixture();
        $actor = User::factory()->bookingStaff()->create();
        $actor->name = 'forged';
        $audit = $this->transition($actor, $booking, true);
        $this->assertNotSame('forged', $audit->actor_name);
        $this->assertNotNull($audit->operational_confirmed_at);
        $this->assertSame(0, $audit->operational_confirmed_at->micro);
        $this->assertDatabaseHas('room_reservations', ['booking_id' => $booking->id, 'room_id' => $room->id]);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->status);
    }

    public function test_stale_revoked_unverified_deleted_and_unsaved_actors_are_denied(): void
    {
        foreach (['revoked', 'unverified', 'deleted', 'unsaved', 'wrong_connection'] as $case) {
            $actor = User::factory()->bookingStaff()->create();
            $booking = Booking::factory()->create();
            match ($case) {
                'revoked' => User::whereKey($actor->id)->update(['role' => 'customer']),
                'unverified' => User::whereKey($actor->id)->update(['email_verified_at' => null]),
                'deleted' => User::whereKey($actor->id)->delete(),
                'unsaved' => $actor = User::factory()->admin()->make(),
                'wrong_connection' => $actor->setConnection('mysql'),
            };
            try {
                $this->transition($actor, $booking);
                $this->fail('Unauthorized action accepted: '.$case);
            } catch (AuthorizationException) {
                $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
            }
        }
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_disabled_legacy_confirm_and_stale_booking(): void
    {
        $actor = User::factory()->bookingStaff()->create();
        $booking = Booking::factory()->create();
        $audit = $this->transition($actor, $booking);
        $this->assertNull($audit->operational_confirmed_at);
        $this->assertDatabaseCount('room_reservations', 0);
        $this->expectException(ValidationException::class);
        $this->transition($actor, $booking);
    }

    public function test_conflict_and_failed_audit_roll_back_all_changes(): void
    {
        config(['booking.scheduling_enabled' => true]);
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        $actor = User::factory()->bookingStaff()->create();
        $second = $booking->replicate(['reference', 'submission_key']);
        $second->reference = 'second';
        $second->submission_key = 'second';
        $second->save();
        $this->transition($actor, $booking, true);
        try {
            $this->transition($actor, $second, true);
            $this->fail('Room conflict accepted');
        } catch (ValidationException $e) {
            $this->assertSame([__('admin.status.room_unavailable')], $e->errors()['room']);
        }
        $this->assertSame(BookingStatus::Pending, $second->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 1);
        $this->assertDatabaseCount('room_reservations', 1);
        $this->travelTo(RoomReservation::firstOrFail()->starts_at->subMinute());
        BookingStatusChange::creating(fn () => throw new \RuntimeException('audit failed'));
        try {
            $this->transition($actor, $booking->fresh(), false, BookingStatus::Cancelled);
            $this->fail('Audit failure ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit failed', $e->getMessage());
        } finally {
            BookingStatusChange::flushEventListeners();
            $this->travelBack();
        }
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertNull(RoomReservation::first()->released_at);
    }

    public function test_cancellation_respects_start_and_feature_toggle_and_completion_retains_cleaning(): void
    {
        foreach ([-1, 0, 1] as $offset) {
            config(['booking.scheduling_enabled' => true]);
            [$booking] = (new RoomAllocationTest('fixture'))->fixture();
            $actor = User::factory()->bookingStaff()->create();
            $this->transition($actor, $booking, true);
            $reservation = RoomReservation::where('booking_id', $booking->id)->firstOrFail();
            $this->travelTo($reservation->starts_at->addSeconds($offset));
            config(['booking.scheduling_enabled' => false]);
            (new TransitionBookingStatus)($actor, $booking, BookingStatus::Confirmed, BookingStatus::Cancelled, str_repeat('x', 500), null, null);
            $this->assertSame($offset < 0, $reservation->fresh()->released_at !== null);
            if ($offset < 0) {
                $this->assertSame('booking_cancelled', $reservation->fresh()->release_reason);
            }
            $this->travelBack();
            DB::table('room_reservations')->delete();
            DB::table('room_service_variant')->delete();
            DB::table('rooms')->delete();
            DB::table('booking_status_changes')->delete();
            DB::table('bookings')->delete();
            DB::table('service_variants')->delete();
            DB::table('services')->delete();
            DB::table('service_categories')->delete();
        }
    }

    public function test_http_rejects_missing_false_and_malformed_attestation(): void
    {
        config(['booking.scheduling_enabled' => true]);
        $booking = Booking::factory()->create();
        $this->actingAs(User::factory()->bookingStaff()->create());
        foreach ([[], ['operationalConfirmed' => false], ['operationalConfirmed' => []], ['operationalConfirmed' => 'garbage']] as $input) {
            $this->patch('/admin/bookings/'.$booking->reference.'/status', ['currentStatus' => 'pending', 'nextStatus' => 'confirmed', ...$input])->assertSessionHasErrors('operationalConfirmed');
        }
    }

    public function test_old_schema_disabled_detail_and_transition_work_but_enabled_fails_closed(): void
    {
        Schema::drop('room_reservations');
        Schema::drop('room_blocks');
        Schema::drop('room_service_variant');
        Schema::drop('rooms');
        Schema::drop('schedule_guards');
        Schema::table('booking_status_changes', fn ($table) => $table->dropColumn('operational_confirmed_at'));
        $actor = User::factory()->bookingStaff()->create();
        $booking = Booking::factory()->create();
        $this->transition($actor, $booking);
        $detail = (new AdminBookingPresenter)->detail($booking->fresh());
        $this->assertNull($detail['assignedRoom']);
        $this->assertNull($detail['history'][0]['operationalConfirmedAt']);
        $this->assertFalse($detail['roomSchedulingEnabled']);
        config(['booking.scheduling_enabled' => true]);
        $this->expectException(ValidationException::class);
        $this->transition($actor, Booking::factory()->create(), true);
    }

    public function test_completion_and_repeated_release_preserve_original_reservation(): void
    {
        config(['booking.scheduling_enabled' => true]);
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        $actor = User::factory()->bookingStaff()->create();
        $this->transition($actor, $booking, true);
        $hold = RoomReservation::firstOrFail();
        $end = $hold->ends_at;
        $this->travelTo($hold->starts_at);
        $this->transition($actor, $booking->fresh(), false, BookingStatus::Completed);
        $this->assertNull($hold->fresh()->released_at);
        $this->assertTrue($end->equalTo($hold->fresh()->ends_at));
        $legacy = Booking::factory()->create(['status' => 'confirmed', 'appointment_date' => '2026-10-24']);
        $this->transition($actor, $legacy, false, BookingStatus::Completed);
        $pending = Booking::factory()->create();
        $this->transition($actor, $pending, false, BookingStatus::Cancelled);
        $this->assertDatabaseCount('room_reservations', 1);
    }

    public function test_audit_failure_rolls_back_fresh_allocation_and_status(): void
    {
        config(['booking.scheduling_enabled' => true]);
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        $actor = User::factory()->bookingStaff()->create();
        BookingStatusChange::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            $this->transition($actor, $booking, true);
            $this->fail('Failed audit committed');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        } finally {
            BookingStatusChange::flushEventListeners();
        }
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('room_reservations', 0);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_detail_is_safe_and_room_failure_is_localized_and_untrusted_fields_rejected(): void
    {
        config(['booking.scheduling_enabled' => true]);
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        $actor = User::factory()->bookingStaff()->create();
        $this->actingAs($actor);
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            $this->withSession(['locale' => $locale])->patch('/admin/bookings/'.$booking->reference.'/status', ['currentStatus' => 'pending', 'nextStatus' => 'confirmed', 'operationalConfirmed' => true, 'room_id' => 123, 'totalPriceIdr' => 1])->assertSessionHasErrors(['room_id', 'totalPriceIdr']);
        }
        $this->transition($actor, $booking, true);
        $detail = (new AdminBookingPresenter)->detail($booking->fresh());
        $this->assertSame(['name', 'code', 'type', 'capacity', 'startsAt', 'endsAt', 'releasedAt'], array_keys($detail['assignedRoom']));
        $this->assertNotNull($detail['history'][0]['operationalConfirmedAt']);
        $other = $booking->replicate(['reference', 'submission_key']);
        $other->reference = 'localized-conflict';
        $other->submission_key = 'localized-conflict';
        $other->save();
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            try {
                $this->transition($actor, $other, true);
                $this->fail('Conflict accepted');
            } catch (ValidationException $error) {
                $this->assertSame([__('admin.status.room_unavailable')], $error->errors()['room']);
            }
        }
    }

    public function test_cancellation_of_already_released_hold_preserves_first_release_evidence(): void
    {
        config(['booking.scheduling_enabled' => true]);
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        $actor = User::factory()->bookingStaff()->create();
        $this->transition($actor, $booking, true);
        $hold = RoomReservation::firstOrFail();
        $released = $hold->starts_at->subHour();
        (new ScheduleLock)->run(fn () => (new RoomAllocator)->releaseLocked($booking, 'first_release', $released));
        $this->travelTo($hold->starts_at->subMinute());
        config(['booking.scheduling_enabled' => false]);
        $this->transition($actor, $booking->fresh(), false, BookingStatus::Cancelled);
        $this->assertSame('first_release', $hold->fresh()->release_reason);
        $this->assertTrue($released->equalTo($hold->fresh()->released_at));
        $this->travelBack();
    }
}
