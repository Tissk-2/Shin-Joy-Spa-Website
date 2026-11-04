<?php

namespace Tests\Feature;

use App\Actions\Catalog\AttestPackagePricing;
use App\Enums\PricingBasis;
use App\Enums\TreatmentMode;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\BookingQuoteResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingQuoteTest extends TestCase
{
    use RefreshDatabase;

    // Production break caught: client totals or mutable inputs must not decide the persisted booking snapshot.
    public function test_valid_nezuko_regular_choice_resolves_authoritative_totals_without_mutating_input(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $user = User::factory()->create([
            'email' => 'AYU@Example.TEST',
        ]);
        $input = $this->validPayload([
            'name' => '  Ayu   Lestari  ',
            'phone' => ' +62 812-3456-7890 ',
            'guests' => '2',
        ]);
        $original = $input;

        $normalized = StoreBookingRequest::validatedBookingDataFrom($input);
        $quote = app(BookingQuoteResolver::class)->resolve($user, $normalized);

        $this->assertSame($original, $input);
        $this->assertSame([
            'service_id',
            'service_variant_id',
            'service_extension_id',
            'customer_name',
            'customer_email',
            'customer_phone',
            'appointment_date',
            'appointment_time',
            'appointment_timezone',
            'guests',
            'notes',
            'package_slug',
            'package_name_id',
            'package_name_en',
            'package_is_vip',
            'treatment_mode',
            'base_choice_code',
            'base_duration_minutes',
            'base_price_idr',
            'extension_choice_code',
            'extension_duration_minutes',
            'extension_price_idr',
            'total_duration_minutes',
            'total_price_idr',
            'pricing_basis',
            'min_guests',
            'max_guests',
        ], array_keys($quote->bookingAttributes()));
        $this->assertSame([
            'customer_name' => 'Ayu Lestari',
            'customer_email' => 'ayu@example.test',
            'customer_phone' => '+6281234567890',
            'appointment_date' => '2026-09-06',
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
            'pricing_basis' => null,
            'min_guests' => null,
            'max_guests' => null,
        ], collect($quote->bookingAttributes())->except([
            'service_id',
            'service_variant_id',
            'service_extension_id',
        ])->all());
    }

    // Production break caught: a verified per-person package must multiply the whole base+extension unit by guests.
    public function test_verified_per_person_quote_multiplies_the_unit_and_snapshots_coverage(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $service = Service::query()->where('slug', 'nezuko')->sole();
        $this->attest($service, PricingBasis::PerPerson, 1, 4);
        $user = User::factory()->create();

        $quote = app(BookingQuoteResolver::class)->resolve($user, StoreBookingRequest::validatedBookingDataFrom(
            $this->validPayload(['guests' => 2]),
        ));
        $attributes = $quote->bookingAttributes();

        $this->assertSame(680000, $attributes['total_price_idr']);
        $this->assertSame(340000, $attributes['base_price_idr'] + $attributes['extension_price_idr']);
        $this->assertSame(150, $attributes['total_duration_minutes']);
        $this->assertSame('per_person', $attributes['pricing_basis']);
        $this->assertSame(1, $attributes['min_guests']);
        $this->assertSame(4, $attributes['max_guests']);
    }

    // Production break caught: a verified per-booking package must keep one amount and enforce its guest coverage.
    public function test_verified_per_booking_quote_keeps_one_total_and_enforces_coverage(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $service = Service::query()->where('slug', 'nezuko')->sole();
        $this->attest($service, PricingBasis::PerBooking, 2, 4);
        $user = User::factory()->create();

        $quote = app(BookingQuoteResolver::class)->resolve($user, StoreBookingRequest::validatedBookingDataFrom(
            $this->validPayload(['guests' => 2]),
        ));
        $attributes = $quote->bookingAttributes();

        $this->assertSame(340000, $attributes['total_price_idr']);
        $this->assertSame('per_booking', $attributes['pricing_basis']);
        $this->assertSame(2, $attributes['min_guests']);
        $this->assertSame(4, $attributes['max_guests']);

        $this->assertQuoteFails(['guests' => 1], $user, 'guests');
        $this->assertQuoteFails(['guests' => 5], $user, 'guests');
    }

    // Production break caught: scheduling activation must not quote packages whose pricing was never attested.
    public function test_unverified_pricing_is_rejected_when_scheduling_is_enabled_but_stays_legacy_off(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $user = User::factory()->create();
        $data = StoreBookingRequest::validatedBookingDataFrom($this->validPayload(['guests' => 3]));

        config(['booking.scheduling_enabled' => false]);
        $this->assertSame(340000, app(BookingQuoteResolver::class)->resolve($user, $data)->bookingAttributes()['total_price_idr']);

        config(['booking.scheduling_enabled' => true]);
        $this->assertQuoteFails(['guests' => 3], $user, 'service');
    }

    // Production break caught: the contact resolver must use the same trusted catalog calculation without a session user.
    public function test_resolve_for_contact_uses_the_supplied_email_and_trusted_pricing(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $service = Service::query()->where('slug', 'nezuko')->sole();
        $this->attest($service, PricingBasis::PerPerson, 1, 4);

        $quote = app(BookingQuoteResolver::class)->resolveForContact(
            StoreBookingRequest::validatedBookingDataFrom($this->validPayload(['guests' => 2])),
            'MANUAL.GUEST@Example.TEST',
        );
        $attributes = $quote->bookingAttributes();

        $this->assertSame('manual.guest@example.test', $attributes['customer_email']);
        $this->assertSame(680000, $attributes['total_price_idr']);
        $this->assertSame('per_person', $attributes['pricing_basis']);
    }

    public function test_resolve_for_contact_preserves_an_absent_email_without_changing_trusted_pricing(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $service = Service::query()->where('slug', 'nezuko')->sole();
        $this->attest($service, PricingBasis::PerPerson, 1, 4);
        $data = StoreBookingRequest::validatedBookingDataFrom($this->validPayload(['guests' => 2]));
        $original = $data;

        $attributes = app(BookingQuoteResolver::class)->resolveForContact($data, null)->bookingAttributes();

        $this->assertArrayHasKey('customer_email', $attributes);
        $this->assertNull($attributes['customer_email']);
        $this->assertSame(680000, $attributes['total_price_idr']);
        $this->assertSame('per_person', $attributes['pricing_basis']);
        $this->assertSame($original, $data);
    }

    // Production break caught: hostile snapshot fields from the browser must never override catalog or account truth.
    public function test_client_supplied_identity_status_labels_durations_and_prices_do_not_affect_quote(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $user = User::factory()->create([
            'email' => 'truth@example.test',
        ]);

        $quote = app(BookingQuoteResolver::class)->resolve($user, StoreBookingRequest::validatedBookingDataFrom($this->validPayload([
            'user_id' => 999,
            'customer_email' => 'attacker@example.test',
            'email' => 'attacker@example.test',
            'reference' => 'SJ-HOSTILE',
            'status' => 'confirmed',
            'package_name_id' => 'Fake Label',
            'package_name_en' => 'Fake Label',
            'base_duration_minutes' => 5,
            'base_price_idr' => 1,
            'extension_duration_minutes' => 5,
            'extension_price_idr' => 1,
            'total_duration_minutes' => 10,
            'total_price_idr' => 2,
        ])));

        $attributes = $quote->bookingAttributes();

        $this->assertArrayNotHasKey('user_id', $attributes);
        $this->assertArrayNotHasKey('reference', $attributes);
        $this->assertArrayNotHasKey('status', $attributes);
        $this->assertSame('truth@example.test', $attributes['customer_email']);
        $this->assertSame('Nezuko', $attributes['package_name_id']);
        $this->assertSame(90, $attributes['base_duration_minutes']);
        $this->assertSame(265000, $attributes['base_price_idr']);
        $this->assertSame(60, $attributes['extension_duration_minutes']);
        $this->assertSame(75000, $attributes['extension_price_idr']);
        $this->assertSame(150, $attributes['total_duration_minutes']);
        $this->assertSame(340000, $attributes['total_price_idr']);
    }

    // Production break caught: stale, private, inactive, or incompatible catalog choices must fail as validation errors.
    public function test_resolver_rejects_inactive_non_public_and_incompatible_catalog_choices(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $user = User::factory()->create();

        Service::query()->where('slug', 'nezuko')->update(['is_active' => false]);
        $this->assertQuoteFails(['service' => 'nezuko'], $user, 'service');

        Service::query()->where('slug', 'nezuko')->update(['is_active' => true]);
        ServiceCategory::query()->where('slug', 'package')->update(['is_active' => false]);
        $this->assertQuoteFails(['service' => 'nezuko'], $user, 'service');

        ServiceCategory::query()->where('slug', 'package')->update(['is_active' => true]);
        $privateCategory = ServiceCategory::query()->create([
            'slug' => 'private',
            'name_id' => 'Private',
            'name_en' => 'Private',
            'image_path' => '/img/about.jpg',
            'is_active' => true,
            'display_order' => 99,
        ]);
        $privateService = Service::query()->create([
            'category_id' => $privateCategory->id,
            'slug' => 'private-treatment',
            'name_id' => 'Private Treatment',
            'name_en' => 'Private Treatment',
            'description_id' => 'Private',
            'description_en' => 'Private',
            'long_description_id' => 'Private',
            'long_description_en' => 'Private',
            'good_for_id' => null,
            'good_for_en' => null,
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => '/img/about.jpg',
            'gallery_paths' => ['about.jpg'],
            'popularity' => 1,
            'is_vip' => false,
            'is_active' => true,
            'display_order' => 99,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $privateService->id,
            'code' => 'private-treatment-regular-90',
            'treatment_mode' => TreatmentMode::Regular,
            'duration_minutes' => 90,
            'price_idr' => 265000,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $this->assertQuoteFails(['service' => 'private-treatment'], $user, 'service');

        ServiceVariant::query()->where('code', 'nezuko-regular-90')->update(['is_active' => false]);
        $this->assertQuoteFails([], $user, 'duration');

        ServiceVariant::query()->where('code', 'nezuko-regular-90')->update(['is_active' => true]);
        ServiceExtension::query()->where('code', 'nezuko-regular-extra-60')->update(['is_active' => false]);
        $this->assertQuoteFails([], $user, 'extension');

        ServiceExtension::query()->where('code', 'nezuko-regular-extra-60')->update(['is_active' => true]);
        $this->assertQuoteFails([
            'extension' => 'nezuko-double-extra-30',
        ], $user, 'extension');
    }

    // Production break caught: an archived child choice must not remain quoteable through its still-published parent package.
    public function test_resolver_rejects_archived_child_choices_while_the_parent_remains_published(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $user = User::factory()->create();

        ServiceVariant::query()->where('code', 'nezuko-regular-90')->update([
            'is_active' => false,
            'published_at' => now(),
        ]);
        $this->assertQuoteFails([], $user, 'duration');

        ServiceVariant::query()->where('code', 'nezuko-regular-90')->update(['is_active' => true]);
        ServiceExtension::query()->where('code', 'nezuko-regular-extra-60')->update([
            'is_active' => false,
            'published_at' => now(),
        ]);
        $this->assertQuoteFails([], $user, 'extension');
    }

    // Production break caught: array-shaped and malformed booking fields must stop before quote resolution.
    public function test_store_request_rejects_malformed_or_array_shaped_boundary_fields(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05 08:00:00', 'Asia/Jakarta'));

        try {
            $cases = [
                'array service' => ['service' => ['nezuko'], 'field' => 'service'],
                'array mode' => ['mode' => ['regular'], 'field' => 'mode'],
                'array duration' => ['duration' => [90], 'field' => 'duration'],
                'array extension' => ['extension' => ['nezuko-regular-extra-60'], 'field' => 'extension'],
                'short phone' => ['phone' => '123', 'field' => 'phone'],
                'parenthesized phone' => ['phone' => '+62 (812) 3456 7890', 'field' => 'phone'],
                'array phone' => ['phone' => ['+62 812'], 'field' => 'phone'],
                'empty name' => ['name' => '   ', 'field' => 'name'],
                'past date in Jakarta' => ['date' => '2026-09-04', 'field' => 'date'],
                'malformed date' => ['date' => '09/06/2026', 'field' => 'date'],
                'malformed time' => ['time' => '9:00', 'field' => 'time'],
                'off-slot time' => ['time' => '20:30', 'field' => 'time'],
                'zero guests' => ['guests' => 0, 'field' => 'guests'],
                'too many guests' => ['guests' => 5, 'field' => 'guests'],
                'long notes' => ['notes' => str_repeat('a', 1001), 'field' => 'notes'],
            ];

            foreach ($cases as $case) {
                $payload = $this->validPayload([$case['field'] => $case[$case['field']]]);

                $validator = Validator::make($payload, (new StoreBookingRequest)->rules());

                $this->assertTrue($validator->fails(), "Expected [{$case['field']}] to fail.");
                $this->assertArrayHasKey($case['field'], $validator->errors()->messages());
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    // Production break caught: an optional WhatsApp number normalizes to an empty phone at the boundary.
    public function test_store_request_normalizes_a_missing_or_blank_phone_to_an_empty_string(): void
    {
        $withoutPhone = StoreBookingRequest::validatedBookingDataFrom(
            array_diff_key($this->validPayload(), ['phone' => null]),
        );
        $withNullPhone = StoreBookingRequest::validatedBookingDataFrom($this->validPayload(['phone' => null]));
        $withBlankPhone = StoreBookingRequest::validatedBookingDataFrom($this->validPayload(['phone' => '   ']));

        $this->assertSame('', $withoutPhone['phone']);
        $this->assertSame('', $withNullPhone['phone']);
        $this->assertSame('', $withBlankPhone['phone']);
    }

    public function test_store_request_normalizes_empty_extension_and_optional_notes_consistently(): void
    {
        $withoutExtension = StoreBookingRequest::validatedBookingDataFrom($this->validPayload([
            'extension' => '',
            'notes' => '   ',
        ]));
        $withNullExtension = StoreBookingRequest::validatedBookingDataFrom($this->validPayload([
            'extension' => null,
            'notes' => null,
        ]));

        $this->assertNull($withoutExtension['extension']);
        $this->assertNull($withoutExtension['notes']);
        $this->assertNull($withNullExtension['extension']);
        $this->assertNull($withNullExtension['notes']);
    }

    private function attest(Service $service, PricingBasis $basis, int $minGuests, int $maxGuests): void
    {
        $owner = User::factory()->owner()->create();

        app(AttestPackagePricing::class)($owner, $service, (int) $service->lock_version, $basis, $minGuests, $maxGuests);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertQuoteFails(array $overrides, User $user, string $field): void
    {
        $exception = null;

        try {
            app(BookingQuoteResolver::class)->resolve($user, StoreBookingRequest::validatedBookingDataFrom($this->validPayload($overrides)));
        } catch (ValidationException $validationException) {
            $exception = $validationException;
        }

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertArrayHasKey($field, $exception->errors());
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
            'date' => '2026-09-06',
            'time' => '10:30',
            'guests' => 1,
            'notes' => 'Focus on shoulders.',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            ...$overrides,
        ];
    }
}
