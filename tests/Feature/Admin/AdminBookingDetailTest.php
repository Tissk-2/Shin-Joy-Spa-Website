<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBookingDetailTest extends TestCase
{
    use RefreshDatabase;

    // Production break caught: the detail endpoint must expose the complete operational allow-list and immutable audit snapshots only.
    public function test_detail_exposes_only_operational_fields_and_ordered_history(): void
    {
        $viewer = User::factory()->bookingStaff()->create([
            'name' => 'Console Administrator',
            'email' => 'console@example.test',
        ]);
        $deletedActor = User::factory()->admin()->create([
            'name' => 'LIVE-ADMIN-NAME-CANARY',
            'email' => 'live-admin-email-canary@example.test',
        ]);
        $booking = Booking::factory()->create([
            'reference' => 'SJ-DETAIL-CONTRACT',
            'submission_key' => 'SECRET-SUBMISSION-KEY-CANARY',
            'request_fingerprint' => 'SECRET-FINGERPRINT-CANARY',
            'status' => BookingStatus::Pending,
            'customer_name' => 'Ayu Lestari',
            'customer_email' => 'ayu@example.test',
            'customer_phone' => '+6281234567890',
            'appointment_date' => '2026-09-08',
            'appointment_time' => '14:30',
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 2,
            'notes' => 'Shoulders only',
            'package_name_id' => 'Nezuko VIP',
            'package_name_en' => 'Nezuko VIP',
            'package_is_vip' => true,
            'treatment_mode' => 'vip',
            'base_choice_code' => 'nezuko-vip-120',
            'base_duration_minutes' => 120,
            'base_price_idr' => 395000,
            'extension_choice_code' => 'head-massage-30',
            'extension_duration_minutes' => 30,
            'extension_price_idr' => 75000,
            'total_duration_minutes' => 150,
            'total_price_idr' => 470000,
        ]);

        $later = BookingStatusChange::factory()->for($booking)->for($deletedActor, 'admin')->create([
            'actor_name' => 'Archived Administrator',
            'actor_email' => 'archived@example.test',
            'from_status' => BookingStatus::Completed,
            'to_status' => BookingStatus::Cancelled,
            'reason' => 'Correction recorded',
            'ip_address' => 'PRIVATE-IP-CANARY',
            'user_agent' => 'PRIVATE-UA-CANARY',
            'created_at' => '2026-09-07 10:01:00',
        ]);
        $firstAtSameTime = BookingStatusChange::factory()->for($booking)->create([
            'actor_name' => 'First Administrator',
            'actor_email' => 'first@example.test',
            'from_status' => BookingStatus::Pending,
            'to_status' => BookingStatus::Confirmed,
            'reason' => null,
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $secondAtSameTime = BookingStatusChange::factory()->for($booking)->create([
            'actor_name' => 'Second Administrator',
            'actor_email' => 'second@example.test',
            'from_status' => BookingStatus::Confirmed,
            'to_status' => BookingStatus::Completed,
            'reason' => 'Treatment finished',
            'created_at' => '2026-09-07 10:00:00',
        ]);

        $deletedActor->delete();

        $this->assertNull($later->fresh()->admin_user_id);
        $this->assertLessThan($secondAtSameTime->id, $firstAtSameTime->id);

        config(['database.connections.sqlite.database' => 'PRIVATE-CONFIG-CANARY']);
        $response = $this->actingAs($viewer)
            ->withSession(['internal_exception' => 'PRIVATE-EXCEPTION-CANARY'])
            ->get(route('admin.bookings.show', $booking));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/bookings/Show', false)
            ->has('booking.history', 3)
            ->where('booking.notes', 'Shoulders only')
            ->where('backUrl', '/admin/bookings'));

        $props = $response->inertiaProps();
        $this->assertSame(
            ['errors', 'auth', 'locale', 'flash', 'catalog', 'content', 'booking', 'backUrl'],
            array_keys($props),
        );
        $this->assertSame([
            'reference' => 'SJ-DETAIL-CONTRACT',
            'status' => 'pending',
            'package' => [
                'nameId' => 'Nezuko VIP',
                'nameEn' => 'Nezuko VIP',
                'isVip' => true,
            ],
            'treatmentMode' => 'vip',
            'appointment' => [
                'date' => '2026-09-08',
                'time' => '14:30',
                'timezone' => 'Asia/Jakarta',
            ],
            'customer' => [
                'name' => 'Ayu Lestari',
                'email' => 'ayu@example.test',
                'phone' => '+6281234567890',
            ],
            'guests' => 2,
            'totalDurationMinutes' => 150,
            'totalPriceIdr' => 470000,
            'allowedTransitions' => ['confirmed', 'cancelled'],
            'roomSchedulingEnabled' => false,
            'assignedRoom' => null,
            'base' => [
                'choiceCode' => 'nezuko-vip-120',
                'durationMinutes' => 120,
                'priceIdr' => 395000,
            ],
            'extension' => [
                'choiceCode' => 'head-massage-30',
                'durationMinutes' => 30,
                'priceIdr' => 75000,
            ],
            'notes' => 'Shoulders only',
            'history' => [
                [
                    'actor' => ['name' => 'First Administrator', 'email' => 'first@example.test'],
                    'fromStatus' => 'pending',
                    'toStatus' => 'confirmed',
                    'reason' => null,
                    'createdAt' => '2026-09-07T10:00:00.000000Z',
                    'operationalConfirmedAt' => null,
                ],
                [
                    'actor' => ['name' => 'Second Administrator', 'email' => 'second@example.test'],
                    'fromStatus' => 'confirmed',
                    'toStatus' => 'completed',
                    'reason' => 'Treatment finished',
                    'createdAt' => '2026-09-07T10:00:00.000000Z',
                    'operationalConfirmedAt' => null,
                ],
                [
                    'actor' => ['name' => 'Archived Administrator', 'email' => 'archived@example.test'],
                    'fromStatus' => 'completed',
                    'toStatus' => 'cancelled',
                    'reason' => 'Correction recorded',
                    'createdAt' => '2026-09-07T10:01:00.000000Z',
                    'operationalConfirmedAt' => null,
                ],
            ],
        ], $props['booking']);

        $serialized = json_encode($props['booking'], JSON_THROW_ON_ERROR);
        foreach ([
            'SECRET-SUBMISSION-KEY-CANARY',
            'SECRET-FINGERPRINT-CANARY',
            'PRIVATE-IP-CANARY',
            'PRIVATE-UA-CANARY',
            'LIVE-ADMIN-NAME-CANARY',
            'live-admin-email-canary@example.test',
            'PRIVATE-EXCEPTION-CANARY',
            'PRIVATE-CONFIG-CANARY',
            'submission_key',
            'request_fingerprint',
            'booking_id',
            'admin_user_id',
            'ip_address',
            'user_agent',
            'exception',
            'configuration',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    // Production break caught: bookings without an extension must present a literal null, not a partial object.
    public function test_detail_extension_may_be_null(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'extension_choice_code' => null,
            'extension_duration_minutes' => null,
            'extension_price_idr' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.bookings.show', $booking));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/bookings/Show', false)
            ->where('booking.extension', null)
            ->where('booking.history', []));
    }

    // Production break caught: only the validated index writer may choose the detail page's return destination.
    public function test_detail_uses_the_validated_filtered_index_url_and_ignores_hostile_redirect_parameters(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'customer_name' => 'Ayu Lestari',
            'appointment_date' => '2026-09-08',
        ]);

        $this->actingAs($admin)->get('/admin/bookings?'.http_build_query([
            'scope' => 'all',
            'status' => 'confirmed',
            'q' => '  Ayu  ',
            'dateFrom' => '2026-09-01',
            'dateTo' => '2026-09-30',
        ]))->assertOk();

        $response = $this->get(route('admin.bookings.show', $booking, absolute: false).'?'.http_build_query([
            'backUrl' => 'https://attacker.example/back',
            'returnTo' => '//attacker.example/return',
            'redirect' => '/admin/users',
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where(
            'backUrl',
            '/admin/bookings?scope=all&status=confirmed&q=Ayu&dateFrom=2026-09-01&dateTo=2026-09-30',
        ));

        $serialized = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('attacker.example', $serialized);
        $this->assertStringNotContainsString('/admin/users', $serialized);
    }

    // Production break caught: implicit binding must fail closed for an unknown public reference.
    public function test_verified_admin_receives_not_found_for_a_missing_reference(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/bookings/SJ-MISSING-DETAIL')
            ->assertNotFound();
    }
}
