<?php

namespace Tests\Feature;

use App\Actions\ApproveBooking;
use App\Actions\CancelBooking;
use App\Actions\RecordNoShow;
use App\Enums\BookingStatus;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxSkippedException;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\ActionContext;
use App\Support\Bookings\SendCustomerBookingNotice;
use App\Support\Outbox\Handlers\TelegramBookingNotificationHandler;
use App\Support\Payments\BookingPaymentSummary;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class BookingCustomerNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-09 03:00:00 UTC');

        config([
            'booking.customer_notices_enabled' => true,
            'app.url' => 'https://spa.example.com',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_an_approval_emails_the_customer_in_their_booking_language(): void
    {
        $booking = $this->booking(['locale' => 'id', 'payment_preference' => 'at_spa']);
        $this->approve($booking, $this->staff());

        $this->handle($this->event('booking.approved', $booking));

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $mail = $mails[0];
        $this->assertSame('ayu@example.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('Reservasi Shin Joy Spa Anda telah dikonfirmasi', $mail->getSubject());

        $text = (string) $mail->getTextBody();
        $this->assertStringContainsString(
            'Reservasi '.$booking->reference.' untuk Pijat Nezuko pada 5 Desember 2026 pukul 10:30 WIB telah dikonfirmasi.',
            $text,
        );
        $this->assertStringContainsString('Pembayaran dilakukan di spa pada hari janji temu Anda.', $text);
        $this->assertStringContainsString('Halo, Ayu Lestari!', $text);

        $html = (string) $mail->getHtmlBody();
        $this->assertStringContainsString('https://spa.example.com/bookings/'.$booking->reference, $html);
        $this->assertStringNotContainsString('/admin/', $html);

        $this->assertSame('en', app()->getLocale());
    }

    public function test_english_and_the_indonesian_fallback(): void
    {
        $staff = $this->staff();

        $english = $this->booking(['locale' => 'en']);
        $this->approve($english, $staff);
        $this->handle($this->event('booking.approved', $english));

        $first = $this->mails()[0];
        $this->assertSame('Your Shin Joy Spa booking is confirmed', $first->getSubject());
        $firstText = (string) $first->getTextBody();
        $this->assertStringContainsString(
            'Your booking '.$english->reference.' for Nezuko Massage on 5 December 2026 at 10:30 WIB is confirmed.',
            $firstText,
        );
        $this->assertStringNotContainsString('Payment is made at the spa', $firstText);

        $fallback = $this->booking(['locale' => null, 'customer_email' => 'b@example.com']);
        $this->approve($fallback, $staff);
        $this->handle($this->event('booking.approved', $fallback));

        $second = $this->mails()[1];
        $this->assertSame('Reservasi Shin Joy Spa Anda telah dikonfirmasi', $second->getSubject());
        $this->assertSame('b@example.com', $second->getTo()[0]->getAddress());

        // A language the site doesn't offer falls back to Indonesian too, not to the app's English fallback.
        $unsupported = $this->booking(['locale' => 'fr', 'customer_email' => 'c@example.com']);
        $this->approve($unsupported, $staff);
        $this->handle($this->event('booking.approved', $unsupported));

        $this->assertSame('Reservasi Shin Joy Spa Anda telah dikonfirmasi', $this->mails()[2]->getSubject());
    }

    public function test_a_decline_says_what_happened_and_never_mentions_money(): void
    {
        $staff = $this->staff();
        $booking = $this->booking(['payment_preference' => 'at_spa']);

        (new CancelBooking)($staff, $booking->id, BookingStatus::Pending, 'staff_declined', new ActionContext('dashboard'));

        $this->handle($this->event('booking.cancelled', $booking));

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('Your Shin Joy Spa booking was cancelled', $mails[0]->getSubject());

        $text = (string) $mails[0]->getTextBody();
        $this->assertStringContainsString('has been cancelled.', $text);
        $this->assertStringContainsString('make a new booking', $text);

        foreach (['refund', 'paid', 'payment'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text);
        }
    }

    public function test_a_no_show_says_what_happened_and_never_mentions_money(): void
    {
        $staff = $this->staff();
        $booking = $this->booking([
            'appointment_date' => '2026-11-08',
            'starts_at' => '2026-11-08 03:30:00',
            'payment_preference' => 'at_spa',
        ]);

        $this->approve($booking, $staff);
        (new RecordNoShow)($staff, $booking->id, BookingStatus::Confirmed, new ActionContext('dashboard'));

        $this->handle($this->event('booking.no_show', $booking));

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('Your Shin Joy Spa appointment was missed', $mails[0]->getSubject());

        $text = (string) $mails[0]->getTextBody();
        $this->assertStringContainsString('8 November 2026 at 10:30 WIB was recorded as missed.', $text);
        $this->assertStringContainsString('contact the spa', $text);

        foreach (['refund', 'paid', 'payment'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text);
        }
    }

    public function test_delayed_approval_notice_cannot_announce_cancelled_booking(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();

        $this->approve($booking, $staff);
        (new CancelBooking)($staff, $booking->id, BookingStatus::Confirmed, 'staff_cancelled', new ActionContext('dashboard'));

        $this->assertSame('superseded', $this->skippedWith($this->event('booking.approved', $booking)));
        $this->assertSame([], $this->mails());

        $this->handle($this->event('booking.cancelled', $booking));

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('Your Shin Joy Spa booking was cancelled', $mails[0]->getSubject());
        $this->assertStringNotContainsString('is confirmed', (string) $mails[0]->getTextBody());
    }

    public function test_a_staff_entered_booking_without_an_email_is_skipped(): void
    {
        $booking = $this->booking(['user_id' => null, 'customer_email' => null]);
        $this->approve($booking, $this->staff());

        $this->assertSame('no_email', $this->skippedWith($this->event('booking.approved', $booking)));
        $this->assertSame([], $this->mails());
    }

    public function test_a_staff_entered_booking_with_an_email_gets_no_account_link(): void
    {
        $booking = $this->booking(['user_id' => null, 'customer_email' => 'walkin@example.com']);
        $this->approve($booking, $this->staff());

        $this->handle($this->event('booking.approved', $booking));

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('walkin@example.com', $mails[0]->getTo()[0]->getAddress());

        $html = (string) $mails[0]->getHtmlBody();
        $this->assertStringNotContainsString('/bookings/', $html);
        $this->assertStringNotContainsString('/admin/', $html);
    }

    public function test_notices_are_skipped_while_switched_off(): void
    {
        $booking = $this->booking();
        $this->approve($booking, $this->staff());
        $event = $this->event('booking.approved', $booking);

        config(['booking.customer_notices_enabled' => false]);
        $this->assertSame('disabled', $this->skippedWith($event));

        config(['booking.customer_notices_enabled' => '1']);
        $this->assertSame('disabled', $this->skippedWith($event));

        $this->assertSame([], $this->mails());
    }

    public function test_an_online_approval_awaiting_payment_is_not_announced_as_confirmed(): void
    {
        $booking = $this->booking();
        $this->approve($booking, $this->staff());
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'awaiting_payment']);

        $this->assertSame('awaiting_payment', $this->skippedWith($this->event('booking.approved', $booking)));
        $this->assertSame([], $this->mails());
    }

    public function test_the_switch_reads_the_usual_true_and_false_spellings(): void
    {
        try {
            // BOOKING_CUSTOMER_NOTICES_ENABLED=1 must not leave every email silently skipped as "disabled".
            foreach (['true' => true, '1' => true, 'yes' => true, 'on' => true, 'false' => false, '0' => false, '' => false] as $value => $expected) {
                $this->setSwitch((string) $value);
                $this->assertSame($expected, (require config_path('booking.php'))['customer_notices_enabled'], "value '{$value}'");
            }

            $this->setSwitch(null);
            $this->assertFalse((require config_path('booking.php'))['customer_notices_enabled']);
        } finally {
            $this->setSwitch('false');   // TestCase's forced value
        }
    }

    /** TestCase forces its environment into putenv, $_ENV and $_SERVER, which Env's immutable repository won't change. */
    private function setSwitch(?string $value): void
    {
        $key = 'BOOKING_CUSTOMER_NOTICES_ENABLED';
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_bad_payloads_unknown_events_and_missing_bookings_fail_permanently(): void
    {
        $existing = $this->booking();
        $cases = [
            ['booking.approved', ['booking_id' => 1], 'payload'],
            // A numeric string would still find the booking, so it must be refused before the lookup.
            ['booking.approved', ['booking_id' => (string) $existing->id, 'booking_version' => 1], 'payload'],
            ['booking.approved', ['booking_id' => 999999, 'booking_version' => 2], 'booking_missing'],
            ['booking.completed', ['booking_id' => 1, 'booking_version' => 2], 'event'],
        ];

        foreach ($cases as [$type, $payload, $reason]) {
            $message = (new OutboxMessage)->forceFill(['type' => $type, 'payload' => $payload]);

            try {
                $this->handle($message);
                $this->fail('Expected a permanent failure.');
            } catch (OutboxPermanentException $exception) {
                $this->assertSame($reason, $exception->reason());
            }
        }

        $this->assertSame([], $this->mails());
    }

    public function test_the_payment_summary_never_claims_money_moved(): void
    {
        $staff = $this->staff();
        $booking = $this->booking(['payment_preference' => 'at_spa']);
        $this->approve($booking, $staff);
        $booking->refresh();

        $summary = app(BookingPaymentSummary::class);
        $this->assertSame([
            'state' => 'at_spa_unrecorded',
            'deadline' => null,
            'checkout_url' => null,
            'refunds' => [],
        ], $summary->forBooking($booking));

        (new CancelBooking)($staff, $booking->id, BookingStatus::Confirmed, 'staff_cancelled', new ActionContext('dashboard'));
        $booking->refresh();

        $this->assertSame('at_spa_unrecorded', $summary->forBooking($booking)['state']);
        $this->assertSame([], $summary->forBooking($booking)['refunds']);

        $this->assertSame('unknown', $summary->forBooking($this->booking(['payment_preference' => null]))['state']);
        $this->assertSame('unknown', $summary->forBooking($this->booking(['payment_preference' => 'online']))['state']);

        $deadline = $this->booking(['payment_deadline_at' => '2026-12-05 02:30:00']);
        $this->assertSame('2026-12-05T02:30:00+00:00', $summary->forBooking($deadline)['deadline']);
    }

    public function test_customer_notices_subscribe_only_to_the_decisions_they_announce(): void
    {
        foreach (['booking.approved', 'booking.cancelled', 'booking.no_show'] as $type) {
            $this->assertSame(
                [
                    'telegram' => TelegramBookingNotificationHandler::class,
                    'customer' => SendCustomerBookingNotice::class,
                ],
                config('outbox.events')[$type],
            );
        }

        foreach (['booking.created', 'booking.confirmed', 'booking.completed'] as $type) {
            $this->assertArrayNotHasKey('customer', config('outbox.events')[$type]);
        }
    }

    public function test_a_website_booking_records_the_language_it_was_made_in(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 09:00:00', 'Asia/Jakarta'));
        $this->seed(ServiceCatalogSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->post('/bookings', $this->validPayload())
            ->assertRedirect();

        $this->assertSame('en', Booking::query()->latest('id')->firstOrFail()->locale);

        // A later request with a fresh session falls back to SetLocale's default.
        $this->flushSession();

        $this->actingAs($user)
            ->post('/bookings', $this->validPayload([
                'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a11',
                'time' => '13:30',
            ]))
            ->assertRedirect();

        $this->assertSame('id', Booking::query()->latest('id')->firstOrFail()->locale);
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create([
            'appointment_date' => '2026-12-05',
            'appointment_time' => '10:30',
            'starts_at' => '2026-12-05 03:30:00',
            'package_name_en' => 'Nezuko Massage',
            'package_name_id' => 'Pijat Nezuko',
            'customer_name' => 'Ayu Lestari',
            'customer_email' => 'ayu@example.com',
            'locale' => 'en',
            ...$overrides,
        ])->fresh();
    }

    private function staff(): User
    {
        return User::factory()->bookingStaff()->create();
    }

    private function approve(Booking $booking, User $staff): void
    {
        (new ApproveBooking)($staff, $booking->id, BookingStatus::Pending, new ActionContext('dashboard'));
    }

    private function event(string $type, Booking $booking): OutboxMessage
    {
        return OutboxMessage::query()->where('type', $type)->get()
            ->sole(fn (OutboxMessage $message): bool => $message->payload['booking_id'] === $booking->id);
    }

    private function handle(OutboxMessage $message): void
    {
        app(SendCustomerBookingNotice::class)->handle($message);
    }

    private function skippedWith(OutboxMessage $message): string
    {
        try {
            $this->handle($message);
        } catch (OutboxSkippedException $exception) {
            return $exception->reason();
        }

        $this->fail('Expected the customer notice to be skipped.');
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return collect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Ayu Lestari',
            'phone' => '+62 812-3456-7890',
            'service' => 'nezuko',
            'mode' => 'regular',
            'duration' => 90,
            'extension' => 'nezuko-regular-extra-60',
            'date' => '2026-09-07',
            'time' => '10:30',
            'guests' => 1,
            'notes' => 'Focus on shoulders.',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            'expected_total_price_idr' => 340000,
            ...$overrides,
        ];
    }
}
