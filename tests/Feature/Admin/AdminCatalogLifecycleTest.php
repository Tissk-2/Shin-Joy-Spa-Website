<?php

namespace Tests\Feature\Admin;

use App\Actions\Catalog\DeleteCatalogDraft;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCatalogLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // Production break caught: first publish must atomically make a complete package and its active child public.
    public function test_complete_draft_can_be_first_published_with_its_active_children(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->publishedCategory();
        $service = $this->draftPackage($category);
        $variant = ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'balinese-regular-60',
            'treatment_mode' => 'regular',
            'duration_minutes' => 60,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);

        $this->actingAs($admin)
            ->post("/admin/catalog/packages/{$service->slug}/publish", ['version' => 0])
            ->assertStatus(303);

        $this->assertTrue($service->fresh()->is_active);
        $this->assertNotNull($service->fresh()->published_at);
        $this->assertSame(1, $service->fresh()->lock_version);
        $this->assertNotNull($variant->fresh()->published_at);
    }

    // Production break caught: publish and restore are different intents and publication completeness is fail-closed.
    public function test_publish_rejects_incomplete_or_wrong_state_and_restore_rejects_a_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->publishedCategory();
        $incomplete = $this->draftPackage($category, ['image_path' => null]);

        $this->actingAs($admin)
            ->postJson("/admin/catalog/packages/{$incomplete->slug}/publish", ['version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cover']);

        $this->actingAs($admin)
            ->postJson("/admin/catalog/packages/{$incomplete->slug}/restore", ['version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertNull($incomplete->fresh()->published_at);
        $this->assertSame(0, $incomplete->fresh()->lock_version);
    }

    // Production break caught: archive must change only parent visibility and restore must preserve publication identity.
    public function test_archive_and_restore_preserve_timestamps_and_child_state(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->publishedCategory();
        $publishedAt = now()->subDay()->startOfSecond();
        $service = $this->draftPackage($category, [
            'published_at' => $publishedAt,
            'is_active' => true,
        ]);
        $variant = ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'balinese-regular-60',
            'treatment_mode' => 'regular',
            'duration_minutes' => 60,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => $publishedAt,
            'display_order' => 0,
        ]);

        $this->actingAs($admin)
            ->post("/admin/catalog/packages/{$service->slug}/archive", ['version' => 0])
            ->assertStatus(303);

        $this->assertFalse($service->fresh()->is_active);
        $this->assertTrue($service->fresh()->published_at->equalTo($publishedAt));
        $this->assertTrue($variant->fresh()->is_active);
        $this->assertTrue($variant->fresh()->published_at->equalTo($publishedAt));

        $this->actingAs($admin)
            ->post("/admin/catalog/packages/{$service->slug}/restore", ['version' => 1])
            ->assertStatus(303);

        $this->assertTrue($service->fresh()->is_active);
        $this->assertTrue($service->fresh()->published_at->equalTo($publishedAt));
        $this->assertSame(2, $service->fresh()->lock_version);
    }

    // Production break caught: package deletion must never sever published or historical booking references.
    public function test_only_unpublished_unreferenced_drafts_can_be_deleted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $category = $this->publishedCategory();
        $managed = 'catalog/packages/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222.jpg';
        Storage::disk('public')->put($managed, 'cover');
        $eligible = $this->draftPackage($category, ['slug' => 'eligible', 'image_path' => $managed]);

        $this->actingAs($admin)
            ->delete("/admin/catalog/packages/{$eligible->slug}", ['version' => 0])
            ->assertStatus(303);
        $this->assertNull($eligible->fresh());
        Storage::disk('public')->assertMissing($managed);

        $published = $this->draftPackage($category, ['slug' => 'published', 'published_at' => now(), 'is_active' => false]);
        $this->actingAs($admin)
            ->deleteJson("/admin/catalog/packages/{$published->slug}", ['version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $referenced = $this->draftPackage($category, ['slug' => 'referenced']);
        $child = ServiceExtension::query()->create([
            'service_id' => $referenced->id,
            'code' => 'referenced-extra-30',
            'treatment_mode' => 'regular',
            'duration_minutes' => 30,
            'price_idr' => 50000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);
        Booking::factory()->create(['service_id' => null, 'service_extension_id' => $child->id]);

        $this->actingAs($admin)
            ->deleteJson("/admin/catalog/packages/{$referenced->slug}", ['version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertNotNull($referenced->fresh());
        $this->assertNotNull($child->fresh());
    }

    // Production break caught: deletion must lock every child row before checking for booking references.
    public function test_draft_deletion_locks_package_and_children_before_the_reference_check(): void
    {
        $service = $this->draftPackage($this->publishedCategory(), ['slug' => 'lock-delete']);
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'lock-delete-regular-60',
            'treatment_mode' => 'regular',
            'duration_minutes' => 60,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);
        ServiceExtension::query()->create([
            'service_id' => $service->id,
            'code' => 'lock-delete-extra-30',
            'treatment_mode' => 'regular',
            'duration_minutes' => 30,
            'price_idr' => 50000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);

        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        $outerTransactionLevel = $connection->transactionLevel();
        // SQLite omits FOR UPDATE SQL; observe the real builder lock intent before compilation.
        $recordingGrammar = new class($connection) extends SQLiteGrammar
        {
            /** @var list<array{from: string|null, lock: string|bool|null, transaction: int}> */
            public array $compiledSelects = [];

            public ?int $selectCountAtDelete = null;

            public function compileSelect(Builder $query)
            {
                $this->compiledSelects[] = [
                    'from' => $query->from,
                    'lock' => $query->lock,
                    'transaction' => $query->getConnection()->transactionLevel(),
                ];

                return parent::compileSelect($query);
            }

            public function compileDelete(Builder $query)
            {
                $this->selectCountAtDelete = count($this->compiledSelects);

                return parent::compileDelete($query);
            }
        };
        $connection->setQueryGrammar($recordingGrammar);

        try {
            app(DeleteCatalogDraft::class)($service, 0);
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }

        $lockedTables = array_column(array_values(array_filter(
            $recordingGrammar->compiledSelects,
            fn (array $select): bool => $select['lock'] === true,
        )), 'from');
        $this->assertSame(
            ['services', 'service_variants', 'service_extensions'],
            array_values(array_intersect($lockedTables, ['services', 'service_variants', 'service_extensions'])),
        );

        $queriedTables = array_column($recordingGrammar->compiledSelects, 'from');
        $bookingQuery = array_search('bookings', $queriedTables, true);
        $this->assertIsInt($bookingQuery);
        $this->assertLessThan($bookingQuery, array_search('service_variants', $queriedTables, true));
        $this->assertLessThan($bookingQuery, array_search('service_extensions', $queriedTables, true));
        $this->assertGreaterThan($bookingQuery, $recordingGrammar->selectCountAtDelete);
        foreach ($recordingGrammar->compiledSelects as $select) {
            $this->assertSame($outerTransactionLevel + 1, $select['transaction']);
        }
        $this->assertNull($service->fresh());
    }

    // Production break caught: every package mutation route must retain policy authorization and catalog throttling.
    public function test_package_routes_keep_read_and_write_security_boundaries(): void
    {
        foreach (['update', 'publish', 'archive', 'restore', 'destroy'] as $action) {
            $route = app('router')->getRoutes()->getByName("admin.catalog.packages.{$action}");
            $this->assertNotNull($route);
            $this->assertContains('throttle:catalog-write', $route->gatherMiddleware());
        }

        foreach (['edit'] as $action) {
            $route = app('router')->getRoutes()->getByName("admin.catalog.packages.{$action}");
            $this->assertNotNull($route);
            $this->assertNotContains('throttle:catalog-write', $route->gatherMiddleware());
        }

        $customer = User::factory()->create();
        $service = $this->draftPackage($this->publishedCategory());
        $this->actingAs($customer)->get("/admin/catalog/packages/{$service->slug}/edit")->assertForbidden();
        $this->actingAs($customer)->patch("/admin/catalog/packages/{$service->slug}", [])->assertForbidden();
    }

    // Production break caught: a package cannot publish or restore beneath a draft or archived category.
    public function test_publish_and_restore_require_an_active_published_category(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([
            ['category' => ['published_at' => null, 'is_active' => false], 'package' => ['published_at' => null]],
            ['category' => ['published_at' => now(), 'is_active' => false], 'package' => ['published_at' => now()]],
        ] as $index => $state) {
            $category = $this->publishedCategory();
            $category->update(['slug' => "category-state-{$index}", ...$state['category']]);
            $service = $this->draftPackage($category, [
                'slug' => "category-state-{$index}",
                'published_at' => $state['package']['published_at'],
                'is_active' => false,
            ]);
            ServiceVariant::query()->create([
                'service_id' => $service->id,
                'code' => "category-state-{$index}-regular-60",
                'treatment_mode' => 'regular',
                'duration_minutes' => 60,
                'price_idr' => 180000,
                'is_active' => true,
                'published_at' => $state['package']['published_at'],
                'display_order' => 0,
            ]);
            $intent = $index === 0 ? 'publish' : 'restore';

            $this->actingAs($admin)
                ->postJson("/admin/catalog/packages/{$service->slug}/{$intent}", ['version' => 0])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('categoryId');

            $this->assertFalse($service->fresh()->is_active);
            $this->assertSame(0, $service->fresh()->lock_version);
        }
    }

    // Production break caught: publishing must reject a variant duration every booking would refuse.
    public function test_publish_rejects_a_variant_longer_than_the_booking_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $service = $this->draftPackage($this->publishedCategory());
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'too-long-regular-481',
            'treatment_mode' => 'regular',
            'duration_minutes' => 481,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/catalog/packages/{$service->slug}/publish", ['version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.durationMinutes');

        $this->assertNull($service->fresh()->published_at);
        $this->assertSame(0, $service->fresh()->lock_version);
    }

    // Production break caught: publishing must reject a choice code longer than every booking accepts.
    public function test_publish_rejects_a_choice_code_longer_than_the_booking_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $service = $this->draftPackage($this->publishedCategory());
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => str_repeat('c', 81),
            'treatment_mode' => 'regular',
            'duration_minutes' => 60,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/catalog/packages/{$service->slug}/publish", ['version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.code');

        $this->assertNull($service->fresh()->published_at);
        $this->assertSame(0, $service->fresh()->lock_version);
    }

    // Production break caught: a same-mode variant plus extension that outlasts the service day must not publish.
    public function test_publish_rejects_a_same_mode_variant_and_extension_over_the_service_day(): void
    {
        $admin = User::factory()->admin()->create();
        $service = $this->draftPackage($this->publishedCategory());
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'service-day-regular-480',
            'treatment_mode' => 'regular',
            'duration_minutes' => 480,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);
        ServiceExtension::query()->create([
            'service_id' => $service->id,
            'code' => 'service-day-extra-300',
            'treatment_mode' => 'regular',
            'duration_minutes' => 300,
            'price_idr' => 50000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/catalog/packages/{$service->slug}/publish", ['version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extensions.0.durationMinutes');

        $this->assertNull($service->fresh()->published_at);
        $this->assertSame(0, $service->fresh()->lock_version);
    }

    // Guard: a same-mode variant plus extension that fits the service day exactly must publish.
    public function test_publish_accepts_a_same_mode_total_exactly_at_the_service_day(): void
    {
        $admin = User::factory()->admin()->create();
        $service = $this->draftPackage($this->publishedCategory());
        $variant = ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'service-day-regular-480',
            'treatment_mode' => 'regular',
            'duration_minutes' => 480,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);
        $extension = ServiceExtension::query()->create([
            'service_id' => $service->id,
            'code' => 'service-day-extra-240',
            'treatment_mode' => 'regular',
            'duration_minutes' => 240,
            'price_idr' => 50000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);

        $this->actingAs($admin)
            ->post("/admin/catalog/packages/{$service->slug}/publish", ['version' => 0])
            ->assertStatus(303);

        $this->assertNotNull($service->fresh()->published_at);
        $this->assertNotNull($variant->fresh()->published_at);
        $this->assertNotNull($extension->fresh()->published_at);
    }

    // Guard: the total check only pairs an extension with a variant of the same mode.
    public function test_publish_ignores_the_total_check_for_an_extension_in_a_different_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $service = $this->draftPackage($this->publishedCategory());
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'cross-mode-regular-480',
            'treatment_mode' => 'regular',
            'duration_minutes' => 480,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);
        ServiceExtension::query()->create([
            'service_id' => $service->id,
            'code' => 'cross-mode-extra-300',
            'treatment_mode' => 'double_therapist',
            'duration_minutes' => 300,
            'price_idr' => 50000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
        ]);

        $this->actingAs($admin)
            ->post("/admin/catalog/packages/{$service->slug}/publish", ['version' => 0])
            ->assertStatus(303);

        $this->assertNotNull($service->fresh()->published_at);
    }

    // Production break caught: restoring must revalidate durations before reactivating a published package.
    public function test_restore_rejects_a_variant_longer_than_the_booking_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $service = $this->draftPackage($this->publishedCategory(), [
            'published_at' => now(),
            'is_active' => false,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'too-long-regular-481',
            'treatment_mode' => 'regular',
            'duration_minutes' => 481,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => now(),
            'display_order' => 0,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/catalog/packages/{$service->slug}/restore", ['version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.durationMinutes');

        $this->assertFalse($service->fresh()->is_active);
        $this->assertSame(0, $service->fresh()->lock_version);
    }

    // Production break caught: an inactive published choice cannot be edited or deleted,
    // so booking limits must not block restore on a choice customers can never pick.
    public function test_restore_ignores_booking_limits_on_an_inactive_legacy_choice(): void
    {
        $admin = User::factory()->admin()->create();
        $service = $this->draftPackage($this->publishedCategory(), [
            'published_at' => now(),
            'is_active' => false,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'regular-90',
            'treatment_mode' => 'regular',
            'duration_minutes' => 90,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => now(),
            'display_order' => 0,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => str_repeat('a', 100),
            'treatment_mode' => 'regular',
            'duration_minutes' => 600,
            'price_idr' => 180000,
            'is_active' => false,
            'published_at' => now(),
            'display_order' => 1,
        ]);

        $this->actingAs($admin)
            ->post("/admin/catalog/packages/{$service->slug}/restore", ['version' => 0])
            ->assertStatus(303)
            ->assertSessionHasNoErrors();

        $this->assertTrue($service->fresh()->is_active);
    }

    private function publishedCategory(): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'slug' => 'body-care',
            'name_id' => 'Perawatan Tubuh',
            'name_en' => 'Body Care',
            'image_path' => '/img/category.jpg',
            'published_at' => now(),
            'lock_version' => 0,
            'is_active' => true,
            'display_order' => 1,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function draftPackage(ServiceCategory $category, array $overrides = []): Service
    {
        return Service::query()->create([
            'category_id' => $category->id,
            'slug' => 'balinese-signature',
            'name_id' => 'Balinese Signature',
            'name_en' => 'Balinese Signature',
            'description_id' => 'Pijat tradisional Bali.',
            'description_en' => 'Traditional Balinese massage.',
            'long_description_id' => 'Perawatan lengkap untuk relaksasi.',
            'long_description_en' => 'A complete treatment for relaxation.',
            'good_for_id' => null,
            'good_for_en' => null,
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => '/img/package.jpg',
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
}
