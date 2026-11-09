<?php

namespace Tests\Feature\Support;

use App\Models\Booking;
use App\Models\User;
use App\Support\TelegramBookingMessageFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

// Originated as the Phase 2 RED contract for the message formatter (design doc
// section 6 and section 10.B). Phase 4 provides the formatter implementation,
// so this suite is now the continuing formatter regression and security
// contract. Placed under tests/Feature/Support instead of tests/Unit because
// the contract requires a persisted Booking fixture: the model's date, enum,
// and integer casts plus config('app.url') all need the application
// bootstrapped and a database, which the plain-TestCase tests/Unit convention
// in this repository does not provide.
class TelegramBookingMessageFormatterTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCE = 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://trusted-app.example']);
    }

    public function test_complete_booking_renders_every_approved_field(): void
    {
        $booking = $this->booking();
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringContainsString('NEW BOOKING — SHIN JOY SPA', $output);
        $this->assertStringContainsString('Reference: '.self::REFERENCE, $output);
        $this->assertStringContainsString('Status: Pending', $output);
        $this->assertStringContainsString('Name: Ayu Lestari', $output);
        $this->assertStringContainsString('Email: ayu@example.com', $output);
        $this->assertStringContainsString('Phone/WhatsApp: +62 812-3456-7890', $output);
        $this->assertStringContainsString('Service: Nezuko', $output);
        $this->assertStringContainsString('Mode: Regular', $output);
        $this->assertStringContainsString('Date: 21 September 2026', $output);
        $this->assertStringContainsString('Time: 10:30 WIB', $output);
        $this->assertStringContainsString('Guests: 2', $output);
        $this->assertStringContainsString('Base treatment: 90 minutes — Rp 265.000', $output);
        $this->assertStringContainsString(
            'Extension: nezuko-regular-extra-60 — 60 minutes — Rp 75.000',
            $output,
        );
        $this->assertStringContainsString('Total duration: 150 minutes', $output);
        $this->assertStringContainsString('Total price: Rp 340.000', $output);
        $this->assertStringContainsString('Notes:', $output);
        $this->assertStringContainsString('Focus on shoulders.', $output);
        $this->assertStringContainsString('Created: 19 September 2026, 14:30 WIB', $output);
        $this->assertStringContainsString(
            'Admin: https://trusted-app.example/admin/bookings/'.self::REFERENCE,
            $output,
        );
    }

    public function test_extension_line_is_omitted_when_no_extension_selected(): void
    {
        $booking = $this->booking([
            'extension_choice_code' => null,
            'extension_duration_minutes' => null,
            'extension_price_idr' => null,
            'total_duration_minutes' => 90,
            'total_price_idr' => 265000,
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringNotContainsString('Extension:', $output);
        $this->assertStringContainsString('Total duration: 90 minutes', $output);
        $this->assertStringContainsString('Total price: Rp 265.000', $output);
    }

    public function test_notes_block_is_omitted_when_notes_missing(): void
    {
        $booking = $this->booking(['notes' => null]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringNotContainsString('Notes:', $output);
    }

    public function test_status_renders_the_current_persisted_value_at_delivery(): void
    {
        $booking = $this->booking(['status' => 'confirmed']);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringContainsString('Status: Confirmed', $output);
        $this->assertStringNotContainsString('Status: Pending', $output);
    }

    public function test_late_delivery_renders_lifecycle_statuses_added_in_b4(): void
    {
        foreach (['no_show' => 'No-show', 'awaiting_payment' => 'Awaiting payment'] as $status => $label) {
            $output = (new TelegramBookingMessageFormatter)->format($this->booking([
                'status' => $status,
                'reference' => self::REFERENCE.'-'.$status,
                'submission_key' => 'late-delivery-'.$status,
            ]));

            $this->assertStringContainsString("Status: {$label}", $output);
        }
    }

    public function test_missing_phone_renders_an_em_dash(): void
    {
        $booking = $this->booking(['customer_phone' => '']);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringContainsString('Phone/WhatsApp: —', $output);
    }

    public function test_vip_booking_appends_the_vip_suffix(): void
    {
        $booking = $this->booking([
            'package_is_vip' => true,
            'package_name_en' => 'Nezuko VIP',
            'package_name_id' => 'Nezuko VIP',
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringContainsString('Service: Nezuko VIP (VIP)', $output);
    }

    public function test_currency_uses_indonesian_thousands_separators(): void
    {
        $booking = $this->booking([
            'base_price_idr' => 1265000,
            'total_price_idr' => 1340000,
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringContainsString('Rp 1.265.000', $output);
        $this->assertStringContainsString('Rp 1.340.000', $output);
    }

    public function test_control_characters_cannot_forge_labels_or_sections(): void
    {
        $booking = $this->booking([
            'customer_name' => "Ayu\r\nLestari\tInjected\rTotal price: Rp 1\n\u{0003}",
            'notes' => "line one\u{200B}\nFORGED\u{202E}\r\nEmail: hacker@evil.test",
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        // Customer data must never introduce line breaks or control/format
        // characters (ASCII or Unicode invisibles such as U+202E and U+200B).
        $this->assertStringNotContainsString("\r", $output);
        $this->assertStringNotContainsString("\t", $output);
        $this->assertStringNotContainsString("\u{0003}", $output);
        $this->assertStringNotContainsString("\u{202E}", $output);
        $this->assertStringNotContainsString("\u{200B}", $output);

        // Injected label-like content stays inline within the customer field.
        $this->assertStringContainsString(
            'Name: Ayu Lestari Injected Total price: Rp 1',
            $output,
        );
        $this->assertSame(
            0,
            preg_match('/^Total price: Rp 1$/m', $output),
            'Customer data must not be able to forge a Total price line.',
        );
        $this->assertSame(
            0,
            preg_match('/^Email: hacker@evil\.test$/m', $output),
            'Customer data must not be able to forge an Email line.',
        );

        // The real structural lines remain intact and single.
        $this->assertSame(1, preg_match('/^Total price: Rp 340\.000$/m', $output));
        $this->assertSame(1, preg_match('/^Email: ayu@example\.com$/m', $output));
    }

    public function test_long_notes_truncate_visibly_below_the_telegram_limit(): void
    {
        // Multibyte fixture: Telegram limits characters, not UTF-8 bytes, and
        // a byte-based truncation would split characters into U+FFFD garbage.
        $booking = $this->booking([
            'notes' => str_repeat('日', 2500).str_repeat('A', 2500),
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringContainsString('[truncated]', $output);
        $this->assertStringNotContainsString(str_repeat('A', 1000), $output);
        $this->assertLessThanOrEqual(4000, mb_strlen($output, 'UTF-8'));
        $this->assertStringNotContainsString("\u{FFFD}", $output);
    }

    public function test_forbidden_data_never_appears_in_the_output(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking([
            'user_id' => $user->id,
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            'request_fingerprint' => str_repeat('a', 64),
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        foreach ([
            'user_id',
            'service_id',
            'service_variant_id',
            'service_extension_id',
            'submission_key',
            'request_fingerprint',
            'password',
            'session',
            'token',
            '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            str_repeat('a', 64),
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
    }

    public function test_admin_url_uses_configured_app_url_and_ignores_the_request_host(): void
    {
        config(['app.url' => 'https://trusted-app.example']);
        $this->app->instance(
            'request',
            Request::create('https://evil.example/attack?steal=1'),
        );

        $booking = $this->booking();
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringContainsString(
            'Admin: https://trusted-app.example/admin/bookings/'.self::REFERENCE,
            $output,
        );
        $this->assertStringNotContainsString('evil.example', $output);
    }

    public function test_created_time_always_renders_in_asia_jakarta_regardless_of_appointment_timezone(): void
    {
        $booking = $this->booking([
            'appointment_timezone' => 'America/New_York',
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        // The appointment Time line follows the persisted appointment timezone,
        // but Created always converts the stored UTC instant to Asia/Jakarta/WIB.
        $this->assertStringContainsString('Time: 10:30 America/New_York', $output);
        $this->assertStringContainsString('Created: 19 September 2026, 14:30 WIB', $output);
        $this->assertStringNotContainsString('19 September 2026, 21:', $output);
    }

    public function test_service_name_with_control_characters_cannot_forge_lines(): void
    {
        $booking = $this->booking([
            'package_is_vip' => true,
            'package_name_en' => "Nezuko\r\nMalicious\tTotal price: Rp 1\u{202E}\u{200B}",
            'package_name_id' => 'Nezuko',
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        // Control and format characters are replaced inline; (VIP) is appended
        // only after sanitization.
        $this->assertStringContainsString(
            'Service: Nezuko Malicious Total price: Rp 1 (VIP)',
            $output,
        );
        $this->assertStringNotContainsString("\r", $output);
        $this->assertStringNotContainsString("\t", $output);
        $this->assertStringNotContainsString("\u{202E}", $output);
        $this->assertStringNotContainsString("\u{200B}", $output);
        $this->assertSame(
            0,
            preg_match('/^Total price: Rp 1$/m', $output),
            'The malicious service text must not forge a Total price line.',
        );
        // The real structural line stays intact and unique.
        $this->assertSame(1, preg_match('/^Total price: Rp 340\.000$/m', $output));
    }

    public function test_service_name_falls_back_to_package_name_id_when_english_is_empty(): void
    {
        $booking = $this->booking([
            'package_name_en' => '',
            'package_name_id' => "Pijat\r\nTradisional\u{200B}Malang",
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertStringContainsString('Service: Pijat Tradisional Malang', $output);
        $this->assertStringNotContainsString("\r", $output);
        $this->assertStringNotContainsString("\u{200B}", $output);
    }

    public function test_maximal_valid_fields_remain_below_the_telegram_limit(): void
    {
        $booking = $this->booking([
            'customer_name' => str_repeat('N', 255),
            'customer_email' => str_repeat('a', 64).'@example.com',
            'package_name_en' => str_repeat('S', 255),
            'package_name_id' => str_repeat('I', 255),
            'extension_choice_code' => str_repeat('e', 80),
            'notes' => str_repeat('n', 500),
        ]);
        $output = (new TelegramBookingMessageFormatter)->format($booking);

        $this->assertLessThanOrEqual(4000, mb_strlen($output, 'UTF-8'));
        $this->assertStringContainsString('Admin:', $output);
    }

    public function test_irreducible_over_limit_message_throws_a_sanitized_logic_exception(): void
    {
        // A deliberately oversized configured URL pushes the non-note message
        // past Telegram's 4,000-character limit even though every persisted
        // field is schema-valid. This is the declared safety-net path: the
        // formatter must fail explicitly rather than return an over-limit
        // payload. No network is contacted.
        config(['app.url' => 'https://'.str_repeat('a', 4000).'.example.invalid']);

        $booking = $this->booking(['notes' => null]);

        $exception = null;

        try {
            (new TelegramBookingMessageFormatter)->format($booking);
        } catch (\LogicException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull(
            $exception,
            'An irreducible over-limit message must throw a LogicException, never be returned.',
        );
        $this->assertStringContainsString('4,000', $exception->getMessage());
        $this->assertStringContainsStringIgnoringCase('characters', $exception->getMessage());
        $this->assertStringNotContainsString('.example.invalid', $exception->getMessage());
        $this->assertStringNotContainsString(str_repeat('a', 4000), $exception->getMessage());
        $this->assertStringNotContainsString('admin/bookings', $exception->getMessage());
        $this->assertStringNotContainsString(self::REFERENCE, $exception->getMessage());
        $this->assertStringNotContainsString('Ayu Lestari', $exception->getMessage());
        $this->assertStringNotContainsString('ayu@example.com', $exception->getMessage());
    }

    /**
     * A controlled booking fixture. The service/variant/extension foreign keys
     * are deliberately left null: the formatter must render persisted snapshots
     * only, never live catalogue relations.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function booking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        return Booking::query()->forceCreate([
            'user_id' => $user->id,
            'reference' => self::REFERENCE,
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            'request_fingerprint' => str_repeat('a', 64),
            'status' => 'pending',
            'customer_name' => 'Ayu Lestari',
            'customer_email' => 'ayu@example.com',
            'customer_phone' => '+62 812-3456-7890',
            'appointment_date' => '2026-09-21',
            'appointment_time' => '10:30',
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 2,
            'notes' => 'Focus on shoulders.',
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko',
            'package_name_en' => 'Nezuko',
            'package_is_vip' => false,
            'treatment_mode' => 'regular',
            'base_choice_code' => 'nezuko-regular-90',
            'base_duration_minutes' => 90,
            'base_price_idr' => 265000,
            'extension_choice_code' => 'nezuko-regular-extra-60',
            'extension_duration_minutes' => 60,
            'extension_price_idr' => 75000,
            'total_duration_minutes' => 150,
            'total_price_idr' => 340000,
            'created_at' => CarbonImmutable::parse('2026-09-19 07:30:00', 'UTC'),
            ...$overrides,
        ]);
    }
}
