<?php

namespace App\Support;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use Illuminate\Support\Facades\Storage;

class ServiceCatalogPresenter
{
    /** @return array{slug: string, nameId: string, nameEn: string, isVip: bool} */
    public function link(Service $service): array
    {
        return [
            'slug' => $service->slug,
            'nameId' => $service->name_id,
            'nameEn' => $service->name_en,
            'isVip' => (bool) $service->is_vip,
        ];
    }

    /**
     * @return array{
     *     slug: string, nameId: string, nameEn: string, descId: string, descEn: string,
     *     longId: string, longEn: string, benefitId: string|null, benefitEn: string|null,
     *     image: string, gallery: list<string>, isVip: bool,
     *     pricingBasis: string|null, minGuests: int|null, maxGuests: int|null, pricingVerified: bool,
     *     variants: list<array{code: string, mode: string, duration: int, price: int}>,
     *     extensions: list<array{code: string, mode: string, duration: int, price: int}>
     * }
     */
    public function package(Service $service): array
    {
        return [
            'slug' => $service->slug,
            'nameId' => $service->name_id,
            'nameEn' => $service->name_en,
            'descId' => $service->description_id,
            'descEn' => $service->description_en,
            'longId' => $service->long_description_id,
            'longEn' => $service->long_description_en,
            'benefitId' => $service->good_for_id,
            'benefitEn' => $service->good_for_en,
            'image' => $this->publicImageUrl($service->image_path),
            'gallery' => array_map($this->publicImageUrl(...), $service->gallery_paths ?? []),
            'isVip' => (bool) $service->is_vip,
            'pricingBasis' => $service->pricing_basis?->value,
            'minGuests' => $service->min_guests,
            'maxGuests' => $service->max_guests,
            'pricingVerified' => $service->hasVerifiedPricing(),
            'variants' => $service->variants->map(fn (ServiceVariant $variant): array => $this->choice($variant))->values()->all(),
            'extensions' => $service->extensions->map(fn (ServiceExtension $extension): array => $this->choice($extension))->values()->all(),
        ];
    }

    /** @return array{slug: string, nameId: string, nameEn: string, image: string, packages: list<array<string, mixed>>} */
    public function category(ServiceCategory $category): array
    {
        return [
            'slug' => $category->slug,
            'nameId' => $category->name_id,
            'nameEn' => $category->name_en,
            'image' => $this->publicImageUrl($category->image_path),
            'packages' => $category->services
                ->map($this->package(...))
                ->values()
                ->all(),
        ];
    }

    /** @return array{code: string, mode: string, duration: int, price: int} */
    private function choice(ServiceVariant|ServiceExtension $choice): array
    {
        return [
            'code' => $choice->code,
            'mode' => $choice->treatment_mode->value,
            'duration' => (int) $choice->duration_minutes,
            'price' => (int) $choice->price_idr,
        ];
    }

    private function publicImageUrl(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        return str_starts_with($path, '/')
            ? $path
            : Storage::disk('public')->url($path);
    }
}
