<?php

namespace Tests\Feature\Admin;

use App\Actions\Catalog\SaveCatalogCategory;
use App\Data\Admin\Catalog\CatalogCategoryData;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCatalogThrottleTest extends TestCase
{
    use DatabaseMigrations;

    public function test_publish_and_restore_reject_the_wrong_lifecycle_intent_with_translated_field_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = $this->category('draft-transition', [
            'published_at' => null,
            'is_active' => false,
        ]);
        $archivedAt = now()->subDay()->startOfSecond();
        $archived = $this->category('archived-transition', [
            'published_at' => $archivedAt,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->withSession(['locale' => 'id'])
            ->postJson("/admin/catalog/categories/{$draft->slug}/restore", ['version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'status yang dipilih tidak valid.']);

        $this->actingAs($admin)
            ->withSession(['locale' => 'id'])
            ->postJson("/admin/catalog/categories/{$archived->slug}/publish", ['version' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'status yang dipilih tidak valid.']);

        $this->assertNull($draft->fresh()->published_at);
        $this->assertFalse($draft->fresh()->is_active);
        $this->assertTrue($archived->fresh()->published_at->equalTo($archivedAt));
        $this->assertFalse($archived->fresh()->is_active);
        $this->assertSame(0, $draft->fresh()->lock_version);
        $this->assertSame(0, $archived->fresh()->lock_version);
    }

    public function test_outer_rollback_preserves_old_image_and_database_reference_and_compensates_replacement(): void
    {
        Storage::fake('public');
        $oldPath = $this->managedPath('rollback');
        Storage::disk('public')->put($oldPath, 'old image');
        $category = $this->category('rollback-category', [
            'image_path' => $oldPath,
            'published_at' => null,
            'is_active' => false,
        ]);

        DB::beginTransaction();

        try {
            $updated = app(SaveCatalogCategory::class)->update(
                $category,
                0,
                $this->replacementData($category),
            );
            $newPath = $updated->image_path;

            Storage::disk('public')->assertExists($oldPath);
            Storage::disk('public')->assertExists($newPath);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
        }

        $rolledBack = $category->fresh();
        $this->assertSame($oldPath, $rolledBack->image_path);
        $this->assertSame(0, $rolledBack->lock_version);
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_old_image_cleanup_waits_for_the_real_outer_commit(): void
    {
        Storage::fake('public');
        $oldPath = $this->managedPath('commit');
        Storage::disk('public')->put($oldPath, 'old image');
        $category = $this->category('commit-category', [
            'image_path' => $oldPath,
            'published_at' => null,
            'is_active' => false,
        ]);

        DB::beginTransaction();

        try {
            $updated = app(SaveCatalogCategory::class)->update(
                $category,
                0,
                $this->replacementData($category),
            );
            $newPath = $updated->image_path;

            Storage::disk('public')->assertExists($oldPath);
            Storage::disk('public')->assertExists($newPath);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
        }

        $committed = $category->fresh();
        $this->assertSame($newPath, $committed->image_path);
        $this->assertSame(1, $committed->lock_version);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_outer_rollback_of_draft_delete_keeps_its_managed_image_and_row(): void
    {
        Storage::fake('public');
        $oldPath = $this->managedPath('delete');
        Storage::disk('public')->put($oldPath, 'draft image');
        $category = $this->category('delete-rollback', [
            'image_path' => $oldPath,
            'published_at' => null,
            'is_active' => false,
        ]);
        $admin = User::factory()->admin()->create();

        DB::beginTransaction();

        try {
            $this->actingAs($admin)
                ->delete("/admin/catalog/categories/{$category->slug}", ['version' => 0])
                ->assertStatus(303);

            $this->assertNull($category->fresh());
            Storage::disk('public')->assertExists($oldPath);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
        }

        $this->assertSame($oldPath, $category->fresh()->image_path);
        Storage::disk('public')->assertExists($oldPath);
    }

    public function test_twenty_first_write_for_one_admin_and_ip_gets_the_standard_429_response(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category('minute-limit');
        $url = "/admin/catalog/categories/{$category->slug}/archive";

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->actingAs($admin)
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
                ->postJson($url, [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('version');
        }

        $response = $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->postJson($url, [])
            ->assertTooManyRequests();

        $response->assertHeader('Retry-After');
        $this->assertSame(0, $category->fresh()->lock_version);
    }

    public function test_one_hundred_and_first_ip_write_is_hour_limited_without_hitting_an_admin_minute_bucket(): void
    {
        $admins = User::factory()->count(6)->admin()->create();
        $category = $this->category('hour-limit');
        $url = "/admin/catalog/categories/{$category->slug}/archive";
        $ipAddress = '203.0.113.101';

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $this->actingAs($admins[$attempt % 5])
                ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                ->postJson($url, [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('version');
        }

        $response = $this->actingAs($admins[5])
            ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson($url, [])
            ->assertTooManyRequests();

        $response->assertHeader('Retry-After');
        $this->assertSame(0, $category->fresh()->lock_version);
    }

    /** @param array<string, mixed> $overrides */
    private function category(string $slug, array $overrides = []): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'slug' => $slug,
            'name_id' => 'Kategori',
            'name_en' => 'Category',
            'image_path' => '/img/category.jpg',
            'published_at' => now(),
            'lock_version' => 0,
            'is_active' => true,
            'display_order' => 1,
            ...$overrides,
        ]);
    }

    private function replacementData(ServiceCategory $category): CatalogCategoryData
    {
        return new CatalogCategoryData(
            slug: $category->slug,
            nameId: $category->name_id,
            nameEn: $category->name_en,
            displayOrder: $category->display_order,
            image: self::fakeImage('replacement.jpg', 600, 400),
            removeImage: false,
        );
    }

    private function managedPath(string $seed): string
    {
        [$directory, $filename] = match ($seed) {
            'rollback' => [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
            ],
            'commit' => [
                '33333333-3333-4333-8333-333333333333',
                '44444444-4444-4444-8444-444444444444',
            ],
            'delete' => [
                '55555555-5555-4555-8555-555555555555',
                '66666666-6666-4666-8666-666666666666',
            ],
        };

        return "catalog/categories/{$directory}/{$filename}.jpg";
    }

    private static function fakeImage(string $filename, int $width, int $height): UploadedFile
    {
        $content = "\xFF\xD8"
            ."\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xC0\x00\x11\x08".pack('nn', $height, $width)
            ."\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00"
            ."\xFF\xD9";

        return UploadedFile::fake()->createWithContent($filename, $content);
    }
}
