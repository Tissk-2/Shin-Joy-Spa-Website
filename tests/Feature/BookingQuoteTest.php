<?php

namespace Tests\Feature;

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
        ], collect($quote->bookingAttributes())->except([
            'service_id',
            'service_variant_id',
            'service_extension_id',
        ])->all());
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

        $this->seed(ServiceCatalogSeeder::class);
        ServiceCategory::query()->where('slug', 'package')->update(['is_active' => false]);
        $this->assertQuoteFails(['service' => 'nezuko'], $user, 'service');

        $this->seed(ServiceCatalogSeeder::class);
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

        $this->seed(ServiceCatalogSeeder::class);
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
