<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // Production break caught: serializing category/scalar services or inactive choices would expose an obsolete or unsafe menu contract.
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
            ->where('packages.0.slug', 'nezuko')
            ->where('packages.1.slug', 'nezuko-vip')
            ->where('packages.0.variants.1.price', 265000)
            ->where('packages.1.extensions.1.duration', 30)
            ->has('packages', 2));

        $props = $response->viewData('page')['props'];
        $package = $props['packages'][0];

        $this->assertSame([
            'slug', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn',
            'benefitId', 'benefitEn', 'image', 'gallery', 'isVip', 'variants', 'extensions',
        ], array_keys($package));
        $this->assertSame(['code', 'mode', 'duration', 'price'], array_keys($package['variants'][0]));
        $this->assertSame(['code', 'mode', 'duration', 'price'], array_keys($package['extensions'][0]));
        $this->assertSame(['/img/massage.jpg', '/img/oil.jpg', '/img/about.jpg'], $package['gallery']);
        $this->assertSame(['nezuko-regular-60', 'nezuko-regular-90', 'nezuko-regular-120', 'nezuko-double-60'], array_column($package['variants'], 'code'));
        $this->assertSame(['nezuko-regular-extra-60', 'nezuko-double-extra-30'], array_column($package['extensions'], 'code'));
        $this->assertIsInt($package['variants'][0]['duration']);
        $this->assertIsInt($package['variants'][0]['price']);
        $this->assertArrayNotHasKey('id', $package);
        $this->assertArrayNotHasKey('created_at', $package);
        $this->assertArrayNotHasKey('service_id', $package['variants'][0]);
        $this->assertArrayNotHasKey('updated_at', $package['extensions'][0]);
    }

    // Production break caught: detail pages must not leak a related collection or expose inactive choices through an otherwise public package.
    public function test_service_detail_exposes_the_package_and_only_the_opposite_package_link(): void
    {
        $nezuko = Service::query()->where('slug', 'nezuko')->firstOrFail();
        ServiceVariant::query()->where('service_id', $nezuko->id)->where('code', 'nezuko-regular-90')->update(['is_active' => false]);

        $response = $this->get('/services/nezuko');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ServiceDetail')
            ->where('service.slug', 'nezuko')
            ->where('service.variants.0.code', 'nezuko-regular-60')
            ->where('comparison.slug', 'nezuko-vip')
            ->has('service.variants', 3)
            ->missing('category')
            ->missing('related'));

        $props = $response->viewData('page')['props'];
        $this->assertSame(['slug', 'nameId', 'nameEn', 'isVip'], array_keys($props['comparison']));
        $this->assertSame(['nezuko-regular-60', 'nezuko-regular-120', 'nezuko-double-60'], array_column($props['service']['variants'], 'code'));
        $this->assertArrayNotHasKey('id', $props['service']);

        $this->get('/services/missing-service')->assertNotFound();
        $this->get('/services/not_valid')->assertNotFound();
    }
}
