<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookingPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 10:30:00', 'Asia/Jakarta'));
        $this->seed(ServiceCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // Production break caught: guests and unverified customers must not view booking history or learn references.
    public function test_guest_and_unverified_customers_cannot_view_booking_pages(): void
    {
        $booking = Booking::factory()->create();
        $unverified = User::factory()->unverified()->create();

        $this->get('/bookings')
            ->assertRedirect(route('login', absolute: false));
        $this->get('/bookings/'.$booking->reference)
            ->assertRedirect(route('login', absolute: false));

        $this->actingAs($unverified)
            ->get('/bookings')
            ->assertRedirect(route('verification.notice', absolute: false));
        $this->actingAs($unverified)
            ->get('/bookings/'.$booking->reference)
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    // Production break caught: the index must expose only owned booking summaries with the allow-listed shape.
    public function test_index_lists_only_current_customers_booking_summary_contract(): void
    {
        $user = User::factory()->create();
        $owned = Booking::factory()->for($user)->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL10',
            'status' => BookingStatus::Confirmed,
            'appointment_date' => '2026-09-06',
            'appointment_time' => '10:30',
            'guests' => 2,
        ]);
        Booking::factory()->for(User::factory())->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL11',
            'appointment_date' => '2026-09-06',
            'appointment_time' => '10:30',
        ]);
        Booking::factory()->create([
            'user_id' => null,
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL12',
            'appointment_date' => '2026-09-06',
            'appointment_time' => '10:30',
        ]);

        $response = $this->actingAs($user)->get('/bookings');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Index', false)
                ->has('upcoming', 1)
                ->has('past', 0));

        $summary = $response->inertiaProps('upcoming.0');
        $this->assertSummaryContract($summary);
        $this->assertSame($this->expectedSummary($owned), $summary);
        $this->assertSafeBookingProps($response->inertiaProps('upcoming'));
        $this->assertSafeBookingProps($response->inertiaProps('past'));
    }

    // Production break caught: detail reads must be user-scoped and anonymized rows must behave like missing references.
    public function test_detail_returns_owned_booking_contract_and_404s_for_cross_account_or_anonymized_rows(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $owned = Booking::factory()->for($user)->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL20',
            'customer_name' => 'Ayu Lestari',
            'customer_email' => 'ayu@example.test',
            'customer_phone' => '+6281234567890',
            'extension_choice_code' => 'nezuko-regular-extra-60',
            'extension_duration_minutes' => 60,
            'extension_price_idr' => 75000,
            'notes' => 'Focus on shoulders.',
        ]);
        $otherBooking = Booking::factory()->for($otherUser)->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL21',
        ]);
        $anonymized = Booking::factory()->create([
            'user_id' => null,
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL22',
        ]);

        $response = $this->actingAs($user)->get('/bookings/'.$owned->reference);

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Show', false)
                ->has('booking'));

        $booking = $response->inertiaProps('booking');
        $this->assertDetailContract($booking);
        $this->assertSame($this->expectedDetail($owned), $booking);
        $this->assertSafeBookingProps($booking);

        $this->actingAs($user)
            ->get('/bookings/'.$otherBooking->reference)
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/bookings/'.$anonymized->reference)
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->get('/bookings/'.$owned->reference)
            ->assertNotFound();
    }

    // Production break caught: customer pages must read immutable booking snapshots, not mutable catalog relations.
    public function test_detail_uses_persisted_snapshots_after_catalog_edits(): void
    {
        $user = User::factory()->create();
        $service = Service::query()->where('slug', 'nezuko')->sole();
        $variant = ServiceVariant::query()->where('code', 'nezuko-regular-90')->sole();
        $extension = ServiceExtension::query()->where('code', 'nezuko-regular-extra-60')->sole();
        $booking = Booking::factory()->for($user)->create([
            'service_id' => $service->id,
            'service_variant_id' => $variant->id,
            'service_extension_id' => $extension->id,
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL30',
            'package_name_id' => 'Nezuko Lama',
            'package_name_en' => 'Old Nezuko',
            'base_price_idr' => 265000,
            'extension_choice_code' => 'nezuko-regular-extra-60',
            'extension_duration_minutes' => 60,
            'extension_price_idr' => 75000,
            'total_price_idr' => 340000,
        ]);

        $service->update([
            'name_id' => 'Nezuko Baru',
            'name_en' => 'New Nezuko',
            'is_vip' => true,
        ]);
        $variant->update(['price_idr' => 999999]);
        $extension->update(['price_idr' => 888888]);

        $response = $this->actingAs($user)->get('/bookings/'.$booking->reference);

        $response->assertOk();
        $this->assertSame('Nezuko Lama', $response->inertiaProps('booking.package.nameId'));
        $this->assertSame('Old Nezuko', $response->inertiaProps('booking.package.nameEn'));
        $this->assertFalse($response->inertiaProps('booking.package.isVip'));
        $this->assertSame(265000, $response->inertiaProps('booking.base.priceIdr'));
        $this->assertSame(75000, $response->inertiaProps('booking.extension.priceIdr'));
        $this->assertSame(340000, $response->inertiaProps('booking.totalPriceIdr'));
    }

    // Production break caught: index grouping must use the appointment instant in its persisted timezone and stable chronological ordering.
    public function test_index_groups_equality_at_now_and_orders_upcoming_and_past_chronologically(): void
    {
        $user = User::factory()->create();
        $oldPast = Booking::factory()->for($user)->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL40',
            'appointment_date' => '2026-09-05',
            'appointment_time' => '20:00',
        ]);
        $recentPast = Booking::factory()->for($user)->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL41',
            'status' => BookingStatus::Cancelled,
            'appointment_date' => '2026-09-06',
            'appointment_time' => '10:00',
        ]);
        $soon = Booking::factory()->for($user)->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL42',
            'appointment_date' => '2026-09-06',
            'appointment_time' => '11:00',
        ]);
        $equalNow = Booking::factory()->for($user)->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL43',
            'status' => BookingStatus::Confirmed,
            'appointment_date' => '2026-09-06',
            'appointment_time' => '10:30',
        ]);
        $laterCancelled = Booking::factory()->for($user)->create([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL44',
            'status' => BookingStatus::Cancelled,
            'appointment_date' => '2026-09-07',
            'appointment_time' => '09:00',
        ]);

        $response = $this->actingAs($user)->get('/bookings');

        $response->assertOk();
        $this->assertSame([
            $equalNow->reference,
            $soon->reference,
            $laterCancelled->reference,
        ], array_column($response->inertiaProps('upcoming'), 'reference'));
        $this->assertSame([
            $recentPast->reference,
            $oldPast->reference,
        ], array_column($response->inertiaProps('past'), 'reference'));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function assertSummaryContract(array $summary): void
    {
        $this->assertSame([
            'reference',
            'status',
            'package',
            'treatmentMode',
            'appointment',
            'guests',
            'totalDurationMinutes',
            'totalPriceIdr',
        ], array_keys($summary));
        $this->assertSame(['slug', 'nameId', 'nameEn', 'isVip'], array_keys($summary['package']));
        $this->assertSame(['date', 'time', 'timezone'], array_keys($summary['appointment']));
    }

    /**
     * @param  array<string, mixed>  $booking
     */
    private function assertDetailContract(array $booking): void
    {
        $this->assertSame([
            'reference',
            'status',
            'package',
            'treatmentMode',
            'appointment',
            'guests',
            'totalDurationMinutes',
            'totalPriceIdr',
            'customer',
            'base',
            'extension',
            'notes',
        ], array_keys($booking));
        $this->assertSame(['slug', 'nameId', 'nameEn', 'isVip'], array_keys($booking['package']));
        $this->assertSame(['date', 'time', 'timezone'], array_keys($booking['appointment']));
        $this->assertSame(['name', 'email', 'phone'], array_keys($booking['customer']));
        $this->assertSame(['choiceCode', 'durationMinutes', 'priceIdr'], array_keys($booking['base']));

        if ($booking['extension'] !== null) {
            $this->assertSame(['choiceCode', 'durationMinutes', 'priceIdr'], array_keys($booking['extension']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedSummary(Booking $booking): array
    {
        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'package' => [
                'slug' => $booking->package_slug,
                'nameId' => $booking->package_name_id,
                'nameEn' => $booking->package_name_en,
                'isVip' => $booking->package_is_vip,
            ],
            'treatmentMode' => $booking->treatment_mode,
            'appointment' => [
                'date' => $booking->appointment_date->format('Y-m-d'),
                'time' => $booking->appointment_time,
                'timezone' => $booking->appointment_timezone,
            ],
            'guests' => $booking->guests,
            'totalDurationMinutes' => $booking->total_duration_minutes,
            'totalPriceIdr' => $booking->total_price_idr,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedDetail(Booking $booking): array
    {
        return [
            ...$this->expectedSummary($booking),
            'customer' => [
                'name' => $booking->customer_name,
                'email' => $booking->customer_email,
                'phone' => $booking->customer_phone,
            ],
            'base' => [
                'choiceCode' => $booking->base_choice_code,
                'durationMinutes' => $booking->base_duration_minutes,
                'priceIdr' => $booking->base_price_idr,
            ],
            'extension' => $booking->extension_choice_code === null ? null : [
                'choiceCode' => $booking->extension_choice_code,
                'durationMinutes' => $booking->extension_duration_minutes,
                'priceIdr' => $booking->extension_price_idr,
            ],
            'notes' => $booking->notes,
        ];
    }

    private function assertSafeBookingProps(mixed $props): void
    {
        $serialized = json_encode($props, JSON_THROW_ON_ERROR);

        foreach ([
            'user_id',
            'service_id',
            'service_variant_id',
            'service_extension_id',
            'submission_key',
            'request_fingerprint',
            'created_at',
            'updated_at',
            'customer_name',
            'customer_email',
            'customer_phone',
            'package_slug',
            'package_name_id',
            'package_name_en',
            'base_choice_code',
            'base_duration_minutes',
            'base_price_idr',
            'extension_choice_code',
            'extension_duration_minutes',
            'extension_price_idr',
            'total_duration_minutes',
            'total_price_idr',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }
}
