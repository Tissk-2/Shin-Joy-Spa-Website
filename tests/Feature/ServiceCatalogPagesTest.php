<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ServiceCatalogPagesTest extends TestCase
{
    use RefreshDatabase;

    // Production break caught: broad model serialization or an unscoped home query would leak internal fields, inactive records, or nondeterministic catalog order.
    public function test_home_exposes_only_allow_listed_active_catalog_data_in_display_order(): void
    {
        $laterCategory = $this->category('later', 20);
        $firstCategory = $this->category('first', 10);
        $this->category('hidden', 1, false);

        $this->service($laterCategory, 'later-service', 2, 200000, 80);
        $this->service($firstCategory, 'first-service', 2, 150000, 95, ['first.jpg', '/img/already-rooted.jpg']);
        $this->service($firstCategory, 'second-service', 1, 175000, 90);
        $this->service($firstCategory, 'inactive-service', 0, 999999, 1, [], false);
        $hiddenCategory = ServiceCategory::query()->where('slug', 'hidden')->firstOrFail();
        $this->service($hiddenCategory, 'hidden-category-service', 0, 999999, 1);

        $response = $this->get('/');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('categories.0.slug', 'first')
            ->where('categories.1.slug', 'later')
            ->where('services.0.slug', 'second-service')
            ->where('services.1.slug', 'later-service')
            ->where('services.2.slug', 'first-service')
            ->has('categories', 2)
            ->has('services', 3));

        $props = $response->viewData('page')['props'];

        $this->assertSame(['slug', 'nameId', 'nameEn', 'image'], array_keys($props['categories'][0]));
        $this->assertSame([
            'slug', 'category', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn',
            'goodId', 'goodEn', 'duration', 'price', 'image', 'gallery', 'popularity',
        ], array_keys($props['services'][0]));
        $this->assertSame(['/img/first.jpg', '/img/already-rooted.jpg'], $props['services'][2]['gallery']);
        $this->assertIsInt($props['services'][2]['price']);
        $this->assertArrayNotHasKey('id', $props['services'][0]);
        $this->assertArrayNotHasKey('category_id', $props['services'][0]);
        $this->assertArrayNotHasKey('created_at', $props['services'][0]);
    }

    // Production break caught: resolving a service without its active category or skipping public scope filtering would make inactive catalog entries reachable.
    public function test_service_detail_returns_only_public_service_category_and_three_related_services(): void
    {
        $category = $this->category('massage', 1);
        $this->service($category, 'current-service', 1, 150000, 100, ['current.jpg']);
        $this->service($category, 'related-first', 2, 160000, 90);
        $this->service($category, 'related-second', 3, 170000, 80);
        $this->service($category, 'related-third', 4, 180000, 70);
        $this->service($category, 'related-fourth', 5, 190000, 60);
        $this->service($category, 'inactive-related', 0, 200000, 50, [], false);
        $inactiveCategory = $this->category('inactive-category', 2, false);
        $this->service($inactiveCategory, 'active-under-inactive-category', 1, 210000, 40);

        $response = $this->get('/services/current-service');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ServiceDetail')
            ->where('service.slug', 'current-service')
            ->where('service.category', 'massage')
            ->where('service.price', 150000)
            ->where('category.slug', 'massage')
            ->where('related.0.slug', 'related-first')
            ->where('related.2.slug', 'related-third')
            ->has('related', 3));

        $props = $response->viewData('page')['props'];
        $this->assertSame([
            'slug', 'category', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn',
            'goodId', 'goodEn', 'duration', 'price', 'image', 'gallery', 'popularity',
        ], array_keys($props['service']));
        $this->assertSame(['slug', 'nameId', 'nameEn', 'image'], array_keys($props['category']));
        $this->assertSame(['related-first', 'related-second', 'related-third'], array_column($props['related'], 'slug'));
        $this->assertIsInt($props['service']['price']);

        $this->get('/services/missing-service')->assertNotFound();
        $this->get('/services/active-under-inactive-category')->assertNotFound();
        $this->get('/services/inactive-related')->assertNotFound();
        $this->get('/services/not_valid')->assertNotFound();
    }

    // Production break caught: accepting a query value independently of the public service collection would preselect inactive or nonexistent services on booking.
    public function test_booking_exposes_only_public_services_and_only_preselects_a_visible_service(): void
    {
        $category = $this->category('massage', 1);
        $this->service($category, 'bookable-service', 2, 150000, 90);
        $this->service($category, 'second-bookable-service', 1, 175000, 80);
        $this->service($category, 'inactive-service', 3, 200000, 70, [], false);
        $inactiveCategory = $this->category('inactive-category', 2, false);
        $this->service($inactiveCategory, 'hidden-service', 1, 220000, 60);

        $response = $this->get('/booking?service=bookable-service');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Booking')
            ->where('selectedService', 'bookable-service')
            ->where('services.0.slug', 'second-bookable-service')
            ->where('services.1.slug', 'bookable-service')
            ->has('services', 2));

        $props = $response->viewData('page')['props'];
        $this->assertSame([
            'slug', 'category', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn',
            'goodId', 'goodEn', 'duration', 'price', 'image', 'gallery', 'popularity',
        ], array_keys($props['services'][0]));
        $this->assertIsInt($props['services'][0]['price']);

        $this->get('/booking?service=inactive-service')
            ->assertInertia(fn (Assert $page) => $page->where('selectedService', ''));
        $this->get('/booking?service=hidden-service')
            ->assertInertia(fn (Assert $page) => $page->where('selectedService', ''));
        $this->get('/booking?service=not_valid')
            ->assertInertia(fn (Assert $page) => $page->where('selectedService', ''));
    }

    private function category(string $slug, int $displayOrder, bool $isActive = true): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'slug' => $slug,
            'name_id' => "Kategori {$slug}",
            'name_en' => "Category {$slug}",
            'image_path' => "/img/{$slug}.jpg",
            'is_active' => $isActive,
            'display_order' => $displayOrder,
        ]);
    }

    /** @param list<string> $gallery */
    private function service(
        ServiceCategory $category,
        string $slug,
        int $displayOrder,
        int $price,
        int $popularity,
        array $gallery = [],
        bool $isActive = true,
    ): Service {
        return Service::query()->create([
            'category_id' => $category->id,
            'slug' => $slug,
            'name_id' => "Layanan {$slug}",
            'name_en' => "Service {$slug}",
            'description_id' => "Deskripsi {$slug}",
            'description_en' => "Description {$slug}",
            'long_description_id' => "Deskripsi panjang {$slug}",
            'long_description_en' => "Long description {$slug}",
            'good_for_id' => "Untuk {$slug}",
            'good_for_en' => "For {$slug}",
            'duration_minutes' => 60,
            'price_idr' => $price,
            'image_path' => "/img/{$slug}.jpg",
            'gallery_paths' => $gallery,
            'popularity' => $popularity,
            'is_active' => $isActive,
            'display_order' => $displayOrder,
        ]);
    }
}
