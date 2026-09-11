<?php

namespace App\Support\Catalog;

use Illuminate\Validation\ValidationException;

class ManagedCatalogPath
{
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
        if ($path === null
            || ! str_starts_with($path, 'catalog/')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return count($segments) > 1;
    }

    /** @throws ValidationException */
    private function throwGalleryReferenceValidationException(): never
    {
        throw ValidationException::withMessages([
            'retainedGalleryIds' => __('admin.catalog.validation.gallery_reference'),
        ]);
    }
}
