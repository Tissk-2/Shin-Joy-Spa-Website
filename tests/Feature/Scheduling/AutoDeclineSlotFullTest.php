<?php

namespace Tests\Feature\Scheduling;

use App\Actions\ApproveBooking;
use App\Actions\Catalog\AttestPackagePricing;
use App\Actions\CreateStaffBooking;
use App\Actions\SaveRoomBlock;
use App\Enums\BookingStatus;
use App\Enums\PricingBasis;
use App\Models\Booking;
use App\Models\BookingSettings;
use App\Models\BookingStatusChange;
use App\Models\OutboxMessage;
use App\Models\Room;
use App\Models\RoomReservation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use App\Support\ActionContext;
use App\Support\BookingPresenter;
use App\Support\Bookings\SendCustomerBookingNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AutoDeclineSlotFullTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private ServiceVariant $variant;

    private ServiceVariant $otherVariant;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function setUpSchedule(int $roomCount = 2): array
    {
        CarbonImmutable::setTestNow('2026-10-25 01:00:00 UTC');
        config([
            'booking.scheduling_enabled' => true,
            'booking.customer_notices_enabled' => true,
        ]);
        BookingSettings::create([
            'id' => 1,
            'version' => 1,
            'review_intervals' => [['weekday' => 7, 'start_minute' => 540, 'end_minute' => 1260]],
            'review_target_minutes' => 60,
            'booking_horizon_days' => 60,
            'minimum_lead_minutes' => 0,
            'minimum_checkout_minutes' => 15,
        ]);
        $category = ServiceCategory::create([
            'slug' => 'spa',
            'name_id' => 'Spa',
            'name_en' => 'Spa',
            'image_path' => '',
            'display_order' => 0,
            'is_active' => true,
            'published_at' => now(),
        ]);
        $this->service = Service::create([
            'category_id' => $category->id,
            'slug' => 'nezuko',
            'name_id' => 'Nezuko',
            'name_en' => 'Nezuko',
            'description_id' => '',
            'description_en' => '',
            'long_description_id' => '',
            'long_description_en' => '',
            'duration_minutes' => 90,
            'price_idr' => 265000,
            'image_path' => '',
            'gallery_paths' => [],
            'popularity' => 0,
            'display_order' => 0,
            'is_active' => true,
            'published_at' => now(),
        ]);
        $owner = User::factory()->owner()->create();
        (new AttestPackagePricing)($owner, $this->service, (int) $this->service->lock_version, PricingBasis::PerPerson, 1, 4);
        $this->variant = ServiceVariant::create([
            'service_id' => $this->service->id,
            'code' => 'nezuko-regular-90',
            'treatment_mode' => 'regular',
            'duration_minutes' => 90,
            'price_idr' => 265000,
            'display_order' => 0,
            'is_active' => true,
            'published_at' => now(),
        ]);
        $this->otherVariant = ServiceVariant::create([
            'service_id' => $this->service->id,
            'code' => 'nezuko-double-90',
            'treatment_mode' => 'double_therapist',
            'duration_minutes' => 90,
            'price_idr' => 350000,
            'display_order' => 1,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $rooms = [];
        for ($i = 1; $i <= $roomCount; $i++) {
            $room = Room::create([
                'code' => 'R-'.$i,
                'name' => 'Room '.$i,
                'type' => 'Private',
                'capacity' => 2,
                'is_active' => true,
                'cleaning_minutes' => 15,
            ]);
            $room->variants()->attach($this->variant);
            $rooms[] = $room;
        }

        return $rooms;
    }

    private function pendingBooking(string $timeWib, array $overrides = []): Booking
    {
        $startUtc = CarbonImmutable::createFromFormat('!Y-m-d H:i', '2026-10-26 '.$timeWib, 'Asia/Jakarta')->utc();
        $duration = (int) ($overrides['total_duration_minutes'] ?? 90);

        return Booking::factory()->create([
            'status' => BookingStatus::Pending,
            'service_id' => $this->service->id,
            'service_variant_id' => $this->variant->id,
            'appointment_date' => '2026-10-26',
            'appointment_time' => $timeWib,
            'appointment_timezone' => 'Asia/Jakarta',
            'starts_at' => $startUtc,
            'treatment_ends_at' => $startUtc->addMinutes($duration),
            'total_duration_minutes' => $duration,
            'guests' => 1,
            'locale' => 'id',
            ...$overrides,
        ]);
    }

    public function test_approving_the_last_room_auto_declines_overlapping_pending_bookings_and_sends_localized_notice(): void
    {
        $rooms = $this->setUpSchedule(2);
        $staff = User::factory()->bookingStaff()->create();

        // Fill room 1 at 15:00 WIB (08:00–09:45 UTC incl. 15m cleaning).
        $first = $this->pendingBooking('15:00');
        $winner = $this->pendingBooking('15:00');
        $loserSameTime = $this->pendingBooking('15:00', ['locale' => 'id']);
        $loserCleaningOverlap = $this->pendingBooking('16:30', ['locale' => 'en']);
        $safeAfterCleaning = $this->pendingBooking('17:00');

        // Separate room exclusively for otherVariant: a pending booking for otherVariant must stay pending.
        $otherRoom = Room::create([
            'code' => 'R-OTHER',
            'name' => 'Other Room',
            'type' => 'Private',
            'capacity' => 2,
            'is_active' => true,
            'cleaning_minutes' => 15,
        ]);
        $otherRoom->variants()->attach($this->otherVariant);
        $otherTreatmentPending = $this->pendingBooking('15:00', ['service_variant_id' => $this->otherVariant->id]);

        // Approving $first leaves 1 free room -> nobody is auto-declined yet.
        (new ApproveBooking)($staff, $first->id, BookingStatus::Pending, new ActionContext('dashboard'), true);
        $this->assertSame(BookingStatus::Pending, $winner->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $loserSameTime->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $loserCleaningOverlap->fresh()->status);

        // Approving $winner fills the last room (15:00–16:45 WIB on both rooms).
        (new ApproveBooking)($staff, $winner->id, BookingStatus::Pending, new ActionContext('dashboard'), true);

        $this->assertSame(BookingStatus::Confirmed, $winner->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $loserSameTime->fresh()->status);
        $this->assertSame('slot_full', $loserSameTime->fresh()->refund_reason);
        $this->assertNotNull($loserSameTime->fresh()->refund_eligible_at);
        $this->assertSame(BookingStatus::Cancelled, $loserCleaningOverlap->fresh()->status);
        $this->assertSame('slot_full', $loserCleaningOverlap->fresh()->refund_reason);

        // Non-overlapping & other-room pending requests remain untouched.
        $this->assertSame(BookingStatus::Pending, $safeAfterCleaning->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $otherTreatmentPending->fresh()->status);

        // Audit trail records system actor and slot_full reason.
        $change = BookingStatusChange::query()->where('booking_id', $loserSameTime->id)->sole();
        $this->assertSame('system', $change->actor_type);
        $this->assertSame('system', $change->channel);
        $this->assertSame('slot_full', $change->reason);
        $this->assertNull($change->admin_user_id);

        // Customer presenter exposes slotFull=true and hides raw internal reason code.
        $presented = (new BookingPresenter)->detail($loserSameTime->fresh());
        $this->assertTrue($presented['slotFull']);
        $this->assertFalse($presented['expired']);
        $this->assertNull($presented['cancellationReason']);

        // Outbox emits booking.cancelled for each auto-declined booking and sends localized slot_full email.
        Notification::fake();
        foreach ([$loserSameTime->fresh(), $loserCleaningOverlap->fresh()] as $declined) {
            $outbox = OutboxMessage::query()
                ->where('type', 'booking.cancelled')
                ->where('deduplication_hash', hash('sha256', "booking.cancelled:{$declined->id}:{$declined->booking_version}"))
                ->firstOrFail();
            app(SendCustomerBookingNotice::class)->handle($outbox);
        }

        Notification::assertSentOnDemand(
            BookingStatusNotification::class,
            function (BookingStatusNotification $notification, array $channels, object $notifiable) use ($loserSameTime): bool {
                if (($notifiable->routes['mail'] ?? null) !== $loserSameTime->customer_email || $notification->locale !== 'id') {
                    return false;
                }
                $previous = app()->getLocale();
                app()->setLocale('id');
                try {
                    $mail = $notification->toMail($notifiable);
                } finally {
                    app()->setLocale($previous);
                }

                return str_contains($mail->subject, 'sudah terisi')
                    && str_contains(implode(' ', $mail->introLines), 'sudah terisi');
            },
        );
        Notification::assertSentOnDemand(
            BookingStatusNotification::class,
            function (BookingStatusNotification $notification, array $channels, object $notifiable) use ($loserCleaningOverlap): bool {
                if (($notifiable->routes['mail'] ?? null) !== $loserCleaningOverlap->customer_email || $notification->locale !== 'en') {
                    return false;
                }
                $previous = app()->getLocale();
                app()->setLocale('en');
                try {
                    $mail = $notification->toMail($notifiable);
                } finally {
                    app()->setLocale($previous);
                }

                return str_contains($mail->subject, 'now full')
                    && str_contains(implode(' ', $mail->introLines), 'filled up');
            },
        );
    }

    public function test_saving_a_room_block_that_fills_the_last_room_auto_declines_unfillable_pending_bookings(): void
    {
        $rooms = $this->setUpSchedule(1);
        $owner = User::factory()->owner()->create();
        $pendingBlocked = $this->pendingBooking('10:00');
        $pendingSafe = $this->pendingBooking('14:00');

        (new SaveRoomBlock)($owner, null, [
            'roomId' => $rooms[0]->id,
            'startsAt' => '2026-10-26T10:00',
            'endsAt' => '2026-10-26T11:30',
            'reason' => 'AC repair',
        ]);

        $this->assertSame(BookingStatus::Cancelled, $pendingBlocked->fresh()->status);
        $this->assertSame('slot_full', $pendingBlocked->fresh()->refund_reason);
        $this->assertSame(BookingStatus::Pending, $pendingSafe->fresh()->status);
    }

    public function test_staff_manual_booking_with_immediate_approval_auto_declines_contending_pending_request(): void
    {
        $this->setUpSchedule(1);
        $staff = User::factory()->bookingStaff()->create();
        $pending = $this->pendingBooking('13:00');

        $created = app(CreateStaffBooking::class)($staff, [
            'source' => 'walk_in',
            'name' => 'Walk-in Guest',
            'phone' => '+6281200001111',
            'email' => null,
            'service' => 'nezuko',
            'mode' => 'regular',
            'duration' => 90,
            'extension' => '',
            'appointment_date' => '2026-10-26',
            'appointment_time' => '13:00',
            'guests' => 1,
            'notes' => '',
            'submission_key' => (string) Str::uuid(),
            'expected_total_price_idr' => 265000,
        ], true, new ActionContext('dashboard'), true);

        $this->assertSame(BookingStatus::Confirmed, $created->status);
        $this->assertSame(BookingStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame('slot_full', $pending->fresh()->refund_reason);
    }

    public function test_auto_decline_does_not_run_when_scheduling_is_off_or_when_approval_rolls_back(): void
    {
        $this->setUpSchedule(1);
        config(['booking.scheduling_enabled' => false]);
        $staff = User::factory()->bookingStaff()->create();
        $first = $this->pendingBooking('15:00');
        $second = $this->pendingBooking('15:00');

        (new ApproveBooking)($staff, $first->id, BookingStatus::Pending, new ActionContext('dashboard'));
        $this->assertSame(BookingStatus::Pending, $second->fresh()->status);

        // Turn scheduling back on; a failed approval (missing operationalConfirmed) rolls back and touches nothing.
        config(['booking.scheduling_enabled' => true]);
        $third = $this->pendingBooking('10:00');
        $fourth = $this->pendingBooking('10:00');
        try {
            (new ApproveBooking)($staff, $third->id, BookingStatus::Pending, new ActionContext('dashboard'), false);
            $this->fail('Expected operationalConfirmed failure.');
        } catch (ValidationException) {
            // expected
        }
        $this->assertSame(BookingStatus::Pending, $third->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $fourth->fresh()->status);
        $this->assertSame(0, RoomReservation::query()->count());
    }
}
