<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum CatalogStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public static function fromLifecycle(?CarbonInterface $publishedAt, bool $isActive): self
    {
        if ($publishedAt === null) {
            return self::Draft;
        }

        return $isActive ? self::Published : self::Archived;
    }
}
