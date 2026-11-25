<?php

namespace App\Support\Catalog;

class ManagedCatalogPath
{
    private const UUID_V4_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

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
}
