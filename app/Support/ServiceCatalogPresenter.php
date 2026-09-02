<?php

namespace App\Support;

use App\Models\Service;
use App\Models\ServiceCategory;

class ServiceCatalogPresenter
{
    /** @return array{slug: string, nameId: string, nameEn: string, image: string} */
    public function category(ServiceCategory $category): array
    {
        return [
            'slug' => $category->slug,
            'nameId' => $category->name_id,
            'nameEn' => $category->name_en,
            'image' => $category->image_path,
        ];
    }

    /**
     * @return array{
     *     slug: string,
     *     category: string,
     *     nameId: string,
     *     nameEn: string,
     *     descId: string,
     *     descEn: string,
     *     longId: string,
     *     longEn: string,
     *     goodId: string|null,
     *     goodEn: string|null,
     *     duration: int,
     *     price: int,
     *     image: string,
     *     gallery: list<string>,
     *     popularity: int
     * }
     */
    public function service(Service $service): array
    {
        return [
            'slug' => $service->slug,
            'category' => $service->category->slug,
            'nameId' => $service->name_id,
            'nameEn' => $service->name_en,
            'descId' => $service->description_id,
            'descEn' => $service->description_en,
            'longId' => $service->long_description_id,
            'longEn' => $service->long_description_en,
            'goodId' => $service->good_for_id,
            'goodEn' => $service->good_for_en,
            'duration' => (int) $service->duration_minutes,
            'price' => (int) $service->price_idr,
            'image' => $service->image_path,
            'gallery' => array_map($this->galleryImagePath(...), $service->gallery_paths),
            'popularity' => (int) $service->popularity,
        ];
    }

    private function galleryImagePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : '/img/'.ltrim($path, '/');
    }
}
