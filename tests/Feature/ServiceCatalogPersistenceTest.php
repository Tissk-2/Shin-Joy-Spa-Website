<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    // Production break caught: replacing upserts with inserts would duplicate the fixed catalog when the seeder runs again.
    public function test_catalog_seeder_persists_the_exact_idempotent_catalog(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);

        $this->assertDatabaseCount('service_categories', 4);
        $this->assertDatabaseCount('services', 12);
        $this->assertSame(4, ServiceCategory::query()->distinct()->count('slug'));
        $this->assertSame(12, Service::query()->distinct()->count('slug'));

        $this->assertDatabaseHas('service_categories', [
            'slug' => 'massage',
            'name_id' => 'Pijat',
            'name_en' => 'Massage',
            'image_path' => '/img/massage.jpg',
            'is_active' => true,
            'display_order' => 1,
        ]);
        $this->assertDatabaseHas('services', [
            'slug' => 'javanese-massage',
            'name_id' => 'Pijat Tradisional Jawa',
            'duration_minutes' => 90,
            'price_idr' => 185000,
            'popularity' => 98,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $this->assertDatabaseHas('services', [
            'slug' => 'couple-package',
            'name_en' => 'Couple Retreat Package',
            'duration_minutes' => 180,
            'price_idr' => 850000,
            'popularity' => 85,
            'display_order' => 12,
        ]);
    }

    // Production break caught: removing casts or the category foreign key would return untyped gallery data or disconnect services from their categories.
    public function test_seeded_services_cast_catalog_values_and_resolve_their_category(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        $service = Service::query()->where('slug', 'javanese-massage')->firstOrFail();

        $this->assertSame(['massage.jpg', 'oil.jpg', 'stilllife.jpg'], $service->gallery_paths);
        $this->assertIsInt($service->duration_minutes);
        $this->assertIsInt($service->price_idr);
        $this->assertIsInt($service->popularity);
        $this->assertIsInt($service->display_order);
        $this->assertTrue($service->is_active);
        $this->assertSame('massage', $service->category->slug);
        $this->assertCount(4, ServiceCategory::query()->where('slug', 'massage')->firstOrFail()->services);
    }

    // Production break caught: changing the category foreign key to cascade or null-on-delete would allow a populated catalog category to be removed.
    public function test_a_category_with_services_cannot_be_deleted(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        $this->expectException(QueryException::class);

        ServiceCategory::query()->where('slug', 'massage')->firstOrFail()->delete();
    }

    // Production break caught: a public query that only filters a service's flag would expose inactive services or services in inactive categories and could reorder public results.
    public function test_publicly_visible_scope_excludes_inactive_records_and_has_deterministic_ordering(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        Service::query()->where('slug', 'aromatherapy-massage')->update(['is_active' => false]);
        ServiceCategory::query()->where('slug', 'facial')->update(['is_active' => false]);

        $visibleSlugs = Service::query()->publiclyVisible()->pluck('slug')->all();

        $this->assertSame([
            'javanese-massage',
            'hot-stone-massage',
            'deep-tissue-massage',
            'body-scrub',
            'body-wrap',
            'foot-reflexology',
            'serenity-package',
            'couple-package',
        ], $visibleSlugs);
        $this->assertSame(['massage', 'body', 'package'], ServiceCategory::query()->active()->pluck('slug')->all());
    }
}
