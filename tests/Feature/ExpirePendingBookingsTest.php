<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Jobs\ExpirePendingBookings;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpirePendingBookingsTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-05 03:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    public function test_a_due_at_spa_booking_expires_through_the_system_actor(): void
    {
        $now = CarbonImmutable::parse(self::NOW, 'UTC');
        $booking = Booking::factory()->create([
            'starts_at' => $now,
            'payment_preference' => 'at_spa',
        ]);

        app(ExpirePendingBookings::class)->handle();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Cancelled, $fresh->status);
        $this->assertSame(2, $fresh->booking_version);
        $this->assertTrue($fresh->refund_eligible_at->equalTo($now));
        $this->assertSame('expired', $fresh->refund_reason);

        $change = BookingStatusChange::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame(BookingStatus::Pending, $change->from_status);
        $this->assertSame(BookingStatus::Cancelled, $change->to_status);
        $this->assertSame('system', $change->actor_type);
        $this->assertSame('system', $change->channel);
        $this->assertNull($change->admin_user_id);
        $this->assertNull($change->actor_name);
        $this->assertNull($change->actor_email);
        $this->assertNull($change->reason);
        $this->assertNull($change->ip_address);
        $this->assertNull($change->user_agent);

        $message = OutboxMessage::query()
            ->where('type', 'booking.cancelled')
            ->where('deduplication_key', "booking.cancelled:{$booking->id}:2")
            ->sole();
        $this->assertSame(['booking_id' => $booking->id, 'booking_version' => 2], $message->payload);
        $this->assertSame(0, $message->deliveries()->count());
    }

    public function test_bookings_that_are_not_due_or_not_pending_stay_unchanged(): void
    {
        $now = CarbonImmutable::parse(self::NOW, 'UTC');
        $pendingNotDue = Booking::factory()->create(['starts_at' => $now->addSecond()]);
        $confirmed = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'starts_at' => $now->subHour(),
        ]);
        $cancelled = Booking::factory()->create([
            'status' => BookingStatus::Cancelled,
            'starts_at' => $now->subHour(),
        ]);
        $pendingWithoutStart = Booking::factory()->create(['starts_at' => null]);

        app(ExpirePendingBookings::class)->handle();

        foreach ([[$pendingNotDue, BookingStatus::Pending], [$confirmed, BookingStatus::Confirmed], [$cancelled, BookingStatus::Cancelled], [$pendingWithoutStart, BookingStatus::Pending]] as [$booking, $status]) {
            $fresh = $booking->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertSame(1, $fresh->booking_version);
        }

        $this->assertSame(0, BookingStatusChange::query()->count());
        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function test_a_legacy_null_payment_preference_is_treated_as_at_spa(): void
    {
        $now = CarbonImmutable::parse(self::NOW, 'UTC');
        $booking = Booking::factory()->create([
            'starts_at' => $now,
            'payment_preference' => null,
        ]);

        app(ExpirePendingBookings::class)->handle();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Cancelled, $fresh->status);
        $this->assertSame(2, $fresh->booking_version);
        $this->assertSame('expired', $fresh->refund_reason);
    }

    public function test_online_bookings_use_the_provider_checkout_window(): void
    {
        $now = CarbonImmutable::parse(self::NOW, 'UTC');
        config(['booking.minimum_provider_checkout_minutes' => 30]);

        $due = Booking::factory()->create([
            'starts_at' => $now->addMinutes(30),
            'payment_preference' => 'online',
        ]);
        $notDue = Booking::factory()->create([
            'starts_at' => $now->addMinutes(30)->addSecond(),
            'payment_preference' => 'online',
        ]);
        // The checkout window applies to online bookings only.
        $atSpaInsideWindow = Booking::factory()->create([
            'starts_at' => $now->addMinutes(30),
            'payment_preference' => 'at_spa',
        ]);
        $legacyInsideWindow = Booking::factory()->create([
            'starts_at' => $now->addMinutes(30),
            'payment_preference' => null,
        ]);

        app(ExpirePendingBookings::class)->handle();

        $this->assertSame(BookingStatus::Cancelled, $due->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $notDue->fresh()->status);
        $this->assertSame(1, $notDue->fresh()->booking_version);
        foreach ([$atSpaInsideWindow, $legacyInsideWindow] as $offline) {
            $this->assertSame(BookingStatus::Pending, $offline->fresh()->status);
            $this->assertSame(1, $offline->fresh()->booking_version);
        }

        config(['booking.minimum_provider_checkout_minutes' => null]);

        $oneMinuteAhead = Booking::factory()->create([
            'starts_at' => $now->addMinute(),
            'payment_preference' => 'online',
        ]);

        app(ExpirePendingBookings::class)->handle();

        $this->assertSame(BookingStatus::Pending, $oneMinuteAhead->fresh()->status);
        $this->assertSame(1, $oneMinuteAhead->fresh()->booking_version);
    }

    public function test_the_locked_recheck_leaves_raced_or_not_due_bookings_alone(): void
    {
        $now = CarbonImmutable::parse(self::NOW, 'UTC');
        $job = app(ExpirePendingBookings::class);

        $confirmed = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'starts_at' => $now->subHour(),
        ]);
        $this->assertFalse($job->expireIfDue($confirmed->id, $now));
        $this->assertSame(BookingStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertSame(1, $confirmed->fresh()->booking_version);

        $notDue = Booking::factory()->create(['starts_at' => $now->addSecond()]);
        $this->assertFalse($job->expireIfDue($notDue->id, $now));
        $this->assertSame(BookingStatus::Pending, $notDue->fresh()->status);
        $this->assertSame(1, $notDue->fresh()->booking_version);

        $this->assertSame(0, BookingStatusChange::query()->count());
        $this->assertSame(0, OutboxMessage::query()->count());

        $due = Booking::factory()->create(['starts_at' => $now]);
        $this->assertTrue($job->expireIfDue($due->id, $now));
        $this->assertSame(BookingStatus::Cancelled, $due->fresh()->status);
        $this->assertSame(2, $due->fresh()->booking_version);
    }

    public function test_running_twice_still_produces_one_history_row_and_one_event(): void
    {
        $now = CarbonImmutable::parse(self::NOW, 'UTC');
        $booking = Booking::factory()->create([
            'starts_at' => $now,
            'payment_preference' => 'at_spa',
        ]);

        app(ExpirePendingBookings::class)->handle();
        app(ExpirePendingBookings::class)->handle();

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(2, $booking->fresh()->booking_version);
        $this->assertSame(1, BookingStatusChange::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.cancelled')->count());
    }

    public function test_the_schedule_registers_the_overlap_guarded_expiry_callback(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(static fn ($event): bool => $event->description === 'bookings:expire-pending');

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertSame(5, $events->first()->expiresAt);
    }
}
