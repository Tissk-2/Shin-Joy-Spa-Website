<?php

namespace App\Data\Admin\Catalog;

use Illuminate\Http\UploadedFile;

final readonly class CatalogPackageData
{
    /**
     * @param  list<string>  $retainedGalleryIds
     * @param  list<UploadedFile>  $galleryUploads
     * @param  list<CatalogChoiceData>  $variants
     * @param  list<CatalogChoiceData>  $extensions
     */
    public function __construct(
        public int $categoryId,
        public string $slug,
        public string $nameId,
        public string $nameEn,
        public ?string $descId,
        public ?string $descEn,
        public ?string $longId,
        public ?string $longEn,
        public ?string $benefitId,
        public ?string $benefitEn,
        public bool $isVip,
        public int $displayOrder,
        public ?UploadedFile $cover,
        public bool $removeCover,
        public array $retainedGalleryIds,
        public array $galleryUploads,
        public array $variants,
        public array $extensions,
    ) {}
}
