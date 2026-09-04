<?php

namespace Tests\Feature;

use App\Enums\TreatmentMode;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_variants_and_extensions_are_typed_ordered_and_owned(): void
    {
        $category = ServiceCategory::query()->create([
            'slug' => 'package', 'name_id' => 'Paket', 'name_en' => 'Packages',
            'image_path' => '/img/package.jpg', 'is_active' => true, 'display_order' => 1,
        ]);
        $service = Service::query()->create($this->packageAttributes($category, 'nezuko'));

        ServiceVariant::query()->create([
            'service_id' => $service->id, 'code' => 'nezuko-regular-90',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 90,
            'price_idr' => 265000, 'is_active' => true, 'display_order' => 2,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $service->id, 'code' => 'nezuko-regular-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 199000, 'is_active' => true, 'display_order' => 1,
        ]);
        ServiceExtension::query()->create([
            'service_id' => $service->id, 'code' => 'nezuko-regular-extra-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 75000, 'is_active' => true, 'display_order' => 1,
        ]);

        $service->load(['variants' => fn ($query) => $query->active(), 'extensions' => fn ($query) => $query->active()]);

        $this->assertSame([60, 90], $service->variants->pluck('duration_minutes')->all());
        $this->assertSame(TreatmentMode::Regular, $service->variants->first()->treatment_mode);
        $this->assertSame(75000, $service->extensions->first()->price_idr);
        $service->delete();
        $this->assertDatabaseMissing('service_variants', ['code' => 'nezuko-regular-60']);
        $this->assertDatabaseMissing('service_extensions', ['code' => 'nezuko-regular-extra-60']);
    }

    public function test_package_choices_reject_duplicate_mode_and_duration_per_service(): void
    {
        $category = ServiceCategory::query()->create([
            'slug' => 'package-duplicates', 'name_id' => 'Paket', 'name_en' => 'Packages',
            'image_path' => '/img/package.jpg', 'is_active' => true, 'display_order' => 1,
        ]);
        $service = Service::query()->create($this->packageAttributes($category, 'nezuko-duplicates'));

        ServiceVariant::query()->create([
            'service_id' => $service->id, 'code' => 'nezuko-regular-60-first',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 199000, 'is_active' => true, 'display_order' => 1,
        ]);

        $variantException = null;

        try {
            ServiceVariant::query()->create([
                'service_id' => $service->id, 'code' => 'nezuko-regular-60-duplicate',
                'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
                'price_idr' => 205000, 'is_active' => true, 'display_order' => 2,
            ]);
        } catch (QueryException $exception) {
            $variantException = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $variantException);

        ServiceExtension::query()->create([
            'service_id' => $service->id, 'code' => 'nezuko-extra-60-first',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 75000, 'is_active' => true, 'display_order' => 1,
        ]);

        $extensionException = null;

        try {
            ServiceExtension::query()->create([
                'service_id' => $service->id, 'code' => 'nezuko-extra-60-duplicate',
                'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
                'price_idr' => 80000, 'is_active' => true, 'display_order' => 2,
            ]);
        } catch (QueryException $exception) {
            $extensionException = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $extensionException);
    }

    // Production break caught: replacing the official packages or their child choices would expose the wrong public menu.
    public function test_catalog_seeder_persists_the_exact_official_menu_idempotently(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);

        $this->assertSame(['nezuko', 'nezuko-vip'], Service::query()->publiclyVisible()->pluck('slug')->all());
        $this->assertSame(8, ServiceVariant::query()->where('is_active', true)->count());
        $this->assertSame(4, ServiceExtension::query()->where('is_active', true)->count());
        $this->assertDatabaseHas('service_variants', [
            'code' => 'nezuko-regular-60', 'duration_minutes' => 60, 'price_idr' => 199000,
        ]);
        $this->assertDatabaseHas('service_variants', [
            'code' => 'nezuko-vip-double-60', 'duration_minutes' => 60, 'price_idr' => 350000,
        ]);
        $this->assertDatabaseHas('service_extensions', [
            'code' => 'nezuko-regular-extra-60', 'duration_minutes' => 60, 'price_idr' => 75000,
        ]);
        $this->assertDatabaseHas('service_extensions', [
            'code' => 'nezuko-vip-double-extra-30', 'duration_minutes' => 30, 'price_idr' => 100000,
        ]);
    }

    // Production break caught: broad retirement queries could deactivate future or admin-created services.
    public function test_catalog_seeder_preserves_unknown_services_while_retiring_known_placeholders(): void
    {
        $category = ServiceCategory::query()->create([
            'slug' => 'future', 'name_id' => 'Masa Depan', 'name_en' => 'Future',
            'image_path' => '/img/about.jpg', 'is_active' => true, 'display_order' => 99,
        ]);
        $unknown = Service::query()->create($this->packageAttributes($category, 'future-treatment'));
        $placeholderCategory = ServiceCategory::query()->create([
            'slug' => 'legacy', 'name_id' => 'Lama', 'name_en' => 'Legacy',
            'image_path' => '/img/massage.jpg', 'is_active' => true, 'display_order' => 100,
        ]);
        foreach ($this->placeholderSlugs() as $slug) {
            Service::query()->create($this->packageAttributes($placeholderCategory, $slug));
        }

        $this->seed(ServiceCatalogSeeder::class);

        $this->assertTrue($unknown->fresh()->is_active);
        $this->assertSame('future', $unknown->fresh()->category->slug);
        $this->assertSame(12, Service::query()->whereIn('slug', $this->placeholderSlugs())->where('is_active', false)->count());
    }

    // Production break caught: removing casts or the category foreign key would return untyped gallery data or disconnect services from their categories.
    public function test_seeded_services_cast_catalog_values_and_resolve_their_category(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        $service = Service::query()->where('slug', 'nezuko')->firstOrFail();

        $this->assertSame(['massage.jpg', 'oil.jpg', 'about.jpg'], $service->gallery_paths);
        $this->assertNull($service->duration_minutes);
        $this->assertNull($service->price_idr);
        $this->assertIsInt($service->popularity);
        $this->assertIsInt($service->display_order);
        $this->assertTrue($service->is_active);
        $this->assertSame('package', $service->category->slug);
        $this->assertCount(2, ServiceCategory::query()->where('slug', 'package')->firstOrFail()->services);
    }

    // Production break caught: changing the category foreign key to cascade or null-on-delete would allow a populated catalog category to be removed.
    public function test_a_category_with_services_cannot_be_deleted(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        $this->expectException(QueryException::class);

        ServiceCategory::query()->where('slug', 'package')->firstOrFail()->delete();
    }

    // Production break caught: a public query that only filters a service's flag would expose inactive services or services in inactive categories and could reorder public results.
    public function test_publicly_visible_scope_excludes_inactive_records_and_has_deterministic_ordering(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        Service::query()->where('slug', 'nezuko')->update(['is_active' => false]);

        $visibleSlugs = Service::query()->publiclyVisible()->pluck('slug')->all();

        $this->assertSame(['nezuko-vip'], $visibleSlugs);
        $this->assertSame(['package'], ServiceCategory::query()->active()->pluck('slug')->all());
    }

    /** @return array<string, mixed> */
    private function packageAttributes(ServiceCategory $category, string $slug): array
    {
        return [
            'category_id' => $category->id,
            'slug' => $slug,
            'name_id' => 'Nezuko',
            'name_en' => 'Nezuko',
            'description_id' => 'Deskripsi',
            'description_en' => 'Description',
            'long_description_id' => 'Deskripsi lengkap',
            'long_description_en' => 'Full description',
            'good_for_id' => 'Relaksasi',
            'good_for_en' => 'Relaxation',
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => '/img/massage.jpg',
            'gallery_paths' => ['massage.jpg'],
            'popularity' => 100,
            'is_vip' => false,
            'is_active' => true,
            'display_order' => 1,
        ];
    }

    /** @return list<string> */
    private function placeholderSlugs(): array
    {
        return [
            'javanese-massage', 'aromatherapy-massage', 'hot-stone-massage', 'deep-tissue-massage',
            'brightening-facial', 'antiaging-facial', 'acne-facial', 'body-scrub', 'body-wrap',
            'foot-reflexology', 'serenity-package', 'couple-package',
        ];
    }
}
