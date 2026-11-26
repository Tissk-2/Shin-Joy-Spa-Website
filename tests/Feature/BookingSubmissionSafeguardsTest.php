<?php

namespace Tests\Feature;

use App\Actions\Catalog\AttestPackagePricing;
use App\Enums\PricingBasis;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSubmissionSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 09:00:00', 'Asia/Jakarta'));
        $this->seed(ServiceCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_stale_reviewed_price_cannot_create_a_booking(): void
    {
        $user = User::factory()->create();

        ServiceVariant::query()->where('code', 'nezuko-regular-90')->update(['price_idr' => 300000]);

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', $this->payload())
            ->assertRedirect('/booking')
            ->assertSessionHasErrors('expected_total_price_idr');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_current_reviewed_price_creates_booking_using_server_quote(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/bookings', $this->payload())->assertStatus(303);

        $this->assertSame(365000, Booking::query()->sole()->total_price_idr);
    }

    // Without the owner's confirmation the basis is unknown; for one guest both bases give the same total.
    public function test_unconfirmed_pricing_books_one_guest_and_refuses_a_group(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', [...$this->payload(), 'guests' => 3])
            ->assertRedirect('/booking')
            ->assertSessionHasErrors('guests');
        $this->assertDatabaseCount('bookings', 0);

        $this->actingAs($user)->post('/bookings', $this->payload())->assertStatus(303);

        $booking = Booking::query()->sole();
        $this->assertSame(1, $booking->guests);
        $this->assertSame('Ayu Lestari', $booking->customer_name);
        $this->assertSame('+6281234567890', $booking->customer_phone);
        $this->assertSame('Focus on shoulders.', $booking->notes);
        $this->assertSame(365000, $booking->total_price_idr);
    }

    public function test_a_date_more_than_365_days_ahead_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', [...$this->payload(), 'date' => '2027-09-07'])
            ->assertRedirect('/booking')
            ->assertSessionHasErrors(['date' => 'Pilih tanggal dalam 365 hari ke depan.']);
        $this->assertDatabaseCount('bookings', 0);

        $this->actingAs($user)->post('/bookings', [...$this->payload(), 'date' => '2027-09-06'])->assertStatus(303);
        $this->assertSame('2027-09-06', Booking::query()->sole()->appointment_date->format('Y-m-d'));
    }

    public function test_missing_reviewed_price_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();
        unset($payload['expected_total_price_idr']);

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', $payload)
            ->assertSessionHasErrors('expected_total_price_idr');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_exact_replay_after_appointment_time_returns_original_booking(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();

        $this->actingAs($user)->post('/bookings', $payload)->assertStatus(303);
        $reference = Booking::query()->sole()->reference;

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 09:00:00', 'Asia/Jakarta'));

        $this->actingAs($user)
            ->post('/bookings', $payload)
            ->assertStatus(303)
            ->assertRedirect('/bookings/'.$reference);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_changed_replay_after_appointment_time_reports_used_key(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();

        $this->actingAs($user)->post('/bookings', $payload)->assertStatus(303);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 09:00:00', 'Asia/Jakarta'));

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', [...$payload, 'notes' => 'Changed note'])
            ->assertSessionHasErrors('submission_key');

        $this->assertDatabaseCount('bookings', 1);
    }

    // Production break caught: a verified per-person package must charge every guest and snapshot its coverage.
    public function test_verified_per_person_submission_multiplies_guests_and_snapshots_coverage(): void
    {
        $this->attestNezuko(PricingBasis::PerPerson, 1, 4);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/bookings', [...$this->payload(), 'guests' => 3, 'expected_total_price_idr' => 1095000])
            ->assertStatus(303);

        $booking = Booking::query()->sole();
        $this->assertSame(1095000, $booking->total_price_idr);
        $this->assertSame(265000, $booking->base_price_idr);
        $this->assertSame(100000, $booking->extension_price_idr);
        $this->assertSame('per_person', $booking->pricing_basis?->value);
        $this->assertSame(1, $booking->min_guests);
        $this->assertSame(4, $booking->max_guests);
    }

    // Production break caught: a client total from the old one-time formula must be rejected after verification.
    public function test_stale_pre_verification_total_is_rejected_for_verified_pricing(): void
    {
        $this->attestNezuko(PricingBasis::PerPerson, 1, 4);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', [...$this->payload(), 'guests' => 2])
            ->assertSessionHasErrors('expected_total_price_idr');

        $this->assertDatabaseCount('bookings', 0);
    }

    // Production break caught: later catalog edits must never reprice an already submitted quote.
    public function test_submitted_quote_snapshot_survives_later_catalog_price_changes(): void
    {
        $this->attestNezuko(PricingBasis::PerPerson, 1, 4);
        $user = User::factory()->create();
        $payload = [...$this->payload(), 'guests' => 2, 'expected_total_price_idr' => 730000];

        $this->actingAs($user)->post('/bookings', $payload)->assertStatus(303);
        $reference = Booking::query()->sole()->reference;

        ServiceVariant::query()->where('code', 'nezuko-regular-90')->update(['price_idr' => 400000]);
        Service::query()->where('slug', 'nezuko')->update(['pricing_verified_at' => null, 'pricing_verified_by' => null]);

        $this->actingAs($user)
            ->post('/bookings', $payload)
            ->assertStatus(303)
            ->assertRedirect('/bookings/'.$reference);

        $booking = Booking::query()->sole();
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(730000, $booking->total_price_idr);
        $this->assertSame(265000, $booking->base_price_idr);
        $this->assertSame('per_person', $booking->pricing_basis?->value);
    }

    /** @return array<string, mixed> */
    private function payload(): array
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
            'expected_total_price_idr' => 365000,
        ];
    }

    private function attestNezuko(PricingBasis $basis, int $minGuests, int $maxGuests): void
    {
        $service = Service::query()->where('slug', 'nezuko')->sole();
        $owner = User::factory()->owner()->create();

        app(AttestPackagePricing::class)($owner, $service, (int) $service->lock_version, $basis, $minGuests, $maxGuests);
    }
}
