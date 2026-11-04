<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardUpcomingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00:59', 'Asia/Jakarta'));
        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_upcoming_filters_current_minute_status_and_inclusive_horizon_before_limit(): void
    {
        $today = CarbonImmutable::now('Asia/Jakarta');
        $earlierToday = $this->bookingAt('SJ-DASH-EARLIER', $today->toDateString(), '11:59');
        $currentMinuteFirst = $this->bookingAt('SJ-DASH-CURRENT-A', $today->toDateString(), '12:00');
        $currentMinuteSecond = $this->bookingAt('SJ-DASH-CURRENT-B', $today->toDateString(), '12:00', BookingStatus::Confirmed);
        $this->bookingAt('SJ-DASH-CANCELLED', $today->toDateString(), '12:00', BookingStatus::Cancelled);
        $this->bookingAt('SJ-DASH-COMPLETED', $today->toDateString(), '12:00', BookingStatus::Completed);
        $laterToday = $this->bookingAt('SJ-DASH-LATER', $today->toDateString(), '12:01', BookingStatus::Confirmed);
        $tomorrow = $this->bookingAt('SJ-DASH-TOMORROW', $today->addDay()->toDateString(), '09:00');
        $horizon = $this->bookingAt('SJ-DASH-HORIZON', $today->addDays(14)->toDateString(), '12:00');
        $this->bookingAt('SJ-DASH-OUTSIDE-HORIZON', $today->addDays(15)->toDateString(), '09:00');

        $expected = [
            $currentMinuteFirst->reference,
            $currentMinuteSecond->reference,
            $laterToday->reference,
            $tomorrow->reference,
            $horizon->reference,
        ];
        $actual = $this->dashboardUpcomingReferences();
        $this->assertSame($expected, $actual);
        $this->assertNotContains($earlierToday->reference, $this->dashboardUpcomingReferences());
    }

    public function test_earlier_today_records_do_not_crowd_upcoming_rows_out_of_the_six_item_panel(): void
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        for ($hour = 6; $hour < 12; $hour++) {
            $this->bookingAt(
                sprintf('SJ-DASH-EARLY-%02d', $hour),
                $today,
                sprintf('%02d:00', $hour),
            );
        }

        $futureBookings = [];
        foreach (['12:00', '12:15', '12:30', '12:45', '13:00', '13:15', '13:30'] as $index => $time) {
            $futureBookings[] = $this->bookingAt(
                sprintf('SJ-DASH-FUTURE-%02d', $index),
                $today,
                $time,
            );
        }

        $this->assertSame(
            array_map(fn (Booking $booking): string => $booking->reference, array_slice($futureBookings, 0, 6)),
            $this->dashboardUpcomingReferences(),
        );
    }

    /** @return list<string> */
    private function dashboardUpcomingReferences(): array
    {
        $response = $this->actingAs($this->admin)->get('/admin');
        $response->assertOk();

        return array_column($response->inertiaProps('upcoming'), 'reference');
    }

    private function bookingAt(
        string $reference,
        string $date,
        string $time,
        BookingStatus $status = BookingStatus::Pending,
    ): Booking {
        return Booking::factory()->for($this->customer)->create([
            'reference' => $reference,
            'appointment_date' => $date,
            'appointment_time' => $time,
            'status' => $status,
        ]);
    }
}
