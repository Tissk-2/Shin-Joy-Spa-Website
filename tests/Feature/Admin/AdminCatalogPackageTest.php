<?php

namespace Tests\Feature\Admin;

use App\Actions\Catalog\SaveCatalogPackage;
use App\Data\Admin\Catalog\CatalogChoiceData;
use App\Data\Admin\Catalog\CatalogPackageData;
use App\Enums\TreatmentMode;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\Catalog\ManagedCatalogPath;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminCatalogPackageTest extends TestCase
{
    use DatabaseMigrations;

    // Production break caught: creating a package must persist only a private version-zero draft aggregate.
    public function test_admin_creates_a_normalized_package_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category();

        $this->actingAs($admin)->post('/admin/catalog/packages', [
            'categoryId' => $category->id,
            'slug' => '  balinese-signature  ',
            'nameId' => '  Balinese Signature  ',
            'nameEn' => '  Balinese Signature  ',
            'descId' => '   ',
            'descEn' => null,
            'longId' => null,
            'longEn' => null,
            'benefitId' => null,
            'benefitEn' => null,
            'isVip' => '0',
            'displayOrder' => '2',
            'removeCover' => '0',
            'retainedGalleryIds' => [],
            'variants' => [],
            'extensions' => [],
        ])->assertStatus(303)
            ->assertRedirect(route('admin.catalog.index', absolute: false));

        $service = Service::query()->where('slug', 'balinese-signature')->sole();
        $this->assertSame($category->id, $service->category_id);
        $this->assertSame('Balinese Signature', $service->name_id);
        $this->assertNull($service->description_id);
        $this->assertFalse($service->is_vip);
        $this->assertFalse($service->is_active);
        $this->assertNull($service->published_at);
        $this->assertSame([], $service->gallery_paths);
        $this->assertSame(0, $service->lock_version);
    }

    // Production break caught: raw or misspelled aggregate fields must never cross the request allow-list.
    public function test_request_rejects_unknown_top_level_and_nested_keys_and_create_only_identifiers(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category();

        foreach ([
            'top-level' => [['published_at' => now()->toISOString()], 'published_at'],
            'version-on-create' => [['version' => 0], 'version'],
            'null-version-on-create' => [['version' => null], 'version'],
            'variant-id-on-create' => [['variants' => [$this->choice(['id' => 1])]], 'variants.0.id'],
            'null-variant-id-on-create' => [['variants' => [$this->choice(['id' => null])]], 'variants.0.id'],
            'variant-nested' => [['variants' => [$this->choice(['service_id' => 999])]], 'variants.0.service_id'],
            'extension-nested' => [['extensions' => [$this->choice(['price' => 1])]], 'extensions.0.price'],
            'boolean-text' => [['isVip' => 'false'], 'isVip'],
            'invalid-enum' => [['variants' => [$this->choice(['mode' => 'vip'])]], 'variants.0.mode'],
        ] as [$changes, $field]) {
            $this->actingAs($admin)
                ->postJson('/admin/catalog/packages', [...$this->payload($category), ...$changes])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('services', 0);
    }

    // Production break caught: nested identity ownership and active-choice cardinality must be enforced before writes.
    public function test_update_rejects_foreign_and_duplicate_children_codes_choices_and_extensions(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category();
        $service = $this->service($category);
        $owned = $this->variant($service, 'owned-regular-60', 60);
        $foreignService = $this->service($category, ['slug' => 'foreign-package']);
        $foreign = $this->variant($foreignService, 'foreign-regular-60', 60);

        $cases = [
            [['variants' => [$this->choice(['id' => $foreign->id])]], 'variants.0.id'],
            [['variants' => [
                $this->choice(['id' => $owned->id]),
                $this->choice(['id' => $owned->id, 'code' => 'duplicate-id', 'durationMinutes' => 90]),
            ]], 'variants.1.id'],
            [['variants' => [
                $this->choice(['id' => $owned->id, 'code' => 'owned-regular-60']),
                $this->choice(['code' => 'owned-regular-60', 'durationMinutes' => 90]),
            ]], 'variants.1.code'],
            [['variants' => [
                $this->choice(['id' => $owned->id]),
                $this->choice(['code' => 'second-regular-60']),
            ]], 'variants.1.durationMinutes'],
            [['extensions' => [
                $this->choice(['code' => 'extra-regular-30', 'durationMinutes' => 30]),
                $this->choice(['code' => 'extra-regular-45', 'durationMinutes' => 45]),
            ]], 'extensions.1.mode'],
        ];

        foreach ($cases as [$changes, $field]) {
            $this->actingAs($admin)
                ->patchJson("/admin/catalog/packages/{$service->slug}", [
                    ...$this->payload($category),
                    'version' => 0,
                    ...$changes,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame('owned-regular-60', $owned->fresh()->code);
        $this->assertSame(0, $service->fresh()->lock_version);
    }

    // Production break caught: a full form save must reconcile media and children as one versioned aggregate.
    public function test_update_reconciles_the_complete_normalized_aggregate_and_exposes_safe_edit_props(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $category = $this->category();
        $oldGallery = 'catalog/packages/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222.jpg';
        Storage::disk('public')->put($oldGallery, 'gallery');
        $service = $this->service($category, ['gallery_paths' => [$oldGallery]]);
        $variant = $this->variant($service, 'draft-regular-60', 60);

        $retentionId = app(ManagedCatalogPath::class)->retentionId($oldGallery);
        $this->actingAs($admin)->post("/admin/catalog/packages/{$service->slug}", [
            '_method' => 'PATCH',
            ...$this->payload($category, [
                'version' => 0,
                'nameId' => '  Paket Baru  ',
                'isVip' => '1',
                'displayOrder' => '4',
                'cover' => self::fakeImage('cover.webp', 600, 400),
                'retainedGalleryIds' => [$retentionId],
                'galleryUploads' => [self::fakeImage('detail.png', 800, 500)],
                'variants' => [$this->choice([
                    'id' => $variant->id,
                    'code' => 'updated-regular-60',
                    'priceIdr' => '225000',
                ])],
                'extensions' => [$this->choice([
                    'code' => 'extra-regular-30',
                    'durationMinutes' => '30',
                    'priceIdr' => '50000',
                ])],
            ]),
        ])->assertStatus(303);

        $updated = $service->fresh();
        $this->assertSame('Paket Baru', $updated->name_id);
        $this->assertTrue($updated->is_vip);
        $this->assertSame(4, $updated->display_order);
        $this->assertSame(1, $updated->lock_version);
        $this->assertCount(2, $updated->gallery_paths);
        $this->assertSame($oldGallery, $updated->gallery_paths[0]);
        Storage::disk('public')->assertExists([$updated->image_path, ...$updated->gallery_paths]);
        $this->assertDatabaseHas('service_variants', ['id' => $variant->id, 'code' => 'updated-regular-60', 'price_idr' => 225000]);
        $this->assertDatabaseHas('service_extensions', ['service_id' => $service->id, 'code' => 'extra-regular-30']);

        $response = $this->actingAs($admin)->get("/admin/catalog/packages/{$updated->slug}/edit");
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/packages/Form', false)
            ->has('categories')
            ->where('package.version', 1)
            ->where('package.status', 'draft')
            ->where('package.gallery.0.retentionId', $retentionId));

        $serialized = json_encode($response->inertiaProps('package'), JSON_THROW_ON_ERROR);
        foreach (['image_path', 'gallery_paths', 'published_at', 'lock_version', 'bookings', 'customer', $oldGallery] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    // Production break caught: stale writes and published public identifiers must not be overwritten.
    public function test_update_rejects_stale_versions_and_published_slug_or_child_code_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category(['published_at' => now(), 'is_active' => true, 'image_path' => '/img/category.jpg']);
        $service = $this->service($category, [
            'published_at' => now(),
            'is_active' => false,
            'image_path' => '/img/package.jpg',
            'description_id' => 'Deskripsi',
            'description_en' => 'Description',
            'long_description_id' => 'Panjang',
            'long_description_en' => 'Long',
        ]);
        $variant = $this->variant($service, 'stable-regular-60', 60, ['published_at' => now()]);

        $this->actingAs($admin)->patchJson("/admin/catalog/packages/{$service->slug}", [
            ...$this->payload($category),
            'version' => 9,
            'variants' => [$this->choice(['id' => $variant->id, 'code' => $variant->code])],
        ])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->actingAs($admin)->patchJson("/admin/catalog/packages/{$service->slug}", [
            ...$this->payload($category, ['slug' => 'changed-slug']),
            'version' => 0,
            'variants' => [$this->choice(['id' => $variant->id, 'code' => 'changed-code'])],
        ])->assertUnprocessable()->assertJsonValidationErrors(['slug', 'variants.0.code']);

        $this->actingAs($admin)->patchJson("/admin/catalog/packages/{$service->slug}", [
            ...$this->payload($category),
            'version' => 0,
            'variants' => 'not-an-array',
        ])->assertUnprocessable()->assertJsonValidationErrors('variants');

        $this->assertSame('balinese-signature', $service->fresh()->slug);
        $this->assertSame('stable-regular-60', $variant->fresh()->code);
    }

    // Production break caught: a live package edit must not commit an incomplete public aggregate.
    public function test_active_published_package_edits_must_remain_publishable(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category(['published_at' => now(), 'is_active' => true, 'image_path' => '/img/category.jpg']);
        $service = $this->service($category, [
            'description_id' => 'Deskripsi', 'description_en' => 'Description',
            'long_description_id' => 'Panjang', 'long_description_en' => 'Long',
            'image_path' => '/img/package.jpg', 'published_at' => now(), 'is_active' => true,
        ]);
        $variant = $this->variant($service, 'stable-regular-60', 60, ['published_at' => now()]);

        $this->actingAs($admin)->patchJson("/admin/catalog/packages/{$service->slug}", [
            ...$this->payload($category, [
                'descId' => null,
                'descEn' => 'Description',
                'longId' => 'Panjang',
                'longEn' => 'Long',
                'variants' => [$this->choice(['id' => $variant->id, 'code' => $variant->code])],
            ]),
            'version' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('descId');

        $this->assertSame('Deskripsi', $service->fresh()->description_id);
        $this->assertSame(0, $service->fresh()->lock_version);
    }

    // Production break caught: omitted draft rows may be deleted, but published or booking-linked rows must be archived.
    public function test_omitted_children_follow_delete_or_archive_reference_rules_without_rewriting_snapshots(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category();
        $service = $this->service($category);
        $disposable = $this->variant($service, 'disposable-regular-30', 30);
        $published = $this->variant($service, 'published-regular-60', 60, ['published_at' => now()]);
        $referenced = $this->variant($service, 'referenced-regular-90', 90);
        $booking = Booking::factory()->create([
            'service_id' => $service->id,
            'service_variant_id' => $referenced->id,
            'base_choice_code' => 'immutable-snapshot-code',
            'base_price_idr' => 777000,
        ]);

        $this->actingAs($admin)->patch("/admin/catalog/packages/{$service->slug}", [
            ...$this->payload($category),
            'version' => 0,
        ])->assertStatus(303);

        $this->assertNull($disposable->fresh());
        $this->assertFalse($published->fresh()->is_active);
        $this->assertNotNull($published->fresh()->published_at);
        $this->assertFalse($referenced->fresh()->is_active);
        $this->assertSame('immutable-snapshot-code', $booking->fresh()->base_choice_code);
        $this->assertSame(777000, $booking->fresh()->base_price_idr);
    }

    // Production break caught: a surrounding rollback must restore DB references and remove every newly stored file.
    public function test_outer_rollback_compensates_new_cover_and_gallery_and_preserves_old_media(): void
    {
        Storage::fake('public');
        $oldCover = 'catalog/packages/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222.jpg';
        $oldGallery = 'catalog/packages/33333333-3333-4333-8333-333333333333/44444444-4444-4444-8444-444444444444.jpg';
        Storage::disk('public')->put($oldCover, 'cover');
        Storage::disk('public')->put($oldGallery, 'gallery');
        $category = $this->category();
        $service = $this->service($category, ['image_path' => $oldCover, 'gallery_paths' => [$oldGallery]]);
        $data = $this->packageData($category, cover: self::fakeImage('new-cover.jpg', 600, 400), gallery: [self::fakeImage('new-gallery.png', 600, 400)]);

        DB::beginTransaction();
        try {
            $updated = app(SaveCatalogPackage::class)->update($service, 0, $data);
            $newPaths = [$updated->image_path, ...$updated->gallery_paths];
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
        }

        $this->assertSame($oldCover, $service->fresh()->image_path);
        $this->assertSame([$oldGallery], $service->fresh()->gallery_paths);
        Storage::disk('public')->assertExists([$oldCover, $oldGallery]);
        Storage::disk('public')->assertMissing($newPaths);
    }

    // Production break caught: late unique races must point at the exact submitted slug or child-code index.
    public function test_database_collisions_are_reported_on_exact_aggregate_fields(): void
    {
        $category = $this->category();
        $this->service($category, ['slug' => 'occupied-slug']);

        try {
            app(SaveCatalogPackage::class)->create($this->packageData($category, slug: 'occupied-slug'));
            $this->fail('Expected slug collision validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['slug'], array_keys($exception->errors()));
        }

        $owner = $this->service($category, ['slug' => 'code-owner']);
        $this->variant($owner, 'occupied-variant-code', 60);
        $choices = [
            new CatalogChoiceData(null, 'free-variant-code', TreatmentMode::Regular, 30, 100000, true, 0),
            new CatalogChoiceData(null, 'occupied-variant-code', TreatmentMode::Regular, 90, 200000, true, 1),
        ];

        try {
            app(SaveCatalogPackage::class)->create($this->packageData($category, slug: 'new-package', variants: $choices));
            $this->fail('Expected child-code collision validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['variants.1.code'], array_keys($exception->errors()));
        }
    }

    // Production break caught: gallery references and the shared six-image boundary must reject forged or excess input.
    public function test_gallery_references_counts_and_image_boundaries_are_enforced(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $category = $this->category();
        $service = $this->service($category, ['gallery_paths' => ['/img/legacy.jpg']]);

        $this->actingAs($admin)->patchJson("/admin/catalog/packages/{$service->slug}", [
            ...$this->payload($category), 'version' => 0, 'retainedGalleryIds' => [str_repeat('a', 64)],
        ])->assertUnprocessable()->assertJsonValidationErrors('retainedGalleryIds');

        $this->actingAs($admin)->post('/admin/catalog/packages', [
            ...$this->payload($category, ['slug' => 'too-many-images']),
            'galleryUploads' => array_map(fn (int $index) => self::fakeImage("{$index}.jpg", 600, 400), range(1, 7)),
        ])->assertSessionHasErrors('galleryUploads');

        $this->actingAs($admin)->post('/admin/catalog/packages', [
            ...$this->payload($category, ['slug' => 'small-image']),
            'cover' => self::fakeImage('small.jpg', 599, 400),
        ])->assertSessionHasErrors('cover');
        $this->assertDatabaseMissing('services', ['slug' => 'too-many-images']);
        $this->assertDatabaseMissing('services', ['slug' => 'small-image']);
    }

    /** @param array<string, mixed> $overrides */
    private function category(array $overrides = []): ServiceCategory
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
    private function service(ServiceCategory $category, array $overrides = []): Service
    {
        return Service::query()->create([
            'category_id' => $category->id,
            'slug' => 'balinese-signature',
            'name_id' => 'Balinese Signature',
            'name_en' => 'Balinese Signature',
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

    /** @param array<string, mixed> $overrides */
    private function variant(Service $service, string $code, int $duration, array $overrides = []): ServiceVariant
    {
        return ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => $code,
            'treatment_mode' => 'regular',
            'duration_minutes' => $duration,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 0,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(ServiceCategory $category, array $overrides = []): array
    {
        return [
            'categoryId' => $category->id,
            'slug' => 'balinese-signature',
            'nameId' => 'Balinese Signature',
            'nameEn' => 'Balinese Signature',
            'descId' => null,
            'descEn' => null,
            'longId' => null,
            'longEn' => null,
            'benefitId' => null,
            'benefitEn' => null,
            'isVip' => false,
            'displayOrder' => 1,
            'removeCover' => false,
            'retainedGalleryIds' => [],
            'variants' => [],
            'extensions' => [],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function choice(array $overrides = []): array
    {
        return [
            'code' => 'draft-regular-60',
            'mode' => 'regular',
            'durationMinutes' => 60,
            'priceIdr' => 180000,
            'isActive' => true,
            'displayOrder' => 0,
            ...$overrides,
        ];
    }

    /**
     * @param  list<UploadedFile>  $gallery
     * @param  list<CatalogChoiceData>|null  $variants
     */
    private function packageData(
        ServiceCategory $category,
        string $slug = 'data-package',
        ?UploadedFile $cover = null,
        array $gallery = [],
        ?array $variants = null,
    ): CatalogPackageData {
        return new CatalogPackageData(
            categoryId: $category->id,
            slug: $slug,
            nameId: 'Paket Data',
            nameEn: 'Data Package',
            descId: null,
            descEn: null,
            longId: null,
            longEn: null,
            benefitId: null,
            benefitEn: null,
            isVip: false,
            displayOrder: 0,
            cover: $cover,
            removeCover: false,
            retainedGalleryIds: [],
            galleryUploads: $gallery,
            variants: $variants ?? [],
            extensions: [],
        );
    }

    private static function fakeImage(string $filename, int $width, int $height): UploadedFile
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $content = match ($extension) {
            'png' => self::png($width, $height),
            'webp' => self::webp($width, $height),
            default => "\xFF\xD8\xFF\xC0\x00\x11\x08".pack('nn', $height, $width)."\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00\xFF\xD9",
        };

        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    private static function png(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $chunk = static fn (string $type, string $body): string => pack('N', strlen($body)).$type.$body.pack('N', crc32($type.$body));

        return "\x89PNG\r\n\x1A\n".$chunk('IHDR', $header).$chunk('IEND', '');
    }

    private static function webp(int $width, int $height): string
    {
        $dimensions = substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
        $chunk = 'VP8X'.pack('V', 10)."\x00\x00\x00\x00".$dimensions;

        return 'RIFF'.pack('V', 4 + strlen($chunk)).'WEBP'.$chunk;
    }
}
