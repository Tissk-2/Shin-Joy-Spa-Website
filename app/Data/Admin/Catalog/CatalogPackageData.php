<?php

namespace App\Data\Admin\Catalog;

use App\Enums\PricingBasis;
use Illuminate\Http\UploadedFile;

final readonly class CatalogPackageData
{
    /**
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
        public ?string $benefitId,
        public ?string $benefitEn,
        public bool $isVip,
        public int $displayOrder,
        public ?PricingBasis $pricingBasis,
        public ?int $minGuests,
        public ?int $maxGuests,
        public ?UploadedFile $cover,
        public bool $removeCover,
        public array $variants,
        public array $extensions,
    ) {}
}
