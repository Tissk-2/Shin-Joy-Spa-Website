<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\TreatmentMode;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookingSubmissionTest extends TestCase
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

    // Production break caught: unauthenticated visitors must not create booking records.
    public function test_guest_cannot_submit_a_booking(): void
    {
        $this->post('/bookings', $this->validPayload())
            ->assertRedirect(route('login', absolute: false));

        $this->assertDatabaseCount('bookings', 0);
    }

    // Production break caught: signed-in but unverified customers must be stopped before persistence.
    public function test_unverified_customer_cannot_submit_a_booking(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/bookings', $this->validPayload())
            ->assertRedirect(route('verification.notice', absolute: false));

        $this->assertDatabaseCount('bookings', 0);
    }

    // Production break caught: the booking form must receive a fresh server-generated idempotency key.
    public function test_booking_form_receives_a_server_generated_submission_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/booking?service=nezuko&mode=regular&duration=90')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Booking')
                ->where('selection.service', 'nezuko')
                ->where('submissionKey', fn (string $key): bool => Str::isUuid($key)));
    }

    // Production break caught: browser-supplied identity, status, reference, and price fields must not control persisted bookings.
    public function test_verified_customer_creates_a_pending_booking_with_authoritative_snapshot_and_303_redirect(): void
    {
        $user = User::factory()->create([
            'email' => 'AYU@Example.TEST',
        ]);

        $response = $this->actingAs($user)->post('/bookings', $this->validPayload([
            'name' => '  Ayu   Lestari  ',
            'phone' => ' +62 812-3456-7890 ',
            'guests' => '2',
            'user_id' => 999,
            'customer_email' => 'attacker@example.test',
            'email' => 'attacker@example.test',
            'reference' => 'SJ-HOSTILE',
            'status' => BookingStatus::Confirmed->value,
            'base_price_idr' => 1,
            'extension_price_idr' => 1,
            'total_price_idr' => 2,
        ]));

        $booking = Booking::query()->firstOrFail();

        $response
            ->assertStatus(303)
            ->assertRedirect('/bookings/'.$booking->reference);

        $this->assertMatchesRegularExpression('/\ASJ-[0-9A-HJKMNP-TV-Z]{26}\z/', $booking->reference);
        $this->assertSame([
            'user_id' => $user->id,
            'status' => BookingStatus::Pending->value,
            'customer_name' => 'Ayu Lestari',
            'customer_email' => 'ayu@example.test',
            'customer_phone' => '+6281234567890',
            'appointment_date' => '2026-09-07',
            'appointment_time' => '10:30',
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 2,
            'notes' => 'Focus on shoulders.',
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko',
            'package_name_en' => 'Nezuko',
            'package_is_vip' => false,
            'treatment_mode' => TreatmentMode::Regular->value,
            'base_choice_code' => 'nezuko-regular-90',
            'base_duration_minutes' => 90,
            'base_price_idr' => 265000,
            'extension_choice_code' => 'nezuko-regular-extra-60',
            'extension_duration_minutes' => 60,
            'extension_price_idr' => 75000,
            'total_duration_minutes' => 150,
            'total_price_idr' => 340000,
        ], $this->bookingSnapshot($booking));
        $this->assertSame($this->serviceId('nezuko'), $booking->service_id);
        $this->assertSame($this->variantId('nezuko-regular-90'), $booking->service_variant_id);
        $this->assertSame($this->extensionId('nezuko-regular-extra-60'), $booking->service_extension_id);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $booking->request_fingerprint);
    }

    // Production break caught: the persisted fingerprint must be the canonical request payload, excluding submission_key.
    public function test_request_fingerprint_hashes_canonical_normalized_payload_without_submission_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/bookings', $this->validPayload())->assertStatus(303);

        $this->assertSame(
            '02a1a1549c8b302c715add72d081b189cd32f14b090f5fed21009f27e9855428',
            Booking::query()->sole()->request_fingerprint,
        );
    }

    // Production break caught: retrying the same key and same payload must return the original row, not create a duplicate.
    public function test_same_key_same_payload_replay_redirects_to_the_existing_booking(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();

        $first = $this->actingAs($user)->post('/bookings', $payload);
        $reference = Booking::query()->sole()->reference;

        $second = $this->actingAs($user)->post('/bookings', $payload);

        $first->assertStatus(303)->assertRedirect('/bookings/'.$reference);
        $second->assertStatus(303)->assertRedirect('/bookings/'.$reference);
        $this->assertDatabaseCount('bookings', 1);
    }

    // Production break caught: idempotent replay must use the original normalized request, not mutable catalog prices.
    public function test_same_key_replay_survives_later_catalog_price_changes(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();

        $this->actingAs($user)->post('/bookings', $payload)->assertStatus(303);
        $reference = Booking::query()->sole()->reference;

        ServiceVariant::query()
            ->where('code', 'nezuko-regular-90')
            ->update(['price_idr' => 999999]);

        $this->actingAs($user)
            ->post('/bookings', $payload)
            ->assertStatus(303)
            ->assertRedirect('/bookings/'.$reference);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(265000, Booking::query()->sole()->base_price_idr);
    }

    // Production break caught: reusing a key for a changed request must not overwrite or create a second booking.
    public function test_same_key_different_payload_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();

        $this->actingAs($user)->post('/bookings', $payload)->assertStatus(303);

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', [
                ...$payload,
                'time' => '11:00',
            ])
            ->assertRedirect('/booking')
            ->assertSessionHasErrors('submission_key');

        $this->assertDatabaseCount('bookings', 1);
    }

    // Idempotency keys dedupe per customer only and must not disclose another customer's booking.
    public function test_different_customer_can_reuse_the_same_submission_key_without_retrieving_another_booking(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $payload = $this->validPayload();

        $this->actingAs($firstUser)->post('/bookings', $payload)->assertStatus(303);
        $firstReference = Booking::query()->where('user_id', $firstUser->id)->sole()->reference;

        $second = $this->actingAs($secondUser)->post('/bookings', $payload);
        $secondReference = Booking::query()->where('user_id', $secondUser->id)->sole()->reference;

        $second->assertStatus(303)->assertRedirect('/bookings/'.$secondReference);
        $this->assertNotSame($firstReference, $secondReference);
        $this->assertDatabaseCount('bookings', 2);
    }

    // Production break caught: an optional WhatsApp number must persist as an empty phone, never fail validation.
    public function test_booking_without_a_whatsapp_number_persists_an_empty_phone(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/bookings', $this->validPayload([
            'phone' => '',
        ]));

        $booking = Booking::query()->sole();

        $response->assertStatus(303)->assertRedirect('/bookings/'.$booking->reference);
        $this->assertSame('', $booking->customer_phone);
    }

    public function test_elapsed_time_today_is_rejected_but_a_future_time_today_is_accepted(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 15:15:00', 'Asia/Jakarta'));
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', $this->validPayload([
                'date' => '2026-09-06',
                'time' => '15:00',
            ]))
            ->assertRedirect('/booking')
            ->assertSessionHasErrors(['time' => __('validation.booking.future_time')]);

        $this->assertDatabaseCount('bookings', 0);

        $this->actingAs($user)
            ->post('/bookings', $this->validPayload([
                'date' => '2026-09-06',
                'time' => '15:30',
            ]))
            ->assertStatus(303);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_treatment_must_finish_by_2100_jakarta_time_including_any_extension(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', $this->validPayload([
                'time' => '20:00',
                'extension' => null,
            ]))
            ->assertRedirect('/booking')
            ->assertSessionHasErrors('time');

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', $this->validPayload([
                'time' => '19:00',
                'submission_key' => Str::uuid()->toString(),
            ]))
            ->assertRedirect('/booking')
            ->assertSessionHasErrors('time');

        $this->assertDatabaseCount('bookings', 0);

        $this->actingAs($user)
            ->post('/bookings', $this->validPayload([
                'time' => '19:30',
                'extension' => null,
                'expected_total_price_idr' => 265000,
                'submission_key' => Str::uuid()->toString(),
            ]))
            ->assertStatus(303);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_two_customers_can_request_the_same_slot_for_staff_confirmation(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->actingAs($firstUser)->post('/bookings', $this->validPayload())->assertStatus(303);

        $this->actingAs($secondUser)
            ->post('/bookings', [
                ...$this->validPayload(),
                'submission_key' => Str::uuid()->toString(),
            ])
            ->assertStatus(303);

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_overlapping_different_start_times_can_be_requested(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        // First: 10:00, 120 min → interval 10:00–12:00.
        $this->actingAs($firstUser)->post('/bookings', [
            ...$this->validPayload(),
            'time' => '10:00',
            'duration' => 120,
            'extension' => null,
            'expected_total_price_idr' => 315000,
        ])->assertStatus(303);

        // Second: 11:30, 90 min → interval 11:30–13:00, overlaps 11:30–12:00.
        $this->actingAs($secondUser)
            ->post('/bookings', [
                ...$this->validPayload(),
                'submission_key' => Str::uuid()->toString(),
                'time' => '11:30',
                'duration' => 90,
                'extension' => null,
                'expected_total_price_idr' => 265000,
            ])
            ->assertStatus(303);

        $this->assertDatabaseCount('bookings', 2);
    }

    // Production break caught: unique-key recovery must not read rows created inside the rolled-back failed transaction.
    public function test_unique_submission_key_exception_rethrows_when_no_committed_same_user_row_exists(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload([
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a99',
        ]);
        $insertedByRace = false;

        Booking::creating(function (Booking $booking) use (&$insertedByRace): void {
            if ($insertedByRace) {
                return;
            }

            $insertedByRace = true;

            DB::table('bookings')->insert([
                ...$booking->getAttributes(),
                'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL99',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $exception = null;

        try {
            $this->withoutExceptionHandling()
                ->actingAs($user)
                ->post('/bookings', $payload);
        } catch (QueryException $queryException) {
            $exception = $queryException;
        }

        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertDatabaseCount('bookings', 0);
    }

    // Production break caught: unique-key recovery must look after rollback, then return only a matching same-user row.
    public function test_unique_submission_key_exception_recovers_matching_row_after_rollback(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload([
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5b99',
        ]);
        $insertedByRace = false;

        Booking::creating(function (Booking $booking) use (&$insertedByRace): void {
            if ($insertedByRace) {
                return;
            }

            $insertedByRace = true;
            $attributes = $booking->getAttributes();

            DB::afterRollBack(fn () => DB::table('bookings')->insert([
                ...$attributes,
                'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKB99',
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            DB::table('bookings')->insert([
                ...$attributes,
                'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKA99',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($user)
            ->post('/bookings', $payload)
            ->assertStatus(303)
            ->assertRedirect('/bookings/SJ-01K4DR9M7JQ2ABCD3EFGHJKB99');

        $this->assertDatabaseCount('bookings', 1);
    }

    // Production break caught: the store route must use the booking-store limiter and return Laravel's standard 429.
    public function test_booking_store_route_is_rate_limited_per_authenticated_customer(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload(['submission_key' => 'not-a-uuid']);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->post('/bookings', $payload)
                ->assertSessionHasErrors('submission_key');
        }

        $this->actingAs($user)
            ->post('/bookings', $payload)
            ->assertStatus(429)
            ->assertSee('Too Many Requests');
    }

    // Production break caught: the shared IP bucket must throttle even when each authenticated user is below 6/minute.
    public function test_booking_store_route_is_rate_limited_per_ip_across_authenticated_customers(): void
    {
        $payload = $this->validPayload(['submission_key' => 'not-a-uuid']);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->actingAs(User::factory()->create())
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
                ->post('/bookings', $payload)
                ->assertSessionHasErrors('submission_key');
        }

        $this->actingAs(User::factory()->create())
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->post('/bookings', $payload)
            ->assertStatus(429)
            ->assertSee('Too Many Requests');
    }

    // Production break caught: malformed scalar and array submission keys must fail at the HTTP boundary.
    public function test_submission_key_must_be_a_scalar_uuid_at_the_http_boundary(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', $this->validPayload(['submission_key' => 'not-a-uuid']))
            ->assertRedirect('/booking')
            ->assertSessionHasErrors('submission_key');

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', $this->validPayload(['submission_key' => ['018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10']]))
            ->assertRedirect('/booking')
            ->assertSessionHasErrors('submission_key');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_new_booking_stores_the_lifecycle_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/bookings', $this->validPayload())->assertStatus(303);

        $booking = Booking::query()->sole();

        $this->assertSame('2026-09-07 03:30:00', $booking->getRawOriginal('starts_at'));
        $this->assertSame('2026-09-07 06:00:00', $booking->getRawOriginal('treatment_ends_at'));
        $this->assertSame('at_spa', $booking->payment_preference);
        $this->assertSame('website', $booking->source_channel);
        $this->assertNull($booking->created_by_staff_id);
        $this->assertSame(1, $booking->booking_version);
    }

    public function test_hostile_lifecycle_input_is_ignored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/bookings', $this->validPayload([
            'payment_preference' => 'online',
            'source_channel' => 'phone',
            'starts_at' => '2000-01-01 00:00:00',
            'created_by_staff_id' => 1,
        ]))->assertStatus(303);

        $booking = Booking::query()->sole();

        $this->assertSame('2026-09-07 03:30:00', $booking->getRawOriginal('starts_at'));
        $this->assertSame('2026-09-07 06:00:00', $booking->getRawOriginal('treatment_ends_at'));
        $this->assertSame('at_spa', $booking->payment_preference);
        $this->assertSame('website', $booking->source_channel);
        $this->assertNull($booking->created_by_staff_id);
        $this->assertSame(1, $booking->booking_version);
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
            'mode' => TreatmentMode::Regular->value,
            'duration' => 90,
            'extension' => 'nezuko-regular-extra-60',
            'date' => '2026-09-07',
            'time' => '10:30',
            'guests' => 1,
            'notes' => 'Focus on shoulders.',
            'expected_total_price_idr' => 340000,
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingSnapshot(Booking $booking): array
    {
        return [
            'user_id' => $booking->user_id,
            'status' => $booking->status->value,
            'customer_name' => $booking->customer_name,
            'customer_email' => $booking->customer_email,
            'customer_phone' => $booking->customer_phone,
            'appointment_date' => $booking->appointment_date->format('Y-m-d'),
            'appointment_time' => $booking->appointment_time,
            'appointment_timezone' => $booking->appointment_timezone,
            'guests' => $booking->guests,
            'notes' => $booking->notes,
            'package_slug' => $booking->package_slug,
            'package_name_id' => $booking->package_name_id,
            'package_name_en' => $booking->package_name_en,
            'package_is_vip' => $booking->package_is_vip,
            'treatment_mode' => $booking->treatment_mode,
            'base_choice_code' => $booking->base_choice_code,
            'base_duration_minutes' => $booking->base_duration_minutes,
            'base_price_idr' => $booking->base_price_idr,
            'extension_choice_code' => $booking->extension_choice_code,
            'extension_duration_minutes' => $booking->extension_duration_minutes,
            'extension_price_idr' => $booking->extension_price_idr,
            'total_duration_minutes' => $booking->total_duration_minutes,
            'total_price_idr' => $booking->total_price_idr,
        ];
    }

    private function serviceId(string $slug): int
    {
        return Service::query()->where('slug', $slug)->sole()->id;
    }

    private function variantId(string $code): int
    {
        return ServiceVariant::query()->where('code', $code)->sole()->id;
    }

    private function extensionId(string $code): int
    {
        return ServiceExtension::query()->where('code', $code)->sole()->id;
    }
}
