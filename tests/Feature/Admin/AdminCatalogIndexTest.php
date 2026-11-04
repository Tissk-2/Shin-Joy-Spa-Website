<?php

namespace Tests\Feature\Admin;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\AdminCatalogPresenter;
use App\Support\Catalog\ManagedCatalogPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminCatalogIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_ordered_allow_listed_catalog_props_with_public_images_counts_and_retention_ids(): void
    {
        if (! class_exists(AdminCatalogPresenter::class)) {
            $this->fail('The admin catalog presenter has not been implemented.');
        }

        $laterCategory = $this->createCategory('later-category', 2, null, true);
        $firstCategory = $this->createCategory(
            'first-category',
            1,
            'catalog/categories/first.jpg',
            true,
        );
        $this->createCategory('same-order-category', 1, null, false);

        $laterPackage = $this->createService($firstCategory, 'later-package', 2, false);
        $firstPackage = $this->createService(
            $firstCategory,
            'first-package',
            1,
            true,
            'catalog/packages/first-cover.jpg',
            ['catalog/packages/first-detail.jpg'],
        );
        $this->createService($firstCategory, 'same-order-package', 1, true);
        $this->createService($laterCategory, 'last-category-package', 1, true);

        $this->createVariant($firstPackage, 'first-regular-60', 60);
        $this->createVariant($firstPackage, 'first-regular-90', 90);
        $this->createExtension($firstPackage, 'first-extra-30');
        $this->createVariant($laterPackage, 'later-regular-60', 60);

        $admin = User::factory()->admin()->create();

        Model::preventLazyLoading();
        try {
            $response = $this->actingAs($admin)->get('/admin/catalog');
        } finally {
            Model::preventLazyLoading(false);
        }

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/Index', false)
            ->has('packages', 4)
        );

        $packages = $response->inertiaProps('packages');

        $this->assertSame(
            ['first-package', 'same-order-package', 'later-package', 'last-category-package'],
            array_column($packages, 'slug'),
        );
        $this->assertSame([
            'version', 'slug', 'categorySlug', 'nameId', 'nameEn', 'image', 'gallery', 'isVip',
            'status', 'variantCount', 'extensionCount', 'optionCount', 'displayOrder', 'can',
        ], array_keys($packages[0]));
        $this->assertSame(
            ['retentionId', 'url'],
            array_keys($packages[0]['gallery'][0]),
        );
        $this->assertSame(
            ['update', 'publish', 'archive', 'restore', 'delete'],
            array_keys($packages[0]['can']),
        );

        $this->assertSame('http://localhost/storage/catalog/packages/first-cover.jpg', $packages[0]['image']);
        $this->assertSame('http://localhost/storage/catalog/packages/first-detail.jpg', $packages[0]['gallery'][0]['url']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $packages[0]['gallery'][0]['retentionId']);
        $this->assertSame(
            ['catalog/packages/first-detail.jpg'],
            app(ManagedCatalogPath::class)->resolveRetained(
                ['catalog/packages/first-detail.jpg'],
                [$packages[0]['gallery'][0]['retentionId']],
            ),
        );
        $this->assertSame(2, $packages[0]['variantCount']);
        $this->assertSame(1, $packages[0]['extensionCount']);
        $this->assertSame(3, $packages[0]['optionCount']);
        $this->assertSame('archived', $packages[0]['status']);
        $this->assertTrue($packages[0]['isVip']);
        $this->assertSame(13, $packages[0]['version']);

        $this->assertForbiddenKeysAreAbsent(['packages' => $packages]);
        $this->assertStringNotContainsString('PRIVATE-BOOKING-CUSTOMER-CANARY', json_encode($packages, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('catalog/packages/first-detail.jpg', $packages[0]['gallery'][0]['retentionId']);

    }

    /** @param array<string, mixed> $value */
    private function assertForbiddenKeysAreAbsent(array $value): void
    {
        $forbiddenKeys = [
            'id', 'category_id', 'image_path', 'gallery_paths', 'lock_version',
            'created_at', 'updated_at', 'published_at', 'bookings', 'customer',
        ];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbiddenKeys);
            }

            if (is_array($item)) {
                $this->assertForbiddenKeysAreAbsent($item);
            }
        }
    }

    private function createCategory(
        string $slug,
        int $displayOrder,
        ?string $imagePath,
        bool $published,
    ): ServiceCategory {
        return ServiceCategory::query()->create([
            'slug' => $slug,
            'name_id' => ucfirst(str_replace('-', ' ', $slug)),
            'name_en' => ucfirst(str_replace('-', ' ', $slug)),
            'image_path' => $imagePath,
            'published_at' => $published ? now() : null,
            'lock_version' => 7,
            'is_active' => $published,
            'display_order' => $displayOrder,
        ]);
    }

    /** @param list<string> $galleryPaths */
    private function createService(
        ServiceCategory $category,
        string $slug,
        int $displayOrder,
        bool $published,
        ?string $imagePath = null,
        array $galleryPaths = [],
    ): Service {
        return Service::query()->create([
            'category_id' => $category->id,
            'slug' => $slug,
            'name_id' => ucfirst(str_replace('-', ' ', $slug)),
            'name_en' => ucfirst(str_replace('-', ' ', $slug)),
            'description_id' => 'PRIVATE-BOOKING-CUSTOMER-CANARY',
            'description_en' => 'PRIVATE-BOOKING-CUSTOMER-CANARY',
            'long_description_id' => null,
            'long_description_en' => null,
            'good_for_id' => null,
            'good_for_en' => null,
            'duration_minutes' => 999,
            'price_idr' => 999999,
            'image_path' => $imagePath,
            'gallery_paths' => $galleryPaths,
            'popularity' => 999,
            'is_vip' => $slug === 'first-package',
            'is_active' => $slug !== 'first-package',
            'published_at' => $published ? now() : null,
            'lock_version' => 13,
            'display_order' => $displayOrder,
        ]);
    }

    private function createVariant(Service $service, string $code, int $durationMinutes): void
    {
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => $code,
            'treatment_mode' => 'regular',
            'duration_minutes' => $durationMinutes,
            'price_idr' => 150000,
            'is_active' => true,
            'published_at' => now(),
            'display_order' => 1,
        ]);
    }

    private function createExtension(Service $service, string $code): void
    {
        ServiceExtension::query()->create([
            'service_id' => $service->id,
            'code' => $code,
            'treatment_mode' => 'regular',
            'duration_minutes' => 30,
            'price_idr' => 50000,
            'is_active' => true,
            'published_at' => now(),
            'display_order' => 1,
        ]);
    }
}
