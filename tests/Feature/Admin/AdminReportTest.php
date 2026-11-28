<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-28 14:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_only_a_verified_owner_can_view_reports(): void
    {
        $booking = Booking::factory()->create(['customer_name' => 'PRIVATE-REPORT-CANARY']);
        $customer = User::factory()->create();
        $staff = User::factory()->bookingStaff()->create();
        $unverifiedOwner = User::factory()->owner()->unverified()->create();
        $owner = User::factory()->owner()->create();

        $this->get('/admin/reports')->assertRedirect(route('login', absolute: false));
        $this->actingAs($unverifiedOwner)->get('/admin/reports')
            ->assertRedirect(route('verification.notice', absolute: false));
        $this->actingAs($customer)->get('/admin/reports')->assertForbidden();
        $this->actingAs($staff)->get('/admin/reports')->assertForbidden();

        // Revoked owner in database loses access even with a stale session model.
        $revokedOwner = User::factory()->owner()->create();
        $this->actingAs($revokedOwner);
        DB::table('users')->where('id', $revokedOwner->id)->update(['role' => UserRole::Admin->value]);
        $this->get('/admin/reports')->assertForbidden();

        $response = $this->actingAs($owner)->get('/admin/reports')->assertOk();
        $this->assertStringNotContainsString($booking->customer_name, $response->getContent());
        $response->assertInertia(fn (Assert $page) => $page
            ->component('admin/Reports', false)
            ->where('report.period', '30d'));
    }

    public function test_reports_aggregate_outcomes_packages_hours_and_channels_for_the_selected_period(): void
    {
        $owner = User::factory()->owner()->create();

        // Inside 7d window (2026-11-22..2026-11-28)
        Booking::factory()->create([
            'status' => BookingStatus::Completed,
            'appointment_date' => '2026-11-28',
            'appointment_time' => '10:30',
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko',
            'package_name_en' => 'Nezuko',
            'base_duration_minutes' => 90,
            'total_price_idr' => 265000,
            'source_channel' => 'website',
        ]);
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'appointment_date' => '2026-11-27',
            'appointment_time' => '10:00',
            'package_slug' => 'nezuko-vip',
            'package_name_id' => 'Nezuko VIP',
            'package_name_en' => 'Nezuko VIP',
            'package_is_vip' => true,
            'base_duration_minutes' => 120,
            'total_price_idr' => 360000,
            'source_channel' => 'walk_in',
        ]);
        Booking::factory()->create([
            'status' => BookingStatus::Cancelled,
            'refund_reason' => 'expired',
            'appointment_date' => '2026-11-26',
            'appointment_time' => '14:00',
            'total_price_idr' => 180000,
            'source_channel' => 'website',
        ]);
        Booking::factory()->create([
            'status' => BookingStatus::Cancelled,
            'refund_reason' => 'slot_full',
            'appointment_date' => '2026-11-25',
            'appointment_time' => '15:00',
            'total_price_idr' => 180000,
            'source_channel' => 'website',
        ]);
        Booking::factory()->create([
            'status' => BookingStatus::NoShow,
            'appointment_date' => '2026-11-24',
            'appointment_time' => '16:00',
            'total_price_idr' => 200000,
            'source_channel' => 'phone',
        ]);

        // Inside 30d window, outside 7d window (2026-11-10)
        Booking::factory()->create([
            'status' => BookingStatus::Completed,
            'appointment_date' => '2026-11-10',
            'appointment_time' => '11:00',
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko',
            'package_name_en' => 'Nezuko',
            'base_duration_minutes' => 90,
            'total_price_idr' => 265000,
            'source_channel' => 'whatsapp',
        ]);

        // Outside 30d window (2026-10-01)
        Booking::factory()->create([
            'status' => BookingStatus::Completed,
            'appointment_date' => '2026-10-01',
            'appointment_time' => '11:00',
            'total_price_idr' => 999000,
        ]);

        $this->actingAs($owner)->get('/admin/reports?period=7d')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.period', '7d')
                ->where('report.range', ['from' => '2026-11-22', 'to' => '2026-11-28'])
                ->has('report.dailyTrend', 7)
                ->where('report.summary.totalBookings', 5)
                ->where('report.summary.completedCount', 1)
                ->where('report.summary.confirmedCount', 1)
                ->where('report.summary.cancelledCount', 2)
                ->where('report.summary.expiredCount', 1)
                ->where('report.summary.slotFullCount', 1)
                ->where('report.summary.manualCancelledCount', 0)
                ->where('report.summary.noShowCount', 1)
                ->where('report.summary.estimatedCompletedRevenueIdr', 265000)
                ->where('report.summary.confirmedPipelineIdr', 360000)
                ->where('report.summary.completionRate', 25)
                ->where('report.previousRange', ['from' => '2026-11-15', 'to' => '2026-11-21'])
                ->where('report.previousSummary', [
                    'totalBookings' => 0,
                    'completedCount' => 0,
                    'lostCount' => 0,
                    'estimatedCompletedRevenueIdr' => 0,
                ])
                ->where('report.peakHours.1', ['hour' => '10:00', 'count' => 2]));

        // Invalid period falls back safely to 30d (and 2026-10-01 lands in its previous 30d window).
        $this->actingAs($owner)->get('/admin/reports?period=drop-table')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.period', '30d')
                ->where('report.summary.totalBookings', 6)
                ->where('report.summary.completedCount', 2)
                ->where('report.summary.estimatedCompletedRevenueIdr', 530000)
                ->where('report.previousSummary.totalBookings', 1)
                ->where('report.previousSummary.completedCount', 1)
                ->where('report.previousSummary.estimatedCompletedRevenueIdr', 999000));

        // Month-to-date starts on 2026-11-01.
        $this->actingAs($owner)->get('/admin/reports?period=month')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.period', 'month')
                ->where('report.range', ['from' => '2026-11-01', 'to' => '2026-11-28'])
                ->has('report.dailyTrend', 28));
    }
}
