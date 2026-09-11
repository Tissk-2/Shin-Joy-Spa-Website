<?php

namespace Tests\Feature\Admin;

use App\Actions\Catalog\ChangeCategoryLifecycle;
use App\Actions\Catalog\SaveCatalogCategory;
use App\Data\Admin\Catalog\CatalogCategoryData;
use App\Enums\CatalogStatus;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class AdminCatalogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_a_normalized_draft_with_safe_defaults(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/catalog/categories', [
            'slug' => '  body-care  ',
            'nameId' => '  Perawatan Tubuh  ',
            'nameEn' => '  Body Care  ',
            'displayOrder' => '2',
            'removeImage' => '0',
        ])->assertStatus(303)
            ->assertRedirect(route('admin.catalog.index', absolute: false));

        $category = ServiceCategory::query()->where('slug', 'body-care')->sole();

        $this->assertSame('Perawatan Tubuh', $category->name_id);
        $this->assertSame('Body Care', $category->name_en);
        $this->assertSame(2, $category->display_order);
        $this->assertFalse($category->is_active);
        $this->assertNull($category->published_at);
        $this->assertNull($category->image_path);
        $this->assertSame(0, $category->lock_version);
    }

    public function test_create_rejects_version_unknown_keys_and_unvalidated_boolean_forms(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->validCreatePayload();

        foreach ([
            'version is forbidden' => [['version' => 0], 'version'],
            'unknown key is rejected' => [['is_active' => true], 'is_active'],
            'truthy text is not coerced' => [['removeImage' => 'false'], 'removeImage'],
        ] as [$extra, $field]) {
            $this->actingAs($admin)
                ->postJson('/admin/catalog/categories', [...$payload, ...$extra])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('service_categories', 0);
    }

    public function test_create_requires_a_unique_slug_bilingual_names_and_non_negative_order(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createCategory(['slug' => 'body-care']);

        $this->actingAs($admin)->postJson('/admin/catalog/categories', [
            'slug' => ' body-care ',
            'nameId' => '   ',
            'nameEn' => null,
            'displayOrder' => -1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['slug', 'nameId', 'nameEn', 'displayOrder']);

        $this->assertDatabaseCount('service_categories', 1);
    }

    public function test_category_image_rejects_wrong_type_size_and_dimensions(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $invalidImages = [
            UploadedFile::fake()->create('category.gif', 10, 'image/gif'),
            self::fakeImage('category.jpg', 600, 400, 5 * 1024 + 1),
            self::fakeImage('category.png', 599, 400),
            self::fakeImage('category.webp', 6001, 6000),
        ];

        foreach ($invalidImages as $index => $image) {
            $this->actingAs($admin)->postJson('/admin/catalog/categories', [
                ...$this->validCreatePayload("invalid-image-{$index}"),
                'image' => $image,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('image');
        }

        $this->assertDatabaseCount('service_categories', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('catalog/categories'));
    }

    public function test_valid_image_is_stored_under_the_managed_category_scope(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/catalog/categories', [
            ...$this->validCreatePayload(),
            'image' => self::fakeImage('category.webp', 600, 400),
        ])->assertStatus(303);

        $path = ServiceCategory::query()->sole()->image_path;

        $this->assertMatchesRegularExpression(
            '#\Acatalog/categories/[0-9a-f-]{36}/[0-9a-f-]{36}\.webp\z#',
            $path,
        );
        Storage::disk('public')->assertExists($path);
    }

    public function test_duplicate_slug_constraint_race_becomes_a_slug_validation_error(): void
    {
        $this->createCategory(['slug' => 'body-care']);

        try {
            app(SaveCatalogCategory::class)->create(new CatalogCategoryData(
                slug: 'body-care',
                nameId: 'Kategori Lain',
                nameEn: 'Another Category',
                displayOrder: 2,
                image: null,
                removeImage: false,
            ));
            $this->fail('The database collision should become a validation error.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug'], array_keys($exception->errors()));
        }

        $this->assertDatabaseCount('service_categories', 1);
    }

    public function test_update_uses_exact_normalized_fields_and_accepts_method_spoofing_for_an_image(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $category = $this->createCategory();

        $this->actingAs($admin)->post("/admin/catalog/categories/{$category->slug}", [
            '_method' => 'PATCH',
            'version' => 0,
            'slug' => '  body-treatments  ',
            'nameId' => '  Perawatan Badan  ',
            'nameEn' => '  Body Treatments  ',
            'displayOrder' => '4',
            'image' => self::fakeImage('replacement.png', 800, 500),
            'removeImage' => '0',
        ])->assertStatus(303);

        $updated = $category->fresh();
        $this->assertSame('body-treatments', $updated->slug);
        $this->assertSame('Perawatan Badan', $updated->name_id);
        $this->assertSame('Body Treatments', $updated->name_en);
        $this->assertSame(4, $updated->display_order);
        $this->assertSame(1, $updated->lock_version);
        Storage::disk('public')->assertExists($updated->image_path);

        $this->actingAs($admin)->patchJson("/admin/catalog/categories/{$updated->slug}", [
            ...$this->validUpdatePayload($updated),
            'published_at' => now()->toISOString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('published_at');
    }

    public function test_published_and_archived_categories_cannot_change_slug(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([true, false] as $isActive) {
            $category = $this->createCategory([
                'slug' => $isActive ? 'published-category' : 'archived-category',
                'image_path' => '/img/legacy-category.jpg',
                'published_at' => now(),
                'is_active' => $isActive,
            ]);

            $this->actingAs($admin)->patchJson("/admin/catalog/categories/{$category->slug}", [
                ...$this->validUpdatePayload($category),
                'slug' => "changed-{$category->slug}",
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('slug');

            $this->assertSame($category->slug, $category->fresh()->slug);
            $this->assertSame(0, $category->fresh()->lock_version);
        }
    }

    public function test_stale_update_is_rejected_after_locking_and_changes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->createCategory(['lock_version' => 3]);

        $this->actingAs($admin)->patchJson("/admin/catalog/categories/{$category->slug}", [
            ...$this->validUpdatePayload($category),
            'version' => 2,
            'nameEn' => 'Stale overwrite',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('version');

        $this->assertSame('Body Care', $category->fresh()->name_en);
        $this->assertSame(3, $category->fresh()->lock_version);
    }

    public function test_update_and_lifecycle_mutations_lock_the_persisted_row_and_increment_version(): void
    {
        $category = $this->createCategory([
            'image_path' => '/img/legacy-category.jpg',
            'published_at' => now(),
            'is_active' => true,
        ]);
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        $recordingGrammar = new class($connection) extends SQLiteGrammar
        {
            /** @var list<array{from: string|null, lock: string|bool|null}> */
            public array $compiledSelects = [];

            public function compileSelect(Builder $query)
            {
                $this->compiledSelects[] = ['from' => $query->from, 'lock' => $query->lock];

                return parent::compileSelect($query);
            }
        };
        $connection->setQueryGrammar($recordingGrammar);

        try {
            app(SaveCatalogCategory::class)->update(
                $category,
                0,
                new CatalogCategoryData(
                    slug: $category->slug,
                    nameId: 'Perawatan Tubuh Baru',
                    nameEn: 'New Body Care',
                    displayOrder: 2,
                    image: null,
                    removeImage: false,
                ),
            );
            app(ChangeCategoryLifecycle::class)($category, 1, CatalogStatus::Archived);
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }

        $categoryLocks = array_values(array_filter(
            $recordingGrammar->compiledSelects,
            fn (array $select): bool => $select['from'] === 'service_categories' && $select['lock'] === true,
        ));

        $this->assertCount(2, $categoryLocks);
        $this->assertSame(2, $category->fresh()->lock_version);
    }

    public function test_active_published_edit_must_remain_publishable(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->createCategory([
            'image_path' => '/img/legacy-category.jpg',
            'published_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)->patchJson("/admin/catalog/categories/{$category->slug}", [
            ...$this->validUpdatePayload($category),
            'nameEn' => null,
            'removeImage' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['nameEn', 'image']);

        $category->refresh();
        $this->assertSame('Body Care', $category->name_en);
        $this->assertSame('/img/legacy-category.jpg', $category->image_path);
        $this->assertSame(0, $category->lock_version);
    }

    public function test_publish_requires_complete_content_sets_timestamp_once_and_activates(): void
    {
        Carbon::setTestNow('2026-09-10 10:00:00');
        $admin = User::factory()->admin()->create();
        $category = $this->createCategory();

        $this->actingAs($admin)->postJson("/admin/catalog/categories/{$category->slug}/publish", [
            'version' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertNull($category->fresh()->published_at);
        $category->update(['image_path' => '/img/legacy-category.jpg']);

        $this->actingAs($admin)->post("/admin/catalog/categories/{$category->slug}/publish", [
            'version' => 0,
        ])->assertStatus(303);

        $published = $category->fresh();
        $firstPublishedAt = $published->published_at;
        $this->assertTrue($published->is_active);
        $this->assertSame(1, $published->lock_version);
        $this->assertTrue($firstPublishedAt->equalTo(now()));

        Carbon::setTestNow('2026-09-11 10:00:00');
        $this->actingAs($admin)->post("/admin/catalog/categories/{$category->slug}/archive", [
            'version' => 1,
        ])->assertStatus(303);
        $this->actingAs($admin)->post("/admin/catalog/categories/{$category->slug}/restore", [
            'version' => 2,
        ])->assertStatus(303);

        $restored = $category->fresh();
        $this->assertTrue($restored->published_at->equalTo($firstPublishedAt));
        $this->assertTrue($restored->is_active);
        $this->assertSame(3, $restored->lock_version);
    }

    public function test_archive_changes_only_category_activity_and_preserves_package_rows(): void
    {
        Carbon::setTestNow('2026-09-10 10:00:00');
        $admin = User::factory()->admin()->create();
        $publishedAt = now()->subDay();
        $category = $this->createCategory([
            'image_path' => '/img/legacy-category.jpg',
            'published_at' => $publishedAt,
            'is_active' => true,
        ]);
        $service = $this->createService($category, [
            'is_active' => true,
            'published_at' => $publishedAt,
        ]);

        $this->actingAs($admin)->post("/admin/catalog/categories/{$category->slug}/archive", [
            'version' => 0,
        ])->assertStatus(303);

        $archived = $category->fresh();
        $this->assertFalse($archived->is_active);
        $this->assertTrue($archived->published_at->equalTo($publishedAt));
        $this->assertSame(1, $archived->lock_version);
        $this->assertTrue($service->fresh()->is_active);
        $this->assertTrue($service->fresh()->published_at->equalTo($publishedAt));
    }

    public function test_archived_category_can_be_saved_incomplete_but_cannot_restore_until_publishable(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->createCategory([
            'image_path' => '/img/legacy-category.jpg',
            'published_at' => now(),
            'is_active' => false,
        ]);

        $this->actingAs($admin)->patchJson("/admin/catalog/categories/{$category->slug}", [
            ...$this->validUpdatePayload($category),
            'nameId' => null,
            'nameEn' => '',
            'removeImage' => true,
        ])->assertStatus(303);

        $incomplete = $category->fresh();
        $this->assertSame('', $incomplete->name_id);
        $this->assertSame('', $incomplete->name_en);
        $this->assertNull($incomplete->image_path);
        $this->assertSame(1, $incomplete->lock_version);

        $this->actingAs($admin)->postJson("/admin/catalog/categories/{$category->slug}/restore", [
            'version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['nameId', 'nameEn', 'image']);

        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame(1, $category->fresh()->lock_version);
    }

    public function test_update_requires_each_declared_scalar_key_instead_of_clearing_omitted_copy(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->createCategory([
            'published_at' => now(),
            'is_active' => false,
        ]);

        $this->actingAs($admin)->patchJson("/admin/catalog/categories/{$category->slug}", [
            'version' => 0,
            'slug' => $category->slug,
            'displayOrder' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['nameId', 'nameEn']);

        $this->assertSame('Perawatan Tubuh', $category->fresh()->name_id);
        $this->assertSame('Body Care', $category->fresh()->name_en);
        $this->assertSame(0, $category->fresh()->lock_version);
    }

    public function test_stale_lifecycle_and_delete_requests_change_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->createCategory([
            'image_path' => '/img/legacy-category.jpg',
            'published_at' => now(),
            'is_active' => true,
            'lock_version' => 2,
        ]);

        $this->actingAs($admin)->postJson("/admin/catalog/categories/{$category->slug}/archive", [
            'version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('version');

        $draft = $this->createCategory(['slug' => 'draft-delete', 'lock_version' => 2]);
        $this->actingAs($admin)->deleteJson("/admin/catalog/categories/{$draft->slug}", [
            'version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('version');

        $this->assertTrue($category->fresh()->is_active);
        $this->assertNotNull($draft->fresh());
    }

    public function test_lifecycle_request_accepts_only_a_non_negative_integer_version(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->createCategory([
            'image_path' => '/img/legacy-category.jpg',
            'published_at' => now(),
            'is_active' => true,
        ]);

        foreach ([
            [['version' => -1], 'version'],
            [['version' => 'one'], 'version'],
            [['version' => 0, 'slug' => 'injected'], 'slug'],
        ] as [$payload, $field]) {
            $this->actingAs($admin)
                ->postJson("/admin/catalog/categories/{$category->slug}/archive", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_category_validation_messages_are_localized_and_stale_copy_is_catalog_specific(): void
    {
        $admin = User::factory()->admin()->create();
        $invalid = $this->actingAs($admin)
            ->withSession(['locale' => 'id'])
            ->postJson('/admin/catalog/categories', [
                ...$this->validCreatePayload(),
                'version' => 0,
                'displayOrder' => -1,
                'image' => UploadedFile::fake()->create('category.txt', 1, 'text/plain'),
            ])
            ->assertUnprocessable();

        foreach ($invalid->json('errors') as $messages) {
            foreach ($messages as $message) {
                $this->assertStringNotContainsString('validation.', $message);
                $this->assertStringNotContainsString('admin.', $message);
            }
        }

        $category = $this->createCategory(['slug' => 'stale-copy', 'lock_version' => 1]);
        $this->actingAs($admin)
            ->withSession(['locale' => 'id'])
            ->patchJson("/admin/catalog/categories/{$category->slug}", [
                ...$this->validUpdatePayload($category),
                'version' => 0,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'version' => 'Kategori katalog ini telah berubah. Muat ulang halaman lalu coba lagi.',
            ]);
    }

    public function test_only_an_empty_never_published_draft_can_be_hard_deleted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $draft = $this->createCategory(['image_path' => $this->managedPath('draft')]);
        Storage::disk('public')->put($draft->image_path, 'draft image');

        $this->actingAs($admin)->delete("/admin/catalog/categories/{$draft->slug}", [
            'version' => 0,
        ])->assertStatus(303);

        $this->assertNull($draft->fresh());
        Storage::disk('public')->assertMissing($draft->image_path);

        $published = $this->createCategory([
            'slug' => 'published-delete',
            'image_path' => '/img/legacy-category.jpg',
            'published_at' => now(),
            'is_active' => false,
        ]);
        $this->actingAs($admin)->deleteJson("/admin/catalog/categories/{$published->slug}", [
            'version' => 0,
        ])->assertUnprocessable();

        $nonEmpty = $this->createCategory(['slug' => 'non-empty-delete']);
        $this->createService($nonEmpty);
        $this->actingAs($admin)->deleteJson("/admin/catalog/categories/{$nonEmpty->slug}", [
            'version' => 0,
        ])->assertUnprocessable();

        $this->assertNotNull($published->fresh());
        $this->assertNotNull($nonEmpty->fresh());
    }

    public function test_failed_create_compensates_the_new_upload_without_leaking_database_errors(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        ServiceCategory::creating(function (): never {
            throw new RuntimeException('private database failure');
        });

        try {
            $this->actingAs($admin)->post('/admin/catalog/categories', [
                ...$this->validCreatePayload(),
                'image' => self::fakeImage('category.jpg', 600, 400),
            ])->assertServerError()
                ->assertDontSee('private database failure');
        } finally {
            ServiceCategory::flushEventListeners();
        }

        $this->assertDatabaseCount('service_categories', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('catalog/categories'));
    }

    public function test_replacement_and_removal_clean_managed_images_after_success_but_protect_legacy_assets(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $managed = $this->managedPath('managed');
        Storage::disk('public')->put($managed, 'managed old image');
        $category = $this->createCategory(['image_path' => $managed]);

        $this->actingAs($admin)->patch("/admin/catalog/categories/{$category->slug}", [
            ...$this->validUpdatePayload($category),
            'image' => self::fakeImage('replacement.jpg', 600, 400),
        ])->assertStatus(303);

        Storage::disk('public')->assertMissing($managed);
        Storage::disk('public')->assertExists($category->fresh()->image_path);

        $legacy = $this->createCategory([
            'slug' => 'legacy-image',
            'image_path' => '/img/category-legacy.jpg',
        ]);
        Storage::disk('public')->put('img/category-legacy.jpg', 'legacy image');

        $this->actingAs($admin)->patch("/admin/catalog/categories/{$legacy->slug}", [
            ...$this->validUpdatePayload($legacy),
            'removeImage' => true,
        ])->assertStatus(303);

        $this->assertNull($legacy->fresh()->image_path);
        Storage::disk('public')->assertExists('img/category-legacy.jpg');
    }

    public function test_failed_update_keeps_the_old_image_and_compensates_the_replacement(): void
    {
        Storage::fake('public');
        $oldPath = $this->managedPath('old-file');
        Storage::disk('public')->put($oldPath, 'old image');
        $category = $this->createCategory(['image_path' => $oldPath]);
        ServiceCategory::updating(function (): never {
            throw new RuntimeException('update failed');
        });

        try {
            app(SaveCatalogCategory::class)->update(
                $category,
                0,
                new CatalogCategoryData(
                    slug: $category->slug,
                    nameId: $category->name_id,
                    nameEn: $category->name_en,
                    displayOrder: $category->display_order,
                    image: self::fakeImage('replacement.png', 600, 400),
                    removeImage: false,
                ),
            );
            $this->fail('The failed database update should escape after compensation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('update failed', $exception->getMessage());
        } finally {
            ServiceCategory::flushEventListeners();
        }

        $this->assertSame($oldPath, $category->fresh()->image_path);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles('catalog/categories'));
    }

    public function test_policy_authorization_runs_for_category_writes_after_outer_identity_guards(): void
    {
        $category = $this->createCategory();
        $payload = $this->validUpdatePayload($category);

        foreach ([$category->slug, 'missing-category'] as $slug) {
            $this->patch("/admin/catalog/categories/{$slug}", $payload)
                ->assertRedirect(route('login', absolute: false));
        }

        $customer = User::factory()->create();
        foreach ([$category->slug, 'missing-category'] as $slug) {
            $this->actingAs($customer)
                ->patch("/admin/catalog/categories/{$slug}", $payload)
                ->assertForbidden();
        }

        $this->assertSame(0, $category->fresh()->lock_version);
    }

    public function test_category_write_routes_are_named_policy_protected_throttled_and_csrf_guarded(): void
    {
        $expected = [
            'admin.catalog.categories.store' => ['POST', '/admin/catalog/categories'],
            'admin.catalog.categories.update' => ['PATCH', '/admin/catalog/categories/{category}'],
            'admin.catalog.categories.publish' => ['POST', '/admin/catalog/categories/{category}/publish'],
            'admin.catalog.categories.archive' => ['POST', '/admin/catalog/categories/{category}/archive'],
            'admin.catalog.categories.restore' => ['POST', '/admin/catalog/categories/{category}/restore'],
            'admin.catalog.categories.destroy' => ['DELETE', '/admin/catalog/categories/{category}'],
        ];

        foreach ($expected as $name => [$method, $uri]) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertContains($method, $route->methods());
            $this->assertSame(ltrim($uri, '/'), $route->uri());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
            $this->assertContains('admin', $route->gatherMiddleware());
            $this->assertContains('throttle:catalog-write', $route->gatherMiddleware());
        }

        $this->assertContains(
            PreventRequestForgery::class,
            app(Kernel::class)->getMiddlewareGroups()['web'],
        );
        $this->assertNotContains(
            'throttle:catalog-write',
            app('router')->getRoutes()->getByName('admin.catalog.index')->gatherMiddleware(),
        );
    }

    /** @return array<string, mixed> */
    private function validCreatePayload(string $slug = 'body-care'): array
    {
        return [
            'slug' => $slug,
            'nameId' => 'Perawatan Tubuh',
            'nameEn' => 'Body Care',
            'displayOrder' => 2,
        ];
    }

    /** @return array<string, mixed> */
    private function validUpdatePayload(ServiceCategory $category): array
    {
        return [
            'version' => $category->lock_version,
            'slug' => $category->slug,
            'nameId' => $category->name_id,
            'nameEn' => $category->name_en,
            'displayOrder' => $category->display_order,
            'removeImage' => false,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createCategory(array $overrides = []): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'slug' => 'body-care',
            'name_id' => 'Perawatan Tubuh',
            'name_en' => 'Body Care',
            'image_path' => null,
            'published_at' => null,
            'lock_version' => 0,
            'is_active' => false,
            'display_order' => 1,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createService(ServiceCategory $category, array $overrides = []): Service
    {
        return Service::query()->create([
            'category_id' => $category->id,
            'slug' => 'body-treatment',
            'name_id' => 'Perawatan Badan',
            'name_en' => 'Body Treatment',
            'description_id' => null,
            'description_en' => null,
            'long_description_id' => null,
            'long_description_en' => null,
            'good_for_id' => null,
            'good_for_en' => null,
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => null,
            'gallery_paths' => [],
            'popularity' => 0,
            'is_vip' => false,
            'is_active' => false,
            'published_at' => null,
            'lock_version' => 0,
            'display_order' => 1,
            ...$overrides,
        ]);
    }

    private function managedPath(string $seed): string
    {
        $directory = substr(hash('sha256', "directory-{$seed}"), 0, 8).'-1234-4abc-8def-'.substr(hash('sha256', "directory-tail-{$seed}"), 0, 12);
        $filename = substr(hash('sha256', "filename-{$seed}"), 0, 8).'-1234-4abc-8def-'.substr(hash('sha256', "filename-tail-{$seed}"), 0, 12);

        return "catalog/categories/{$directory}/{$filename}.jpg";
    }

    private static function fakeImage(
        string $filename,
        int $width,
        int $height,
        int $sizeKilobytes = 0,
    ): UploadedFile {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $content = match ($extension) {
            'jpg', 'jpeg' => self::jpegHeader($width, $height),
            'png' => self::pngHeader($width, $height),
            'webp' => self::webpHeader($width, $height),
        };

        return UploadedFile::fake()->createWithContent($filename, $content)->size($sizeKilobytes);
    }

    private static function jpegHeader(int $width, int $height): string
    {
        return "\xFF\xD8"
            ."\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xC0\x00\x11\x08".pack('nn', $height, $width)
            ."\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00"
            ."\xFF\xD9";
    }

    private static function pngHeader(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1A\n".self::pngChunk('IHDR', $header).self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $content): string
    {
        return pack('N', strlen($content)).$type.$content.pack('N', crc32($type.$content));
    }

    private static function webpHeader(int $width, int $height): string
    {
        $dimensions = substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
        $chunk = 'VP8X'.pack('V', 10)."\x00\x00\x00\x00".$dimensions;

        return 'RIFF'.pack('V', 4 + strlen($chunk)).'WEBP'.$chunk;
    }
}
