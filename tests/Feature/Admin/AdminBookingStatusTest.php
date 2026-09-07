<?php

namespace Tests\Feature\Admin;

use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    // Production break caught: removing lockForUpdate would allow concurrent administrators to transition the same persisted state.
    public function test_transition_locks_the_persisted_booking_select_for_update(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        $recordingGrammar = new class($connection) extends SQLiteGrammar
        {
            /** @var list<array{from: string|null, lock: string|bool|null}> */
            public array $compiledSelects = [];

            public function compileSelect(Builder $query)
            {
                $this->compiledSelects[] = [
                    'from' => $query->from,
                    'lock' => $query->lock,
                ];

                return parent::compileSelect($query);
            }
        };

        $connection->setQueryGrammar($recordingGrammar);

        try {
            app(TransitionBookingStatus::class)(
                $admin,
                $booking,
                BookingStatus::Pending,
                BookingStatus::Confirmed,
                null,
                null,
                null,
            );
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }

        $bookingSelects = array_values(array_filter(
            $recordingGrammar->compiledSelects,
            fn (array $select): bool => $select['from'] === 'bookings',
        ));

        $this->assertSame([
            ['from' => 'bookings', 'lock' => true],
        ], $bookingSelects);
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

    // Production break caught: the endpoint must pass only validated status data to the transition action.
    public function test_admin_can_apply_an_allowed_transition_through_the_http_boundary(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending,
            'total_price_idr' => 265000,
        ]);

        $this->actingAs($admin)->patch(route('admin.bookings.status.update', $booking), [
            'currentStatus' => 'pending',
            'nextStatus' => 'confirmed',
            'reason' => '  Confirmed by WhatsApp.  ',
            'total_price_idr' => 1,
            'role' => UserRole::Customer->value,
        ])->assertRedirect(route('admin.bookings.show', $booking, absolute: false))
            ->assertStatus(303)
            ->assertSessionHas('status', 'Status reservasi berhasil diperbarui.');

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(265000, $booking->fresh()->total_price_idr);
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertDatabaseHas('booking_status_changes', [
            'booking_id' => $booking->id,
            'admin_user_id' => $admin->id,
            'reason' => 'Confirmed by WhatsApp.',
        ]);
    }

    /**
     * @param  array{currentStatus: string, nextStatus: string}  $payload
     */
    #[DataProvider('invalidEnumPayloads')]
    public function test_invalid_enum_strings_are_rejected_at_the_http_boundary(
        array $payload,
        string $invalidField,
    ): void {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        $this->actingAs($admin)
            ->from(route('admin.bookings.show', $booking, absolute: false))
            ->patch(route('admin.bookings.status.update', $booking), $payload)
            ->assertRedirect(route('admin.bookings.show', $booking, absolute: false))
            ->assertSessionHasErrors($invalidField);

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_lifecycle_transition_is_rejected_at_the_http_boundary(
        BookingStatus $from,
        BookingStatus $to,
    ): void {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => $from]);

        $this->actingAs($admin)
            ->from(route('admin.bookings.show', $booking, absolute: false))
            ->patch(route('admin.bookings.status.update', $booking), [
                'currentStatus' => $from->value,
                'nextStatus' => $to->value,
                'reason' => null,
            ])
            ->assertRedirect(route('admin.bookings.show', $booking, absolute: false))
            ->assertSessionHasErrors('nextStatus');

        $this->assertSame($from, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_blank_reason_is_normalized_to_null(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        $this->actingAs($admin)->patch(route('admin.bookings.status.update', $booking), [
            'currentStatus' => 'pending',
            'nextStatus' => 'cancelled',
            'reason' => " \t\n ",
        ])->assertStatus(303);

        $this->assertDatabaseHas('booking_status_changes', [
            'booking_id' => $booking->id,
            'reason' => null,
        ]);
    }

    public function test_reason_longer_than_500_characters_is_rejected_without_an_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        $this->actingAs($admin)
            ->from(route('admin.bookings.show', $booking, absolute: false))
            ->patch(route('admin.bookings.status.update', $booking), [
                'currentStatus' => 'pending',
                'nextStatus' => 'confirmed',
                'reason' => str_repeat('A', 501),
            ])
            ->assertRedirect(route('admin.bookings.show', $booking, absolute: false))
            ->assertSessionHasErrors('reason');

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_stale_http_transition_returns_a_field_error_without_an_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        $this->actingAs($admin)
            ->from(route('admin.bookings.show', $booking, absolute: false))
            ->patch(route('admin.bookings.status.update', $booking), [
                'currentStatus' => 'pending',
                'nextStatus' => 'cancelled',
                'reason' => null,
            ])
            ->assertRedirect(route('admin.bookings.show', $booking, absolute: false))
            ->assertSessionHasErrors([
                'currentStatus' => 'Status reservasi ini telah berubah. Muat ulang halaman lalu coba lagi.',
            ]);

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_guest_is_redirected_before_status_route_model_binding(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        foreach ([$booking->reference, 'SJ-NONEXISTENT'] as $reference) {
            $this->patch("/admin/bookings/{$reference}/status", $this->validHttpPayload())
                ->assertRedirect(route('login', absolute: false));
        }

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_unverified_admin_is_redirected_before_status_route_model_binding(): void
    {
        $admin = User::factory()->admin()->unverified()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        foreach ([$booking->reference, 'SJ-NONEXISTENT'] as $reference) {
            $this->actingAs($admin)
                ->patch("/admin/bookings/{$reference}/status", $this->validHttpPayload())
                ->assertRedirect(route('verification.notice', absolute: false));
        }

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_verified_customer_is_forbidden_before_status_route_model_binding(): void
    {
        $customer = User::factory()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        foreach ([$booking->reference, 'SJ-NONEXISTENT'] as $reference) {
            $this->actingAs($customer)
                ->patch("/admin/bookings/{$reference}/status", [
                    ...$this->validHttpPayload(),
                    'role' => UserRole::Admin->value,
                ])
                ->assertForbidden();
        }

        $this->assertSame(UserRole::Customer, $customer->fresh()->role);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_thirty_first_status_request_for_an_admin_and_ip_is_rate_limited(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);
        $payload = $this->invalidHttpPayload();

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->actingAs($admin)
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
                ->patch(route('admin.bookings.status.update', $booking), $payload)
                ->assertSessionHasErrors('nextStatus');
        }

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
            ->patch(route('admin.bookings.status.update', $booking), $payload)
            ->assertTooManyRequests();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    public function test_one_hundred_and_first_status_request_from_an_ip_is_rate_limited(): void
    {
        $admins = User::factory()->count(5)->admin()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);
        $payload = $this->invalidHttpPayload();

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $this->actingAs($admins[$attempt % 4])
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.100'])
                ->patch(route('admin.bookings.status.update', $booking), $payload)
                ->assertSessionHasErrors('nextStatus');
        }

        $this->actingAs($admins[4])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.100'])
            ->patch(route('admin.bookings.status.update', $booking), $payload)
            ->assertTooManyRequests();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
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

    /**
     * @return array<string, array{array{currentStatus: string, nextStatus: string}, string}>
     */
    public static function invalidEnumPayloads(): array
    {
        return [
            'invalid current status' => [
                ['currentStatus' => 'scheduled', 'nextStatus' => 'confirmed'],
                'currentStatus',
            ],
            'invalid next status' => [
                ['currentStatus' => 'pending', 'nextStatus' => 'scheduled'],
                'nextStatus',
            ],
        ];
    }

    /**
     * @return array{currentStatus: string, nextStatus: string, reason: null}
     */
    private function validHttpPayload(): array
    {
        return [
            'currentStatus' => 'pending',
            'nextStatus' => 'confirmed',
            'reason' => null,
        ];
    }

    /**
     * @return array{currentStatus: string, nextStatus: string, reason: null}
     */
    private function invalidHttpPayload(): array
    {
        return [
            'currentStatus' => 'pending',
            'nextStatus' => 'scheduled',
            'reason' => null,
        ];
    }
}
