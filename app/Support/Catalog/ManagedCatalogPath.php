<?php

namespace App\Support\Catalog;

use Illuminate\Validation\ValidationException;

class ManagedCatalogPath
{
    private const UUID_V4_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function retentionId(string $path): string
    {
        return hash_hmac('sha256', $path, (string) config('app.key'));
    }

    /**
     * @param  array<int, string>  $currentPaths
     * @param  array<int, mixed>  $submittedIds
     * @return array<int, string>
     */
    public function resolveRetained(array $currentPaths, array $submittedIds): array
    {
        $known = [];

        foreach ($currentPaths as $path) {
            $known[$this->retentionId($path)] = $path;
        }

        $resolved = [];
        $seen = [];

        foreach ($submittedIds as $id) {
            if (! is_string($id) || isset($seen[$id]) || ! array_key_exists($id, $known)) {
                $this->throwGalleryReferenceValidationException();
            }

            $seen[$id] = true;
            $resolved[] = $known[$id];
        }

        return $resolved;
    }

    public function isManaged(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        $uuid = self::UUID_V4_PATTERN;

        return preg_match(
            "#\\Acatalog/(?:categories|packages)/{$uuid}/{$uuid}\\.(?:jpe?g|png|webp)\\z#",
            $path,
        ) === 1;
    }

    /** @throws ValidationException */
    private function throwGalleryReferenceValidationException(): never
    {
        throw ValidationException::withMessages([
            'retainedGalleryIds' => __('admin.catalog.validation.gallery_reference'),
        ]);
    }
}
