<?php

namespace App\Data\Admin\Catalog;

use App\Enums\TreatmentMode;

final readonly class CatalogChoiceData
{
    public function __construct(
        public ?int $id,
        public string $code,
        public TreatmentMode $mode,
        public int $durationMinutes,
        public int $priceIdr,
        public bool $isActive,
        public int $displayOrder,
    ) {}
}
