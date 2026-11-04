<?php

namespace Tests\Feature\Scheduling;

use App\Models\BookingSettings;
use App\Support\Scheduling\BookingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingWindowTest extends TestCase
{
    private function settings(array $overrides = []): BookingSettings
    {
        return new BookingSettings([...['id' => 1, 'version' => 1, 'booking_horizon_days' => 30, 'minimum_lead_minutes' => 0,
            'minimum_checkout_minutes' => 15, 'review_target_minutes' => 60, 'review_intervals' => [
                ['weekday' => 1, 'start_minute' => 540, 'end_minute' => 1020],
                ['weekday' => 2, 'start_minute' => 540, 'end_minute' => 1020],
            ]], ...$overrides]);
    }

    public function test_approval_deadline_is_capped_at_start_and_returns_utc_without_mutation(): void
    {
        $approval = CarbonImmutable::parse('2026-10-26 10:00:00.123456 Asia/Jakarta');
        $start = CarbonImmutable::parse('2026-10-26 03:30:00.654321 UTC');
        $this->assertSame('2026-10-26 03:30:00.654321 UTC', BookingWindow::deadline($approval, $start)->format('Y-m-d H:i:s.u e'));
        $this->assertSame('2026-10-26 04:00:00.123456 UTC', BookingWindow::deadline($approval, $start->addHours(2))->format('Y-m-d H:i:s.u e'));
        $this->assertSame('10:00:00.123456', $approval->format('H:i:s.u'));
        $this->assertSame('03:30:00.654321', $start->format('H:i:s.u'));
    }

    public function test_review_openings_closings_week_wrap_gaps_precision_and_timezone(): void
    {
        foreach ([
            ['2026-10-26 16:30:00', '2026-10-27 09:30:00'],
            ['2026-10-26 17:00:00', '2026-10-27 10:00:00'],
            ['2026-10-26 16:00:00', '2026-10-26 17:00:00'],
            ['2026-10-25 22:00:00', '2026-10-26 10:00:00'],
            ['2026-10-27 17:00:00', '2026-11-02 10:00:00'],
            ['2026-10-26 16:30:00.123456', '2026-10-27 09:30:00.123456'],
        ] as [$submitted, $expected]) {
            $at = CarbonImmutable::parse($submitted, 'Asia/Jakarta');
            $before = $at->format('c.u');
            $result = BookingWindow::reviewDeadline($at->setTimezone('America/New_York'), $this->settings());
            $this->assertTrue($result->equalTo(CarbonImmutable::parse($expected, 'Asia/Jakarta')), $submitted.' yielded '.$result);
            $this->assertSame('UTC', $result->timezoneName);
            $this->assertSame($before, $at->format('c.u'));
        }
        $settings = $this->settings(['review_intervals' => [
            ['weekday' => 1, 'start_minute' => 540, 'end_minute' => 720],
            ['weekday' => 1, 'start_minute' => 780, 'end_minute' => 1020],
        ]]);
        $this->assertSame('2026-10-26 13:30:00', BookingWindow::reviewDeadline(CarbonImmutable::parse('2026-10-26 11:30 Asia/Jakarta'), $settings)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-26 14:00:00', BookingWindow::reviewDeadline(CarbonImmutable::parse('2026-10-26 12:00 Asia/Jakarta'), $settings)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
    }

    public function test_sparse_maximum_target_terminates_with_exact_budget_and_split_overnight_hours(): void
    {
        $settings = $this->settings(['review_target_minutes' => 10080, 'review_intervals' => [['weekday' => 1, 'start_minute' => 540, 'end_minute' => 541]]]);
        $at = CarbonImmutable::parse('2026-10-26 09:00 Asia/Jakarta');
        $expected = $at->addWeeks(10079)->addMinute();
        $this->assertTrue(BookingWindow::reviewDeadline($at, $settings)->equalTo($expected));
        $overnight = $this->settings(['review_intervals' => [['weekday' => 1, 'start_minute' => 1380, 'end_minute' => 1440], ['weekday' => 2, 'start_minute' => 0, 'end_minute' => 60]]]);
        $this->assertTrue(BookingWindow::reviewDeadline(CarbonImmutable::parse('2026-10-26 23:30 Asia/Jakarta'), $overnight)->equalTo(CarbonImmutable::parse('2026-10-27 00:30 Asia/Jakarta')));
    }

    public function test_corrupt_persisted_fields_order_overlap_and_provider_configuration_fail_explicitly(): void
    {
        foreach ([['review_intervals' => []], ['review_intervals' => [['weekday' => true, 'start_minute' => 0, 'end_minute' => 60]]],
            ['review_intervals' => [['weekday' => 1, 'start_minute' => 60, 'end_minute' => 60]]],
            ['review_intervals' => [['weekday' => 2, 'start_minute' => 0, 'end_minute' => 60], ['weekday' => 1, 'start_minute' => 0, 'end_minute' => 60]]],
            ['review_target_minutes' => null], ['minimum_checkout_minutes' => 61], ['booking_horizon_days' => '01'],
            ['review_intervals' => [['weekday' => 1, 'start_minute' => 0, 'end_minute' => 60, 'unknown' => 1]]]] as $invalid) {
            try {
                BookingWindow::reviewDeadline(CarbonImmutable::now(), $this->settings($invalid));
                $this->fail('Corrupt persisted settings accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        config(['booking.online_payments_enabled' => true, 'booking.minimum_provider_checkout_minutes' => null]);
        $this->expectException(ValidationException::class);
        BookingWindow::reviewDeadline(CarbonImmutable::now(), $this->settings());
    }

    public function test_review_deadline_accepts_reordered_json_object_keys_without_relaxing_list_order_or_value_types(): void
    {
        $settings = $this->settings();
        $raw = $settings->getAttributes();
        $settings->setRawAttributes([...$raw, 'review_intervals' => '[{"end_minute":1020,"start_minute":540,"weekday":1},{"start_minute":540,"weekday":2,"end_minute":1020}]']);
        $original = $settings->getAttributes();
        $at = CarbonImmutable::parse('2026-10-26 16:30:00.123456 Asia/Jakarta');
        $this->assertTrue(BookingWindow::reviewDeadline($at, $settings)->equalTo(CarbonImmutable::parse('2026-10-27 09:30:00.123456 Asia/Jakarta')));
        $this->assertSame($original, $settings->getAttributes());
        foreach ([
            '[{"end_minute":1020,"start_minute":540,"weekday":2},{"end_minute":1020,"start_minute":540,"weekday":1}]',
            '[{"end_minute":1020,"start_minute":540,"weekday":"1"}]',
            '[{"end_minute":1020,"start_minute":540.0,"weekday":1}]',
            '[{"end_minute":true,"start_minute":540,"weekday":1}]',
        ] as $invalid) {
            $corrupt = new BookingSettings;
            $corrupt->setRawAttributes([...$raw, 'review_intervals' => $invalid]);
            try {
                BookingWindow::reviewDeadline($at, $corrupt);
                $this->fail('Malformed or unsorted saved intervals accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
