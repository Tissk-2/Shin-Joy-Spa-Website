<?php

namespace Tests\Feature\Admin;

use App\Enums\CatalogStatus;
use App\Enums\TreatmentMode;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_rows_have_publication_and_lock_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('service_categories', ['published_at', 'lock_version']));
        $this->assertTrue(Schema::hasColumns('services', ['published_at', 'lock_version']));
        $this->assertTrue(Schema::hasColumn('service_variants', 'published_at'));
        $this->assertTrue(Schema::hasColumn('service_extensions', 'published_at'));
    }

    public function test_catalog_status_classifies_draft_published_and_archived_rows(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-09-10 09:00:00');

        $this->assertSame(CatalogStatus::Draft, CatalogStatus::fromLifecycle(null, true));
        $this->assertSame(CatalogStatus::Draft, CatalogStatus::fromLifecycle(null, false));
        $this->assertSame(CatalogStatus::Published, CatalogStatus::fromLifecycle($publishedAt, true));
        $this->assertSame(CatalogStatus::Archived, CatalogStatus::fromLifecycle($publishedAt, false));
    }

    public function test_catalog_models_cast_lifecycle_values_and_report_their_status(): void
    {
        $category = ServiceCategory::query()->create([
            'slug' => 'draft-category', 'name_id' => 'Draf', 'name_en' => 'Draft',
            'image_path' => null, 'published_at' => null, 'lock_version' => 3,
            'is_active' => true, 'display_order' => 1,
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id, 'slug' => 'archived-service',
            'name_id' => 'Arsip', 'name_en' => 'Archived',
            'description_id' => null, 'description_en' => null,
            'long_description_id' => null, 'long_description_en' => null,
            'image_path' => null, 'gallery_paths' => [], 'popularity' => 0,
            'is_vip' => false, 'is_active' => false,
            'published_at' => '2026-09-10 09:00:00', 'lock_version' => 7,
            'display_order' => 1,
        ]);
        $variant = ServiceVariant::query()->create([
            'service_id' => $service->id, 'code' => 'archived-regular-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 175000, 'is_active' => true,
            'published_at' => '2026-09-10 09:00:00', 'display_order' => 1,
        ]);
        $extension = ServiceExtension::query()->create([
            'service_id' => $service->id, 'code' => 'draft-extra-30',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 30,
            'price_idr' => 50000, 'is_active' => true,
            'published_at' => null, 'display_order' => 1,
        ]);

        $this->assertSame(CatalogStatus::Draft, $category->fresh()->catalogStatus());
        $this->assertSame(3, $category->fresh()->lock_version);
        $this->assertSame(CatalogStatus::Archived, $service->fresh()->catalogStatus());
        $this->assertSame(7, $service->fresh()->lock_version);
        $this->assertInstanceOf(CarbonInterface::class, $service->fresh()->published_at);
        $this->assertSame(CatalogStatus::Published, $variant->fresh()->catalogStatus());
        $this->assertInstanceOf(CarbonInterface::class, $variant->fresh()->published_at);
        $this->assertSame(CatalogStatus::Draft, $extension->fresh()->catalogStatus());
    }

    public function test_draft_categories_and_services_allow_nullable_content_and_database_defaults(): void
    {
        $category = ServiceCategory::query()->create([
            'slug' => 'future-care', 'name_id' => 'Perawatan Mendatang', 'name_en' => 'Future Care',
            'image_path' => null, 'published_at' => null,
            'is_active' => true, 'display_order' => 9,
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id, 'slug' => 'future-treatment',
            'name_id' => 'Perawatan Baru', 'name_en' => 'New Treatment',
            'description_id' => null, 'description_en' => null,
            'long_description_id' => null, 'long_description_en' => null,
            'image_path' => null, 'is_vip' => false, 'is_active' => true,
            'published_at' => null, 'display_order' => 1,
        ])->fresh();

        $this->assertNull($category->fresh()->image_path);
        $this->assertNull($service->description_id);
        $this->assertNull($service->description_en);
        $this->assertNull($service->long_description_id);
        $this->assertNull($service->long_description_en);
        $this->assertNull($service->image_path);
        $this->assertSame([], $service->gallery_paths);
        $this->assertSame(0, $service->popularity);
        $this->assertSame(0, $service->lock_version);
    }

    public function test_public_scopes_require_published_active_parents_and_choices(): void
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
            'service_id' => $service->id, 'code' => 'draft-body-scrub-60',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
            'price_idr' => 175000, 'is_active' => true,
            'published_at' => null, 'display_order' => 1,
        ]);
        ServiceExtension::query()->create([
            'service_id' => $service->id, 'code' => 'draft-body-extra-30',
            'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 30,
            'price_idr' => 50000, 'is_active' => true,
            'published_at' => null, 'display_order' => 1,
        ]);

        $this->assertSame(['body-care'], ServiceCategory::query()->publiclyVisible()->pluck('slug')->all());
        $this->assertSame([], Service::query()->publiclyVisible()->pluck('slug')->all());
        $this->assertSame([], ServiceVariant::query()->active()->pluck('code')->all());
        $this->assertSame([], ServiceExtension::query()->active()->pluck('code')->all());
    }

    public function test_lifecycle_migration_rolls_back_drafts_and_backfills_legacy_rows(): void
    {
        $defaultConnection = DB::getDefaultConnection();
        config(['database.connections.catalog_lifecycle' => [
            ...config('database.connections.sqlite'),
            'database' => ':memory:',
        ]]);
        DB::purge('catalog_lifecycle');
        DB::setDefaultConnection('catalog_lifecycle');

        try {
            $this->migrateLegacyCatalogSchema();
            [$categoryId, $serviceId] = $this->insertLegacyCatalogRows();

            $migrationPath = database_path('migrations/2026_09_10_000000_add_catalog_publishing_lifecycle.php');
            $this->assertFileExists($migrationPath);
            $migration = require $migrationPath;
            $migration->up();

            $this->assertBackfilledLifecycle($categoryId, $serviceId);

            $draftCategoryId = DB::table('service_categories')->insertGetId([
                'slug' => 'draft-before-rollback', 'name_id' => 'Draf', 'name_en' => 'Draft',
                'image_path' => null, 'published_at' => null,
                'is_active' => true, 'display_order' => 3,
            ]);
            $draftServiceId = DB::table('services')->insertGetId([
                'category_id' => $draftCategoryId, 'slug' => 'draft-before-rollback',
                'name_id' => 'Draf', 'name_en' => 'Draft',
                'description_id' => null, 'description_en' => null,
                'long_description_id' => null, 'long_description_en' => null,
                'image_path' => null, 'gallery_paths' => '[]', 'popularity' => 0,
                'is_vip' => false, 'is_active' => true, 'published_at' => null,
                'display_order' => 1,
            ]);

            $migration->down();

            $this->assertFalse(Schema::hasColumn('services', 'published_at'));
            $this->assertSame('', DB::table('service_categories')->where('id', $draftCategoryId)->value('image_path'));
            $this->assertSame('', DB::table('services')->where('id', $draftServiceId)->value('description_id'));
            $this->assertSame('', DB::table('services')->where('id', $draftServiceId)->value('image_path'));
            $this->assertSame('/img/legacy.jpg', DB::table('services')->where('id', $serviceId)->value('image_path'));

            $migration->up();
            $this->assertBackfilledLifecycle($categoryId, $serviceId);
        } finally {
            DB::purge('catalog_lifecycle');
            DB::setDefaultConnection($defaultConnection);
        }
    }

    // Production break caught: archived published rows may contain null draftable content and must still roll back.
    public function test_lifecycle_migration_rolls_back_archived_rows_with_null_required_content(): void
    {
        $defaultConnection = DB::getDefaultConnection();
        config(['database.connections.catalog_lifecycle_archived' => [
            ...config('database.connections.sqlite'),
            'database' => ':memory:',
        ]]);
        DB::purge('catalog_lifecycle_archived');
        DB::setDefaultConnection('catalog_lifecycle_archived');

        try {
            $this->migrateLegacyCatalogSchema();
            $migration = require database_path('migrations/2026_09_10_000000_add_catalog_publishing_lifecycle.php');
            $migration->up();

            $categoryId = DB::table('service_categories')->insertGetId([
                'slug' => 'archived-category', 'name_id' => 'Arsip', 'name_en' => 'Archived',
                'image_path' => null, 'published_at' => now(),
                'is_active' => false, 'display_order' => 4,
            ]);
            $serviceId = DB::table('services')->insertGetId([
                'category_id' => $categoryId, 'slug' => 'archived-treatment',
                'name_id' => 'Arsip', 'name_en' => 'Archived',
                'description_id' => null, 'description_en' => null,
                'long_description_id' => null, 'long_description_en' => null,
                'image_path' => null, 'gallery_paths' => '[]', 'popularity' => 0,
                'is_vip' => false, 'is_active' => false, 'published_at' => now(),
                'display_order' => 1,
            ]);

            $migration->down();

            $this->assertFalse(Schema::hasColumn('services', 'published_at'));
            $this->assertSame('', DB::table('service_categories')->where('id', $categoryId)->value('image_path'));
            foreach (['description_id', 'description_en', 'long_description_id', 'long_description_en', 'image_path'] as $column) {
                $this->assertSame('', DB::table('services')->where('id', $serviceId)->value($column));
            }
        } finally {
            DB::purge('catalog_lifecycle_archived');
            DB::setDefaultConnection($defaultConnection);
        }
    }

    private function migrateLegacyCatalogSchema(): void
    {
        foreach ([
            '2026_09_01_000000_create_service_categories_table.php',
            '2026_09_01_000001_create_services_table.php',
            '2026_09_03_000000_update_services_for_package_catalog.php',
            '2026_09_03_000001_create_service_variants_table.php',
            '2026_09_03_000002_create_service_extensions_table.php',
        ] as $migration) {
            (require database_path("migrations/{$migration}"))->up();
        }
    }

    /** @return array{0: int, 1: int} */
    private function insertLegacyCatalogRows(): array
    {
        $categoryId = DB::table('service_categories')->insertGetId([
            'slug' => 'legacy-category', 'name_id' => 'Kategori Lama', 'name_en' => 'Legacy Category',
            'image_path' => '/img/legacy.jpg', 'is_active' => true, 'display_order' => 2,
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId, 'slug' => 'legacy-treatment',
            'name_id' => 'Perawatan Lama', 'name_en' => 'Legacy Treatment',
            'description_id' => 'Deskripsi', 'description_en' => 'Description',
            'long_description_id' => 'Deskripsi lengkap', 'long_description_en' => 'Full description',
            'image_path' => '/img/legacy.jpg', 'gallery_paths' => '[]', 'popularity' => 0,
            'is_vip' => false, 'is_active' => true, 'display_order' => 1,
        ]);
        DB::table('service_variants')->insert([
            'service_id' => $serviceId, 'code' => 'legacy-regular-60',
            'treatment_mode' => TreatmentMode::Regular->value, 'duration_minutes' => 60,
            'price_idr' => 125000, 'is_active' => true, 'display_order' => 1,
        ]);
        DB::table('service_extensions')->insert([
            'service_id' => $serviceId, 'code' => 'legacy-extra-30',
            'treatment_mode' => TreatmentMode::Regular->value, 'duration_minutes' => 30,
            'price_idr' => 50000, 'is_active' => true, 'display_order' => 1,
        ]);

        return [$categoryId, $serviceId];
    }

    private function assertBackfilledLifecycle(int $categoryId, int $serviceId): void
    {
        $this->assertNotNull(DB::table('service_categories')->where('id', $categoryId)->value('published_at'));
        $this->assertSame(0, DB::table('service_categories')->where('id', $categoryId)->value('lock_version'));
        $this->assertNotNull(DB::table('services')->where('id', $serviceId)->value('published_at'));
        $this->assertSame(0, DB::table('services')->where('id', $serviceId)->value('lock_version'));
        $this->assertNotNull(DB::table('service_variants')->where('service_id', $serviceId)->value('published_at'));
        $this->assertNotNull(DB::table('service_extensions')->where('service_id', $serviceId)->value('published_at'));
    }
}
