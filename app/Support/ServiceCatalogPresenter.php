<?php

namespace App\Support;

use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;

class ServiceCatalogPresenter
{
    /**
     * Temporary compatibility bridge for BookingController until Task 4 adopts package().
     *
     * @return array<string, mixed>
     */
    public function service(Service $service): array
    {
        $package = clone $service;
        $package->setRelation('variants', $service->variants()->active()->get());
        $package->setRelation('extensions', $service->extensions()->active()->get());

        return $this->package($package);
    }

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
            'image' => $service->image_path,
            'gallery' => array_map($this->galleryImagePath(...), $service->gallery_paths ?? []),
            'isVip' => (bool) $service->is_vip,
            'variants' => $service->variants->map(fn (ServiceVariant $variant): array => $this->choice($variant))->values()->all(),
            'extensions' => $service->extensions->map(fn (ServiceExtension $extension): array => $this->choice($extension))->values()->all(),
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

    private function galleryImagePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : '/img/'.ltrim($path, '/');
    }
}
