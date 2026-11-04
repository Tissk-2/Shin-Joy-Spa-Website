<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ServiceCatalogPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ServiceCatalogSeeder::class);
    }

    // Production break caught: restoring the transitional flat prop would let Home drift away from the grouped public catalog contract.
    public function test_home_exposes_only_active_package_choices_in_the_exact_public_shape(): void
    {
        $nezuko = Service::query()->where('slug', 'nezuko')->firstOrFail();
        ServiceVariant::query()->create([
            'service_id' => $nezuko->id, 'code' => 'nezuko-inactive-180', 'treatment_mode' => 'regular',
            'duration_minutes' => 180, 'price_idr' => 999999, 'is_active' => false, 'display_order' => 99,
        ]);
        ServiceExtension::query()->create([
            'service_id' => $nezuko->id, 'code' => 'nezuko-inactive-extra-90', 'treatment_mode' => 'regular',
            'duration_minutes' => 90, 'price_idr' => 999999, 'is_active' => false, 'display_order' => 99,
        ]);
        $hidden = Service::query()->create([
            'category_id' => $nezuko->category_id, 'slug' => 'without-active-variant',
            'name_id' => 'Tersembunyi', 'name_en' => 'Hidden', 'description_id' => 'Tersembunyi', 'description_en' => 'Hidden',
            'long_description_id' => 'Tersembunyi', 'long_description_en' => 'Hidden', 'image_path' => '/img/massage.jpg',
            'gallery_paths' => ['massage.jpg'], 'popularity' => 0, 'is_active' => true, 'display_order' => 0,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $hidden->id, 'code' => 'without-active-variant-60', 'treatment_mode' => 'regular',
            'duration_minutes' => 60, 'price_idr' => 199000, 'is_active' => false, 'display_order' => 1,
        ]);

        $response = $this->get('/');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('categories.0.slug', 'package')
            ->has('categories', 1)
            ->where('categories.0.packages.0.slug', 'nezuko')
            ->where('categories.0.packages.1.slug', 'nezuko-vip')
            ->where('categories.0.packages.0.variants.1.price', 265000)
            ->where('categories.0.packages.1.extensions.1.duration', 30)
            ->missing('packages'));

        $props = $response->viewData('page')['props'];
        $package = $props['categories'][0]['packages'][0];

        $this->assertSame([
            'slug', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn',
            'benefitId', 'benefitEn', 'image', 'gallery', 'isVip',
            'pricingBasis', 'minGuests', 'maxGuests', 'pricingVerified', 'variants', 'extensions',
        ], array_keys($package));
        $this->assertSame(['code', 'mode', 'duration', 'price'], array_keys($package['variants'][0]));
        $this->assertSame(['code', 'mode', 'duration', 'price'], array_keys($package['extensions'][0]));
        $this->assertSame([
            '/img/massage.jpg',
            '/img/oil.jpg',
            '/img/about.jpg',
        ], $package['gallery']);
        $this->assertSame(['nezuko-regular-60', 'nezuko-regular-90', 'nezuko-regular-120', 'nezuko-double-60'], array_column($package['variants'], 'code'));
        $this->assertSame(['nezuko-regular-extra-60', 'nezuko-double-extra-30'], array_column($package['extensions'], 'code'));
        $this->assertIsInt($package['variants'][0]['duration']);
        $this->assertIsInt($package['variants'][0]['price']);
        $this->assertArrayNotHasKey('id', $package);
        $this->assertArrayNotHasKey('created_at', $package);
        $this->assertArrayNotHasKey('service_id', $package['variants'][0]);
        $this->assertArrayNotHasKey('updated_at', $package['extensions'][0]);
    }

    // Production break caught: returning a redirect, flat catalog, draft record, unstable ordering, or internal columns violates the public menu boundary.
    public function test_services_index_exposes_grouped_published_catalog_in_deterministic_safe_shape(): void
    {
        ServiceCategory::query()->where('slug', 'package')->update(['display_order' => 2]);
        $massage = $this->createCategory('massage', 1, imagePath: 'catalog/categories/massage.jpg');
        $this->createService($massage, 'balinese-60', 1, imagePath: 'catalog/services/balinese.jpg');
        $this->createService($massage, 'draft-treatment', 0, published: false);
        $draftCategory = $this->createCategory('draft-category', 0, published: false);
        $this->createService($draftCategory, 'published-under-draft', 1);

        $response = $this->get(route('services.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Services')
            ->has('categories', 2)
            ->where('categories.0.slug', 'massage')
            ->where('categories.0.packages.0.slug', 'balinese-60')
            ->where('categories.1.slug', 'package')
            ->where('categories.1.packages.0.slug', 'nezuko')
            ->missing('categories.0.id')
            ->missing('categories.0.published_at')
            ->missing('categories.0.packages.0.id')
            ->missing('categories.0.packages.0.published_at'));

        $categories = $response->inertiaProps('categories');
        $this->assertSame(['slug', 'nameId', 'nameEn', 'image', 'packages'], array_keys($categories[0]));
        $this->assertSame([
            'slug', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn',
            'benefitId', 'benefitEn', 'image', 'gallery', 'isVip',
            'pricingBasis', 'minGuests', 'maxGuests', 'pricingVerified', 'variants', 'extensions',
        ], array_keys($categories[0]['packages'][0]));
        $this->assertSame('http://localhost/storage/catalog/categories/massage.jpg', $categories[0]['image']);
        $this->assertSame('http://localhost/storage/catalog/services/balinese.jpg', $categories[0]['packages'][0]['image']);
        $this->assertSame('http://localhost/storage/catalog/services/balinese-detail.jpg', $categories[0]['packages'][0]['gallery'][0]);
        $this->assertSame(['massage', 'package'], array_column($categories, 'slug'));
        $this->assertSame(['balinese-60'], array_column($categories[0]['packages'], 'slug'));

    }

    // Production break caught: lazy-loading services or choices would make public menu query counts grow with catalog size.
    public function test_services_index_query_count_does_not_grow_with_more_packages(): void
    {
        $baseline = $this->requestQueryCount(route('services.index'));
        $package = ServiceCategory::query()->where('slug', 'package')->firstOrFail();

        foreach (range(3, 7) as $order) {
            $this->createService($package, "package-{$order}", $order);
        }

        $expanded = $this->requestQueryCount(route('services.index'));

        $this->assertSame($baseline, $expanded);
    }

    // Production break caught: booking must resolve only active, internally consistent server-side choices after account verification.
    public function test_verified_customer_receives_a_normalized_configured_booking_selection(): void
    {
        $verified = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($verified)
            ->get('/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Booking')
                ->where('selection.service', 'nezuko')
                ->where('selection.mode', 'regular')
                ->where('selection.duration', 90)
                ->where('selection.extension', 'nezuko-regular-extra-60'));
    }

    // Production break caught: a mode-specific extension must never be accepted for a different treatment mode.
    public function test_booking_selection_rejects_a_regular_extension_for_double_therapist_treatment(): void
    {
        $verified = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($verified)
            ->get('/booking?service=nezuko&mode=double_therapist&duration=60&extension=nezuko-regular-extra-60')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection.service', 'nezuko')
                ->where('selection.mode', 'double_therapist')
                ->where('selection.duration', 60)
                ->where('selection.extension', ''));
    }

    // Production break caught: stale inactive catalog codes and non-scalar duration input must clear rather than being trusted.
    public function test_booking_selection_clears_inactive_codes_and_array_duration_input(): void
    {
        $verified = User::factory()->create(['email_verified_at' => now()]);
        ServiceExtension::query()->where('code', 'nezuko-regular-extra-60')->update(['is_active' => false]);

        $this->actingAs($verified)
            ->get('/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection.service', 'nezuko')
                ->where('selection.mode', 'regular')
                ->where('selection.duration', 90)
                ->where('selection.extension', ''));

        $this->actingAs($verified)
            ->get('/booking?service=nezuko&mode=regular&duration[]=90&extension=nezuko-regular-extra-60')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection.service', 'nezuko')
                ->where('selection.mode', 'regular')
                ->where('selection.duration', null)
                ->where('selection.extension', ''));
    }

    // Production break caught: array-shaped query values must not be coerced into catalog selections.
    public function test_booking_selection_rejects_array_service_mode_and_extension_inputs(): void
    {
        $verified = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($verified)
            ->get('/booking?service[]=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection.service', '')
                ->where('selection.mode', '')
                ->where('selection.duration', null)
                ->where('selection.extension', ''));

        $this->actingAs($verified)
            ->get('/booking?service=nezuko&mode[]=regular&duration=90&extension=nezuko-regular-extra-60')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection.service', 'nezuko')
                ->where('selection.mode', '')
                ->where('selection.duration', null)
                ->where('selection.extension', ''));

        $this->actingAs($verified)
            ->get('/booking?service=nezuko&mode=regular&duration=90&extension[]=nezuko-regular-extra-60')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection.service', 'nezuko')
                ->where('selection.mode', 'regular')
                ->where('selection.duration', 90)
                ->where('selection.extension', ''));
    }

    // Production break caught: deactivated packages and duration variants must clear progressively without trusting stale URLs.
    public function test_booking_selection_clears_inactive_service_and_variant(): void
    {
        $verified = User::factory()->create(['email_verified_at' => now()]);

        ServiceVariant::query()->where('code', 'nezuko-regular-90')->update(['is_active' => false]);

        $this->actingAs($verified)
            ->get('/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection.service', 'nezuko')
                ->where('selection.mode', 'regular')
                ->where('selection.duration', null)
                ->where('selection.extension', ''));

        Service::query()->where('slug', 'nezuko')->update(['is_active' => false]);

        $this->actingAs($verified)
            ->get('/booking?service=nezuko&mode=regular&duration=60&extension=nezuko-regular-extra-60')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selection.service', '')
                ->where('selection.mode', '')
                ->where('selection.duration', null)
                ->where('selection.extension', ''));
    }

    private function createCategory(
        string $slug,
        int $displayOrder,
        bool $published = true,
        string $imagePath = '/img/category.jpg',
    ): ServiceCategory {
        return ServiceCategory::query()->create([
            'slug' => $slug,
            'name_id' => ucfirst(str_replace('-', ' ', $slug)),
            'name_en' => ucfirst(str_replace('-', ' ', $slug)),
            'image_path' => $imagePath,
            'is_active' => true,
            'published_at' => $published ? now() : null,
            'display_order' => $displayOrder,
        ]);
    }

    private function createService(
        ServiceCategory $category,
        string $slug,
        int $displayOrder,
        bool $published = true,
        string $imagePath = '/img/service.jpg',
    ): Service {
        $service = Service::query()->create([
            'category_id' => $category->id,
            'slug' => $slug,
            'name_id' => ucfirst(str_replace('-', ' ', $slug)),
            'name_en' => ucfirst(str_replace('-', ' ', $slug)),
            'description_id' => 'Deskripsi',
            'description_en' => 'Description',
            'long_description_id' => 'Deskripsi panjang',
            'long_description_en' => 'Long description',
            'good_for_id' => null,
            'good_for_en' => null,
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => $imagePath,
            'gallery_paths' => ['catalog/services/balinese-detail.jpg'],
            'popularity' => 1,
            'is_vip' => false,
            'is_active' => true,
            'published_at' => $published ? now() : null,
            'display_order' => $displayOrder,
        ]);

        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => "{$slug}-regular-60",
            'treatment_mode' => 'regular',
            'duration_minutes' => 60,
            'price_idr' => 199000,
            'is_active' => true,
            'published_at' => now(),
            'display_order' => 1,
        ]);

        return $service;
    }

    private function requestQueryCount(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }
}
