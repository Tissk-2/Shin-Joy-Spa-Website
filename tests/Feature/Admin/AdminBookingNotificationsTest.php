<?php

namespace Tests\Feature\Admin;

use App\Actions\ApproveBooking;
use App\Actions\CancelBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\TelegramBookingMessage;
use App\Models\User;
use App\Support\ActionContext;
use App\Support\Outbox\BookingCreatedOutbox;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminBookingNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-09 03:00:00', 'UTC'));
    }

    public function test_detail_lists_each_notice_with_its_state_and_reason(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $this->approve($booking, $staff);

        $this->setDelivery($this->delivery('booking.approved', $booking, 'telegram'), [
            'state' => 'completed', 'attempts' => 1,
        ]);
        $this->setDelivery($this->delivery('booking.approved', $booking, 'customer'), [
            'state' => 'skipped', 'attempts' => 1, 'last_error' => 'skipped: no_email', 'updated_at' => '2026-11-09 04:00:00',
        ]);

        $notifications = $this->props($booking, $staff)['notifications'];

        $this->assertCount(2, $notifications);
        $this->assertSame(['event', 'channel', 'state', 'detail', 'attempts', 'updatedAt'], array_keys($notifications[0]));
        $this->assertSame(['event', 'channel', 'state', 'detail', 'attempts', 'updatedAt'], array_keys($notifications[1]));

        $this->assertSame('booking.approved', $notifications[0]['event']);
        $this->assertSame('telegram', $notifications[0]['channel']);
        $this->assertSame('completed', $notifications[0]['state']);
        $this->assertNull($notifications[0]['detail']);
        $this->assertSame(1, $notifications[0]['attempts']);
        $this->assertMatchesRegularExpression('/^2026-11-09T03:00:00/', $notifications[0]['updatedAt']);

        $this->assertSame('booking.approved', $notifications[1]['event']);
        $this->assertSame('customer', $notifications[1]['channel']);
        $this->assertSame('skipped', $notifications[1]['state']);
        $this->assertSame('skipped: no_email', $notifications[1]['detail']);
        $this->assertSame(1, $notifications[1]['attempts']);
        // When the delivery last changed, not when it was created.
        $this->assertMatchesRegularExpression('/^2026-11-09T04:00:00/', $notifications[1]['updatedAt']);
    }

    public function test_every_booking_event_the_outbox_knows_is_listed(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $events = array_values(array_filter(
            array_keys(config('outbox.events')),
            fn (string $event): bool => str_starts_with($event, 'booking.'),
        ));
        $this->assertContains('booking.no_show', $events);

        DB::transaction(function () use ($booking, $events): void {
            app(BookingCreatedOutbox::class)->record($booking);
            $version = 1;
            foreach (array_diff($events, ['booking.created']) as $event) {
                $version++;
                // The key BookingStateWriter writes for each decision.
                app(OutboxWriter::class)->record($event, "{$event}:{$booking->id}:{$version}", ['booking_id' => $booking->id, 'booking_version' => $version]);
            }
            DB::table('bookings')->where('id', $booking->id)->update(['booking_version' => $version]);
        });

        $listed = array_column($this->props($booking, $staff)['notifications'], 'event');

        $this->assertSame($events, array_values(array_unique($listed)));
    }

    public function test_notices_cover_every_decision_of_this_booking_and_no_other(): void
    {
        $staff = $this->staff();

        $a = $this->booking();
        DB::transaction(fn () => app(BookingCreatedOutbox::class)->record($a));
        $this->approve($a, $staff);
        (new CancelBooking)($staff, $a->id, BookingStatus::Confirmed, null, new ActionContext('dashboard'));

        $b = $this->booking();
        DB::transaction(fn () => app(BookingCreatedOutbox::class)->record($b));
        $this->approve($b, $staff);

        $labels = array_map(
            fn (array $notice): string => "{$notice['event']}/{$notice['channel']}",
            $this->props($a, $staff)['notifications'],
        );

        $this->assertSame([
            'booking.created/telegram',
            'booking.approved/telegram',
            'booking.approved/customer',
            'booking.cancelled/telegram',
            'booking.cancelled/customer',
        ], $labels);
    }

    public function test_only_the_staff_and_customer_channels_are_listed(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $this->approve($booking, $staff);

        $message = OutboxMessage::query()->where('type', 'booking.approved')->get()
            ->sole(fn (OutboxMessage $m): bool => $m->payload['booking_id'] === $booking->id);

        DB::table('outbox_deliveries')->insert([
            'message_id' => $message->id,
            'handler_key' => 'audit',
            'handler_key_hash' => hash('sha256', 'audit'),
            'state' => 'failed',
            'attempts' => 5,
            'last_error' => 'permanent: internal',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $channels = array_map(
            fn (array $notice): string => $notice['channel'],
            $this->props($booking, $staff)['notifications'],
        );

        $this->assertSame(['telegram', 'customer'], $channels);
    }

    public function test_the_staff_message_line_reports_its_state_and_whether_it_is_current(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();

        // Another booking's message, however new, never shows on this booking's page.
        TelegramBookingMessage::query()->create([
            'booking_id' => $this->booking()->id,
            'chat_id' => '-1001',
            'message_id' => 9,
            'booking_version' => 1,
            'state' => 'deleted',
        ]);
        $this->assertNull($this->props($booking, $staff)['telegramMessage']);

        TelegramBookingMessage::query()->create([
            'booking_id' => $booking->id,
            'chat_id' => '-1001',
            'message_id' => 5,
            'booking_version' => 1,
            'state' => 'sent',
        ]);
        $this->assertSame(['state' => 'sent', 'current' => true], $this->props($booking, $staff)['telegramMessage']);

        $this->approve($booking, $staff);
        $this->assertSame(['state' => 'sent', 'current' => false], $this->props($booking, $staff)['telegramMessage']);

        TelegramBookingMessage::query()->create([
            'booking_id' => $booking->id,
            'chat_id' => '-1002',
            'message_id' => null,
            'booking_version' => 2,
            'state' => 'deleted',
        ]);
        $this->assertSame(['state' => 'deleted', 'current' => true], $this->props($booking, $staff)['telegramMessage']);
    }

    public function test_the_customer_booking_page_never_exposes_delivery_details(): void
    {
        $user = User::factory()->create();
        $staff = $this->staff();
        $booking = $this->booking(['user_id' => $user->id]);
        $this->approve($booking, $staff);

        $this->setDelivery($this->delivery('booking.approved', $booking, 'customer'), [
            'state' => 'failed', 'last_error' => 'unexpected: Some\Mailer\Exception',
        ]);

        $response = $this->actingAs($user)
            ->get(route('bookings.show', $booking->reference))
            ->assertOk();

        $presented = $response->inertiaProps()['booking'];
        $this->assertArrayNotHasKey('notifications', $presented);
        $this->assertArrayNotHasKey('telegramMessage', $presented);

        $serialized = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        foreach (['last_error', 'Mailer', 'outbox'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    private function staff(): User
    {
        return User::factory()->bookingStaff()->create();
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create(['starts_at' => '2026-12-05 03:30:00', ...$overrides])->fresh();
    }

    private function approve(Booking $booking, User $staff): void
    {
        (new ApproveBooking)($staff, $booking->id, BookingStatus::Pending, new ActionContext('dashboard'));
    }

    /** @return array<string, mixed> */
    private function props(Booking $booking, User $viewer): array
    {
        return $this->actingAs($viewer)
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->inertiaProps()['booking'];
    }

    private function delivery(string $type, Booking $booking, string $channel): object
    {
        $message = OutboxMessage::query()->where('type', $type)->get()
            ->sole(fn (OutboxMessage $m): bool => $m->payload['booking_id'] === $booking->id);

        return DB::table('outbox_deliveries')
            ->where('message_id', $message->id)
            ->where('handler_key', $channel)
            ->sole();
    }

    /** @param  array<string, mixed>  $attributes */
    private function setDelivery(object $row, array $attributes): void
    {
        DB::table('outbox_deliveries')->where('id', $row->id)->update($attributes);
    }
}
