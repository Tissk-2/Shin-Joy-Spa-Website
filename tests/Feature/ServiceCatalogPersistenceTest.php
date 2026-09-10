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
            'price_idr' => 265000, 'is_active' => true,
            'published_at' => now(), 'display_order' => 2,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $service->id, 'code' => 'nezuko-regular-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 199000, 'is_active' => true,
            'published_at' => now(), 'display_order' => 1,
        ]);
        ServiceExtension::query()->create([
            'service_id' => $service->id, 'code' => 'nezuko-regular-extra-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 75000, 'is_active' => true,
            'published_at' => now(), 'display_order' => 1,
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

        $this->assertDatabaseHas('service_categories', [
            'slug' => 'package', 'name_id' => 'Paket Spa', 'name_en' => 'Spa Packages',
            'image_path' => '/img/package.jpg', 'is_active' => true, 'display_order' => 1,
        ]);
        $this->assertNotNull(ServiceCategory::query()->where('slug', 'package')->value('published_at'));

        foreach ($this->officialPackages() as $slug => $attributes) {
            $service = Service::query()->where('slug', $slug)->firstOrFail();

            $this->assertSame($attributes['name_id'], $service->name_id);
            $this->assertSame($attributes['name_en'], $service->name_en);
            $this->assertSame($attributes['description_id'], $service->description_id);
            $this->assertSame($attributes['description_en'], $service->description_en);
            $this->assertSame($attributes['description_id'], $service->long_description_id);
            $this->assertSame($attributes['description_en'], $service->long_description_en);
            $this->assertSame($attributes['good_for_id'], $service->good_for_id);
            $this->assertSame($attributes['good_for_en'], $service->good_for_en);
            $this->assertSame($attributes['is_vip'], $service->is_vip);
            $this->assertSame($attributes['popularity'], $service->popularity);
            $this->assertSame($attributes['gallery_paths'], $service->gallery_paths);
            $this->assertNull($service->duration_minutes);
            $this->assertNull($service->price_idr);
            $this->assertSame($attributes['image_path'], $service->image_path);
            $this->assertSame($attributes['display_order'], $service->display_order);
            $this->assertSame('package', $service->category->slug);
            $this->assertTrue($service->is_active);
            $this->assertNotNull($service->published_at);
        }

        foreach ($this->officialVariants() as $row) {
            $variant = ServiceVariant::query()->where('code', $row['code'])->firstOrFail();

            $this->assertSame($row['service'], $variant->service->slug);
            $this->assertSame($row['mode'], $variant->treatment_mode);
            $this->assertSame($row['duration'], $variant->duration_minutes);
            $this->assertSame($row['price'], $variant->price_idr);
            $this->assertSame($row['display_order'], $variant->display_order);
            $this->assertTrue($variant->is_active);
            $this->assertNotNull($variant->published_at);
        }

        foreach ($this->officialExtensions() as $row) {
            $extension = ServiceExtension::query()->where('code', $row['code'])->firstOrFail();

            $this->assertSame($row['service'], $extension->service->slug);
            $this->assertSame($row['mode'], $extension->treatment_mode);
            $this->assertSame($row['duration'], $extension->duration_minutes);
            $this->assertSame($row['price'], $extension->price_idr);
            $this->assertSame($row['display_order'], $extension->display_order);
            $this->assertTrue($extension->is_active);
            $this->assertNotNull($extension->published_at);
        }
    }

    // Production break caught: resolving catalog collisions by deletion discards existing child-row identities and data history.
    public function test_catalog_seeder_reuses_natural_choices_archives_code_collisions_and_preserves_unknown_children(): void
    {
        $packageCategory = ServiceCategory::query()->create([
            'slug' => 'package', 'name_id' => 'Paket', 'name_en' => 'Packages',
            'image_path' => '/img/package.jpg', 'is_active' => true, 'display_order' => 1,
        ]);
        $nezuko = Service::query()->create($this->packageAttributes($packageCategory, 'nezuko'));
        $staleVariant = ServiceVariant::query()->create([
            'service_id' => $nezuko->id, 'code' => 'stale-nezuko-regular-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 1, 'is_active' => true, 'display_order' => 99,
        ]);
        $staleExtension = ServiceExtension::query()->create([
            'service_id' => $nezuko->id, 'code' => 'stale-nezuko-extra-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 1, 'is_active' => true, 'display_order' => 99,
        ]);
        $variantCodeCollision = ServiceVariant::query()->create([
            'service_id' => $nezuko->id, 'code' => 'nezuko-regular-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 45,
            'price_idr' => 2, 'is_active' => true,
            'published_at' => '2026-09-01 10:00:00', 'display_order' => 98,
        ]);
        $extensionCodeCollision = ServiceExtension::query()->create([
            'service_id' => $nezuko->id, 'code' => 'nezuko-regular-extra-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 45,
            'price_idr' => 2, 'is_active' => true, 'display_order' => 98,
        ]);
        $futureCategory = ServiceCategory::query()->create([
            'slug' => 'future', 'name_id' => 'Masa Depan', 'name_en' => 'Future',
            'image_path' => '/img/about.jpg', 'is_active' => true, 'display_order' => 2,
        ]);
        $future = Service::query()->create($this->packageAttributes($futureCategory, 'future-treatment'));
        $futureVariant = ServiceVariant::query()->create([
            'service_id' => $future->id, 'code' => 'future-regular-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 123000, 'is_active' => true, 'display_order' => 1,
        ]);
        $futureExtension = ServiceExtension::query()->create([
            'service_id' => $future->id, 'code' => 'future-extra-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 45000, 'is_active' => true, 'display_order' => 1,
        ]);

        $this->seed(ServiceCatalogSeeder::class);

        $this->assertDatabaseHas('service_variants', [
            'id' => $staleVariant->id,
            'service_id' => $nezuko->id,
            'code' => 'nezuko-regular-60',
            'treatment_mode' => TreatmentMode::Regular->value,
            'duration_minutes' => 60,
            'price_idr' => 199000,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $this->assertDatabaseHas('service_extensions', [
            'id' => $staleExtension->id,
            'service_id' => $nezuko->id,
            'code' => 'nezuko-regular-extra-60',
            'treatment_mode' => TreatmentMode::Regular->value,
            'duration_minutes' => 60,
            'price_idr' => 75000,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $archivedVariantCode = $variantCodeCollision->fresh()->code;
        $archivedExtensionCode = $extensionCodeCollision->fresh()->code;
        $this->assertNotSame('nezuko-regular-60', $archivedVariantCode);
        $this->assertNotSame('nezuko-regular-extra-60', $archivedExtensionCode);
        $this->assertDatabaseHas('service_variants', ['id' => $variantCodeCollision->id, 'code' => $archivedVariantCode, 'is_active' => false]);
        $this->assertDatabaseHas('service_extensions', ['id' => $extensionCodeCollision->id, 'code' => $archivedExtensionCode, 'is_active' => false]);
        $this->assertSame('2026-09-01 10:00:00', $variantCodeCollision->fresh()->published_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($extensionCodeCollision->fresh()->published_at);
        $this->assertDatabaseHas('service_variants', [
            'id' => $futureVariant->id, 'service_id' => $future->id, 'code' => 'future-regular-60',
            'price_idr' => 123000, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('service_extensions', [
            'id' => $futureExtension->id, 'service_id' => $future->id, 'code' => 'future-extra-60',
            'price_idr' => 45000, 'is_active' => true,
        ]);
        $this->assertSame(8, ServiceVariant::query()->whereIn('service_id', [$nezuko->id, Service::query()->where('slug', 'nezuko-vip')->value('id')])->where('is_active', true)->count());
        $this->assertSame(4, ServiceExtension::query()->whereIn('service_id', [$nezuko->id, Service::query()->where('slug', 'nezuko-vip')->value('id')])->where('is_active', true)->count());

        $variantCount = ServiceVariant::query()->count();
        $extensionCount = ServiceExtension::query()->count();

        $this->seed(ServiceCatalogSeeder::class);

        $this->assertSame($variantCount, ServiceVariant::query()->count());
        $this->assertSame($extensionCount, ServiceExtension::query()->count());
        $this->assertSame($archivedVariantCode, $variantCodeCollision->fresh()->code);
        $this->assertSame($archivedExtensionCode, $extensionCodeCollision->fresh()->code);
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

    // Production break caught: a slug whitelist would hide valid packages added through the admin catalog.
    public function test_any_published_package_can_be_publicly_visible(): void
    {
        $category = ServiceCategory::query()->create([
            'slug' => 'body-care', 'name_id' => 'Perawatan Tubuh', 'name_en' => 'Body Care',
            'image_path' => '/img/body.jpg', 'published_at' => now(),
            'is_active' => true, 'display_order' => 2,
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id, 'slug' => 'body-scrub-deluxe',
            'name_id' => 'Body Scrub Deluxe', 'name_en' => 'Deluxe Body Scrub',
            'description_id' => 'Perawatan tubuh.', 'description_en' => 'A body treatment.',
            'long_description_id' => 'Perawatan tubuh lengkap.',
            'long_description_en' => 'A complete body treatment.',
            'image_path' => '/img/body.jpg', 'gallery_paths' => [],
            'is_vip' => false, 'is_active' => true, 'published_at' => now(),
            'display_order' => 1, 'popularity' => 0,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $service->id, 'code' => 'body-scrub-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 175000, 'is_active' => true,
            'published_at' => now(), 'display_order' => 1,
        ]);

        $this->assertSame(['body-scrub-deluxe'], Service::query()->publiclyVisible()->pluck('slug')->all());
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

    /** @return array<string, array<string, mixed>> */
    private function officialPackages(): array
    {
        return [
            'nezuko' => [
                'name_id' => 'Nezuko', 'name_en' => 'Nezuko', 'is_vip' => false,
                'description_id' => 'Pijatan lembut dengan perpaduan pijat tradisional dan pijat Thailand, menggunakan minyak atau tanpa minyak, yang menghadirkan sensasi relaksasi.',
                'description_en' => 'A gentle treatment combining traditional and Thai massage techniques, with or without oil, designed to create a deeply relaxing experience.',
                'good_for_id' => 'Membantu memperlancar peredaran darah dan meningkatkan kualitas tidur.',
                'good_for_en' => 'Helps support healthy circulation and improve sleep quality.',
                'image_path' => '/img/massage.jpg', 'gallery_paths' => ['massage.jpg', 'oil.jpg', 'about.jpg'],
                'popularity' => 100, 'display_order' => 1,
            ],
            'nezuko-vip' => [
                'name_id' => 'Nezuko VIP', 'name_en' => 'Nezuko VIP', 'is_vip' => true,
                'description_id' => 'Perpaduan pijat tradisional dan pijat Thailand, menggunakan minyak atau tanpa minyak, yang disesuaikan dengan kebutuhan dan keluhan klien untuk menghadirkan sensasi relaksasi.',
                'description_en' => "A tailored blend of traditional and Thai massage, with or without oil, adjusted to the client's needs and preferences for a deeply relaxing experience.",
                'good_for_id' => 'Membantu memperlancar peredaran darah dan meningkatkan kualitas tidur.',
                'good_for_en' => 'Helps support healthy circulation and improve sleep quality.',
                'image_path' => '/img/package.jpg', 'gallery_paths' => ['package.jpg', 'hands.jpg', 'oil.jpg'],
                'popularity' => 99, 'display_order' => 2,
            ],
        ];
    }

    /** @return list<array{service: string, code: string, mode: TreatmentMode, duration: int, price: int, display_order: int}> */
    private function officialVariants(): array
    {
        return [
            ['service' => 'nezuko', 'code' => 'nezuko-regular-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 199000, 'display_order' => 1],
            ['service' => 'nezuko', 'code' => 'nezuko-regular-90', 'mode' => TreatmentMode::Regular, 'duration' => 90, 'price' => 265000, 'display_order' => 2],
            ['service' => 'nezuko', 'code' => 'nezuko-regular-120', 'mode' => TreatmentMode::Regular, 'duration' => 120, 'price' => 315000, 'display_order' => 3],
            ['service' => 'nezuko', 'code' => 'nezuko-double-60', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 60, 'price' => 250000, 'display_order' => 4],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 299000, 'display_order' => 5],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-90', 'mode' => TreatmentMode::Regular, 'duration' => 90, 'price' => 349000, 'display_order' => 6],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-120', 'mode' => TreatmentMode::Regular, 'duration' => 120, 'price' => 499000, 'display_order' => 7],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-double-60', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 60, 'price' => 350000, 'display_order' => 8],
        ];
    }

    /** @return list<array{service: string, code: string, mode: TreatmentMode, duration: int, price: int, display_order: int}> */
    private function officialExtensions(): array
    {
        return [
            ['service' => 'nezuko', 'code' => 'nezuko-regular-extra-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 75000, 'display_order' => 1],
            ['service' => 'nezuko', 'code' => 'nezuko-double-extra-30', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 30, 'price' => 100000, 'display_order' => 2],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-extra-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 100000, 'display_order' => 3],
            ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-double-extra-30', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 30, 'price' => 100000, 'display_order' => 4],
        ];
    }
}
