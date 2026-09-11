<?php

namespace Tests\Feature\Admin;

use App\Support\Catalog\CatalogImageStorage;
use App\Support\Catalog\ManagedCatalogPath;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CatalogImageStorageTest extends TestCase
{
    public function test_storage_uses_random_public_disk_paths_without_client_filenames(): void
    {
        Storage::fake('public');
        $storage = app(CatalogImageStorage::class);

        $firstPath = $storage->store(
            self::fakeImage('client-name.jpg', 1200, 800, 500),
            'packages',
        );
        $secondPath = $storage->store(
            self::fakeImage('client-name.jpg', 1200, 800, 500),
            'packages',
        );

        $pattern = '#\Acatalog/packages/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\.jpg\z#';
        $this->assertMatchesRegularExpression($pattern, $firstPath);
        $this->assertMatchesRegularExpression($pattern, $secondPath);
        $this->assertNotSame($firstPath, $secondPath);
        $this->assertStringNotContainsString('client-name', $firstPath);
        Storage::disk('public')->assertExists([$firstPath, $secondPath]);
    }

    #[DataProvider('supportedImageProvider')]
    public function test_jpeg_png_and_webp_images_are_stored_in_each_catalog_scope(
        string $filename,
        string $scope,
        string $extension,
    ): void {
        Storage::fake('public');

        $path = app(CatalogImageStorage::class)->store(
            self::fakeImage($filename, 600, 400, 5120),
            $scope,
        );

        $this->assertMatchesRegularExpression(
            "#\\Acatalog/{$scope}/[0-9a-f-]+/[0-9a-f-]+\\.{$extension}\\z#",
            $path,
        );
        Storage::disk('public')->assertExists($path);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function supportedImageProvider(): iterable
    {
        yield 'JPEG category' => ['category.jpg', 'categories', 'jpg'];
        yield 'PNG package' => ['cover.png', 'packages', 'png'];
        yield 'WebP package' => ['gallery.webp', 'packages', 'webp'];
    }

    public function test_invalid_scope_is_reported_as_a_validation_failure(): void
    {
        Storage::fake('public');

        $this->assertValidationFailure(
            'scope',
            fn (): string => app(CatalogImageStorage::class)->store(
                self::fakeImage('valid.jpg', 600, 400),
                'avatars',
            ),
        );

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[DataProvider('invalidImageProvider')]
    public function test_unsupported_or_spoofed_images_are_reported_as_validation_failures(
        string $filename,
        string $content,
    ): void {
        Storage::fake('public');
        $file = UploadedFile::fake()->createWithContent($filename, $content);

        $this->assertValidationFailure(
            'image',
            fn (): string => app(CatalogImageStorage::class)->store($file, 'packages'),
        );

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function invalidImageProvider(): iterable
    {
        yield 'GIF image' => ['animation.gif', 'GIF89a'.pack('vv', 600, 400)];
        yield 'text with JPEG extension' => ['script.jpg', 'not an image'];
    }

    #[DataProvider('invalidImageBoundaryProvider')]
    public function test_image_size_and_dimension_limits_are_reported_as_validation_failures(
        int $width,
        int $height,
        int $sizeKilobytes,
    ): void {
        Storage::fake('public');

        $this->assertValidationFailure(
            'image',
            fn (): string => app(CatalogImageStorage::class)->store(
                self::fakeImage('boundary.png', $width, $height, $sizeKilobytes),
                'categories',
            ),
        );
    }

    /** @return iterable<string, array{0: int, 1: int, 2: int}> */
    public static function invalidImageBoundaryProvider(): iterable
    {
        yield 'width below minimum' => [599, 400, 100];
        yield 'height below minimum' => [600, 399, 100];
        yield 'width above maximum' => [6001, 400, 100];
        yield 'height above maximum' => [600, 6001, 100];
        yield 'size above maximum' => [600, 400, 5121];
    }

    #[DataProvider('validImageBoundaryProvider')]
    public function test_image_size_and_dimension_boundaries_are_inclusive(
        int $width,
        int $height,
        int $sizeKilobytes,
    ): void {
        Storage::fake('public');

        $path = app(CatalogImageStorage::class)->store(
            self::fakeImage('boundary.png', $width, $height, $sizeKilobytes),
            'categories',
        );

        Storage::disk('public')->assertExists($path);
    }

    /** @return iterable<string, array{0: int, 1: int, 2: int}> */
    public static function validImageBoundaryProvider(): iterable
    {
        yield 'minimum dimensions' => [600, 400, 100];
        yield 'maximum width' => [6000, 400, 100];
        yield 'maximum height' => [600, 6000, 100];
        yield 'maximum size' => [600, 400, 5120];
    }

    public function test_cleanup_is_idempotent_and_never_deletes_legacy_or_unsafe_paths(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        $managedPath = 'catalog/packages/8b16caa7-8007-41f1-8928-22f93b38f9c0/a4d9f22e-727a-4a4f-b392-1c4e9af674b1.jpg';
        $unownedCatalogPath = 'catalog/services/8b16caa7-8007-41f1-8928-22f93b38f9c0/a4d9f22e-727a-4a4f-b392-1c4e9af674b1.jpg';
        $legacyPath = 'img/massage.jpg';
        $disk->put($managedPath, 'managed');
        $disk->put($unownedCatalogPath, 'unowned');
        $disk->put($legacyPath, 'legacy');

        $storage = app(CatalogImageStorage::class);
        $storage->deleteManaged([
            $managedPath,
            $managedPath,
            '/img/massage.jpg',
            $legacyPath,
            $unownedCatalogPath,
            'catalog/packages/../img/massage.jpg',
            null,
        ]);
        $storage->deleteManaged([$managedPath]);

        $disk->assertMissing($managedPath);
        $disk->assertExists($unownedCatalogPath);
        $disk->assertExists($legacyPath);
    }

    public function test_cleanup_logs_and_swallows_a_false_disk_result(): void
    {
        $path = 'catalog/packages/8b16caa7-8007-41f1-8928-22f93b38f9c0/a4d9f22e-727a-4a4f-b392-1c4e9af674b1.jpg';
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $filesystems = Mockery::mock(FilesystemManager::class);
        $filesystems->shouldReceive('disk')->once()->with('public')->andReturn($disk);
        Log::spy();

        (new CatalogImageStorage(new ManagedCatalogPath, $filesystems))->deleteManaged([$path]);

        Log::shouldHaveReceived('warning')->once()->with(
            'Catalog image deletion failed.',
            Mockery::on(fn (array $context): bool => $context['path'] === $path),
        );
    }

    public function test_cleanup_logs_and_swallows_disk_exceptions(): void
    {
        $path = 'catalog/categories/8b16caa7-8007-41f1-8928-22f93b38f9c0/a4d9f22e-727a-4a4f-b392-1c4e9af674b1.png';
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with($path)->andThrow(new RuntimeException('disk unavailable'));
        $filesystems = Mockery::mock(FilesystemManager::class);
        $filesystems->shouldReceive('disk')->once()->with('public')->andReturn($disk);
        Log::spy();

        (new CatalogImageStorage(new ManagedCatalogPath, $filesystems))->deleteManaged([$path]);

        Log::shouldHaveReceived('warning')->once()->with(
            'Catalog image deletion failed.',
            Mockery::on(fn (array $context): bool => $context['path'] === $path
                && $context['exception'] instanceof RuntimeException),
        );
    }

    public function test_retention_ids_are_stable_opaque_and_bound_to_the_application_key(): void
    {
        $paths = new ManagedCatalogPath;
        $path = 'catalog/packages/8b16caa7-8007-41f1-8928-22f93b38f9c0/cover.webp';

        $retentionId = $paths->retentionId($path);

        $this->assertSame($retentionId, $paths->retentionId($path));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $retentionId);
        $this->assertStringNotContainsString('catalog', $retentionId);
        $this->assertStringNotContainsString('cover.webp', $retentionId);

        config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);

        $this->assertNotSame($retentionId, $paths->retentionId($path));
    }

    public function test_retained_ids_resolve_only_against_current_paths_in_submitted_order(): void
    {
        $paths = new ManagedCatalogPath;
        $currentPaths = [
            'catalog/packages/first/image.jpg',
            '/img/legacy-gallery.jpg',
        ];

        $resolved = $paths->resolveRetained($currentPaths, [
            $paths->retentionId($currentPaths[1]),
            $paths->retentionId($currentPaths[0]),
        ]);

        $this->assertSame([$currentPaths[1], $currentPaths[0]], $resolved);
    }

    public function test_duplicate_retained_ids_are_rejected_with_the_catalog_validation_error(): void
    {
        $paths = new ManagedCatalogPath;
        $path = 'catalog/packages/current/image.jpg';
        $retentionId = $paths->retentionId($path);

        $this->assertGalleryReferenceValidationFailure(
            fn (): array => $paths->resolveRetained([$path], [$retentionId, $retentionId]),
        );
    }

    public function test_unknown_or_malformed_retained_ids_are_rejected_with_the_catalog_validation_error(): void
    {
        $paths = new ManagedCatalogPath;
        $currentPath = 'catalog/packages/current/image.jpg';

        foreach ([['foreign-retention-id'], [null]] as $submittedIds) {
            $this->assertGalleryReferenceValidationFailure(
                fn (): array => $paths->resolveRetained([$currentPath], $submittedIds),
            );
        }
    }

    #[DataProvider('managedPathProvider')]
    public function test_only_generated_catalog_image_paths_are_managed(?string $path, bool $expected): void
    {
        $this->assertSame($expected, (new ManagedCatalogPath)->isManaged($path));
    }

    /** @return iterable<string, array{0: string|null, 1: bool}> */
    public static function managedPathProvider(): iterable
    {
        $directoryUuid = '8b16caa7-8007-41f1-8928-22f93b38f9c0';
        $filenameUuid = 'a4d9f22e-727a-4a4f-b392-1c4e9af674b1';

        yield 'generated category JPEG path' => ["catalog/categories/{$directoryUuid}/{$filenameUuid}.jpg", true];
        yield 'generated package JPEG alias path' => ["catalog/packages/{$directoryUuid}/{$filenameUuid}.jpeg", true];
        yield 'generated category PNG path' => ["catalog/categories/{$directoryUuid}/{$filenameUuid}.png", true];
        yield 'generated package WebP path' => ["catalog/packages/{$directoryUuid}/{$filenameUuid}.webp", true];
        yield 'null' => [null, false];
        yield 'empty' => ['', false];
        yield 'catalog directory itself' => ['catalog', false];
        yield 'catalog directory with trailing slash' => ['catalog/', false];
        yield 'unknown catalog scope' => ["catalog/services/{$directoryUuid}/{$filenameUuid}.jpg", false];
        yield 'non UUID directory' => ["catalog/packages/not-a-uuid/{$filenameUuid}.jpg", false];
        yield 'non UUID filename' => ["catalog/packages/{$directoryUuid}/image.jpg", false];
        yield 'unsupported extension' => ["catalog/packages/{$directoryUuid}/{$filenameUuid}.gif", false];
        yield 'uppercase extension' => ["catalog/packages/{$directoryUuid}/{$filenameUuid}.JPG", false];
        yield 'uppercase UUID' => ["catalog/packages/8B16CAA7-8007-41F1-8928-22F93B38F9C0/{$filenameUuid}.jpg", false];
        yield 'non version 4 UUID directory' => ["catalog/packages/8b16caa7-8007-11f1-8928-22f93b38f9c0/{$filenameUuid}.jpg", false];
        yield 'path too shallow' => ["catalog/packages/{$filenameUuid}.jpg", false];
        yield 'path too deep' => ["catalog/packages/{$directoryUuid}/nested/{$filenameUuid}.jpg", false];
        yield 'absolute catalog path' => ["/catalog/packages/{$directoryUuid}/{$filenameUuid}.jpg", false];
        yield 'legacy public asset' => ['/img/massage.jpg', false];
        yield 'relative legacy asset' => ['img/massage.jpg', false];
        yield 'parent traversal' => ["catalog/packages/../{$filenameUuid}.jpg", false];
        yield 'current directory traversal' => ["catalog/packages/./{$filenameUuid}.jpg", false];
        yield 'duplicate separator' => ["catalog/packages//{$filenameUuid}.jpg", false];
        yield 'backslash traversal' => ["catalog\\packages\\{$directoryUuid}\\{$filenameUuid}.jpg", false];
        yield 'https URL' => ['https://example.test/catalog/packages/image.jpg', false];
        yield 'protocol-relative URL' => ['//example.test/catalog/packages/image.jpg', false];
        yield 'windows absolute path' => ['C:\\catalog\\packages\\image.jpg', false];
    }

    private function assertGalleryReferenceValidationFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected retained gallery validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [__('admin.catalog.validation.gallery_reference')],
                $exception->errors()['retainedGalleryIds'] ?? [],
            );
        }
    }

    private function assertValidationFailure(string $field, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected [{$field}] validation to fail.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
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
