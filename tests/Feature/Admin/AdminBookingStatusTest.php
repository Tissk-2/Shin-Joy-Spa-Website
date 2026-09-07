<?php

namespace Tests\Feature\Admin;

use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminBookingStatusTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transition_updates_booking_and_writes_one_audit_row(
        BookingStatus $from,
        BookingStatus $to,
    ): void {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => $from]);

        $change = app(TransitionBookingStatus::class)(
            $admin,
            $booking,
            $from,
            $to,
            'Guest confirmed by phone.',
            '127.0.0.1',
            'Admin status test',
        );

        $this->assertSame($to, $booking->fresh()->status);
        $this->assertSame($from, $change->from_status);
        $this->assertSame($to, $change->to_status);
        $this->assertDatabaseCount('booking_status_changes', 1);
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_changes_nothing(
        BookingStatus $from,
        BookingStatus $to,
    ): void {
        $booking = Booking::factory()->create(['status' => $from]);

        try {
            app(TransitionBookingStatus::class)(
                User::factory()->admin()->create(),
                $booking,
                $from,
                $to,
                null,
                null,
                null,
            );
            $this->fail('An invalid transition should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('nextStatus', $exception->errors());
        }

        $this->assertSame($from, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_stale_transition_changes_nothing(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        try {
            app(TransitionBookingStatus::class)(
                User::factory()->admin()->create(),
                $booking,
                BookingStatus::Pending,
                BookingStatus::Cancelled,
                null,
                null,
                null,
            );
            $this->fail('A stale transition should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currentStatus', $exception->errors());
        }

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_second_request_with_old_status_cannot_create_a_second_audit_row(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);
        $transition = app(TransitionBookingStatus::class);

        $transition($admin, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null);

        try {
            $transition($admin, $booking, BookingStatus::Pending, BookingStatus::Cancelled, null, null, null);
            $this->fail('A repeated request with an old current status should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currentStatus', $exception->errors());
        }

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 1);
    }

    public function test_audit_failure_rolls_back_booking_status(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        BookingStatusChange::creating(function (): never {
            throw new RuntimeException('Audit write failed.');
        });

        try {
            app(TransitionBookingStatus::class)(
                User::factory()->admin()->create(),
                $booking,
                BookingStatus::Pending,
                BookingStatus::Confirmed,
                null,
                null,
                null,
            );
            $this->fail('An audit failure should abort the transition.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit write failed.', $exception->getMessage());
        } finally {
            BookingStatusChange::flushEventListeners();
        }

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_audit_snapshots_survive_admin_deletion_and_metadata_is_capped(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Made Ayu',
            'email' => 'made@example.test',
        ]);
        $booking = Booking::factory()->create();

        $change = app(TransitionBookingStatus::class)(
            $admin,
            $booking,
            BookingStatus::Pending,
            BookingStatus::Confirmed,
            'Guest confirmed by phone.',
            str_repeat('1', 60),
            str_repeat('A', 1100),
        );

        $admin->delete();
        $change = $change->fresh();

        $this->assertNull($change->admin_user_id);
        $this->assertNull($change->admin);
        $this->assertSame('Made Ayu', $change->actor_name);
        $this->assertSame('made@example.test', $change->actor_email);
        $this->assertSame(45, mb_strlen($change->ip_address));
        $this->assertSame(1024, mb_strlen($change->user_agent));
    }

    public function test_booking_with_audit_history_cannot_be_deleted(): void
    {
        $booking = Booking::factory()->create();

        app(TransitionBookingStatus::class)(
            User::factory()->admin()->create(),
            $booking,
            BookingStatus::Pending,
            BookingStatus::Confirmed,
            null,
            null,
            null,
        );

        try {
            $booking->delete();
            $this->fail('A booking with audit history should be protected from deletion.');
        } catch (QueryException) {
            // Expected database enforcement.
        }

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
        $this->assertDatabaseCount('booking_status_changes', 1);
    }

    public function test_status_changes_relationship_is_ordered_by_created_at_then_id(): void
    {
        $booking = Booking::factory()->create();
        $admin = User::factory()->admin()->create();
        $later = Carbon::parse('2026-09-07 11:00:00');
        $earlier = Carbon::parse('2026-09-07 10:00:00');

        $last = BookingStatusChange::factory()->for($booking)->for($admin, 'admin')->create([
            'created_at' => $later,
        ]);
        $first = BookingStatusChange::factory()->for($booking)->for($admin, 'admin')->create([
            'created_at' => $earlier,
        ]);
        $second = BookingStatusChange::factory()->for($booking)->for($admin, 'admin')->create([
            'created_at' => $earlier,
        ]);

        $this->assertSame(
            [$first->id, $second->id, $last->id],
            $booking->statusChanges()->pluck('id')->all(),
        );
    }

    public function test_booking_uses_reference_as_route_key(): void
    {
        $this->assertSame('reference', (new Booking)->getRouteKeyName());
    }

    public function test_booking_blocks_ordinary_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new Booking)->fill([
            'reference' => 'SJ-UNTRUSTED',
            'status' => BookingStatus::Completed,
            'customer_email' => 'attacker@example.test',
            'total_price_idr' => 1,
        ]);
    }

    public function test_booking_status_change_blocks_ordinary_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new BookingStatusChange)->fill([
            'actor_name' => 'Untrusted actor',
            'from_status' => BookingStatus::Pending,
            'to_status' => BookingStatus::Completed,
        ]);
    }

    /**
     * @return array<string, array{BookingStatus, BookingStatus}>
     */
    public static function allowedTransitions(): array
    {
        return [
            'pending to confirmed' => [BookingStatus::Pending, BookingStatus::Confirmed],
            'pending to cancelled' => [BookingStatus::Pending, BookingStatus::Cancelled],
            'confirmed to completed' => [BookingStatus::Confirmed, BookingStatus::Completed],
            'confirmed to cancelled' => [BookingStatus::Confirmed, BookingStatus::Cancelled],
        ];
    }

    /**
     * @return array<string, array{BookingStatus, BookingStatus}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'pending to pending' => [BookingStatus::Pending, BookingStatus::Pending],
            'pending to completed' => [BookingStatus::Pending, BookingStatus::Completed],
            'confirmed to pending' => [BookingStatus::Confirmed, BookingStatus::Pending],
            'confirmed to confirmed' => [BookingStatus::Confirmed, BookingStatus::Confirmed],
            'completed to pending' => [BookingStatus::Completed, BookingStatus::Pending],
            'completed to confirmed' => [BookingStatus::Completed, BookingStatus::Confirmed],
            'completed to completed' => [BookingStatus::Completed, BookingStatus::Completed],
            'completed to cancelled' => [BookingStatus::Completed, BookingStatus::Cancelled],
            'cancelled to pending' => [BookingStatus::Cancelled, BookingStatus::Pending],
            'cancelled to confirmed' => [BookingStatus::Cancelled, BookingStatus::Confirmed],
            'cancelled to completed' => [BookingStatus::Cancelled, BookingStatus::Completed],
            'cancelled to cancelled' => [BookingStatus::Cancelled, BookingStatus::Cancelled],
        ];
    }
}
