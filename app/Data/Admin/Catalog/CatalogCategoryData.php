<?php

namespace App\Data\Admin\Catalog;

use Illuminate\Http\UploadedFile;

final readonly class CatalogCategoryData
{
    public function __construct(
        public string $slug,
        public string $nameId,
        public string $nameEn,
        public int $displayOrder,
        public ?UploadedFile $image,
        public bool $removeImage,
    ) {}
}
