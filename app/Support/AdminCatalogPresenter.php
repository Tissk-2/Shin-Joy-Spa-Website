<?php

namespace App\Support;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Catalog\ManagedCatalogPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class AdminCatalogPresenter
{
    public function __construct(private readonly ManagedCatalogPath $managedPath) {}

    /**
     * @param  Collection<int, ServiceCategory>  $categories
     * @return array{categories: list<array<string, mixed>>, packages: list<array<string, mixed>>, can: array<string, bool>}
     */
    public function index(Collection $categories): array
    {
        return [
            'categories' => $categories
                ->map(fn (ServiceCategory $category): array => $this->category($category))
                ->values()
                ->all(),
            'packages' => $categories
                ->flatMap(fn (ServiceCategory $category): Collection => $category->services
                    ->sortBy([
                        ['display_order', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->map(fn (Service $service): array => $this->package($service, $category)))
                ->values()
                ->all(),
            'can' => [
                'createCategory' => Gate::allows('create', ServiceCategory::class),
                'createPackage' => Gate::allows('create', Service::class),
            ],
        ];
    }

    /** @param Collection<int, ServiceCategory> $categories
     * @return array{categories: list<array<string, mixed>>, package: array<string, mixed>}
     */
    public function packageCreate(Collection $categories): array
    {
        return [
            'categories' => $this->packageCategories($categories),
            'package' => [
                'version' => null,
                'categoryId' => null,
                'slug' => '',
                'nameId' => '',
                'nameEn' => '',
                'descId' => null,
                'descEn' => null,
                'longId' => null,
                'longEn' => null,
                'benefitId' => null,
                'benefitEn' => null,
                'isVip' => false,
                'displayOrder' => 0,
                'cover' => null,
                'gallery' => [],
                'variants' => [],
                'extensions' => [],
                'status' => 'draft',
                'isPublished' => false,
                'can' => [
                    'update' => false,
                    'publish' => false,
                    'archive' => false,
                    'restore' => false,
                    'delete' => false,
                ],
            ],
        ];
    }

    /** @param Collection<int, ServiceCategory> $categories
     * @return array{categories: list<array<string, mixed>>, package: array<string, mixed>}
     */
    public function packageEdit(Service $service, Collection $categories): array
    {
        return [
            'categories' => $this->packageCategories($categories),
            'package' => [
                'version' => (int) $service->lock_version,
                'categoryId' => (int) $service->category_id,
                'slug' => $service->slug,
                'nameId' => $service->name_id,
                'nameEn' => $service->name_en,
                'descId' => $service->description_id,
                'descEn' => $service->description_en,
                'longId' => $service->long_description_id,
                'longEn' => $service->long_description_en,
                'benefitId' => $service->good_for_id,
                'benefitEn' => $service->good_for_en,
                'isVip' => (bool) $service->is_vip,
                'displayOrder' => (int) $service->display_order,
                'cover' => $this->publicImageUrl($service->image_path),
                'gallery' => collect($service->gallery_paths ?? [])->map(fn (string $path): array => [
                    'retentionId' => $this->managedPath->retentionId($path),
                    'url' => $this->publicImageUrl($path),
                ])->values()->all(),
                'variants' => $service->variants->map(fn ($choice): array => $this->packageChoice($choice))->values()->all(),
                'extensions' => $service->extensions->map(fn ($choice): array => $this->packageChoice($choice))->values()->all(),
                'status' => $service->catalogStatus()->value,
                'isPublished' => $service->published_at !== null,
                'can' => $this->abilities($service),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function category(ServiceCategory $category): array
    {
        return [
            'slug' => $category->slug,
            'version' => (int) $category->lock_version,
            'nameId' => $category->name_id,
            'nameEn' => $category->name_en,
            'image' => $this->publicImageUrl($category->image_path),
            'status' => $category->catalogStatus()->value,
            'packageCount' => $category->services->count(),
            'displayOrder' => (int) $category->display_order,
            'can' => $this->abilities($category),
        ];
    }

    /** @return array<string, mixed> */
    private function package(Service $service, ServiceCategory $category): array
    {
        return [
            'slug' => $service->slug,
            'categorySlug' => $category->slug,
            'nameId' => $service->name_id,
            'nameEn' => $service->name_en,
            'image' => $this->publicImageUrl($service->image_path),
            'gallery' => collect($service->gallery_paths ?? [])
                ->map(fn (string $path): array => [
                    'retentionId' => $this->managedPath->retentionId($path),
                    'url' => $this->publicImageUrl($path),
                ])
                ->values()
                ->all(),
            'isVip' => (bool) $service->is_vip,
            'status' => $service->catalogStatus()->value,
            'variantCount' => $service->variants->count(),
            'extensionCount' => $service->extensions->count(),
            'optionCount' => $service->variants->count() + $service->extensions->count(),
            'displayOrder' => (int) $service->display_order,
            'can' => $this->abilities($service),
        ];
    }

    /** @return array{update: bool, publish: bool, archive: bool, restore: bool, delete: bool} */
    private function abilities(ServiceCategory|Service $model): array
    {
        return [
            'update' => Gate::allows('update', $model),
            'publish' => Gate::allows('publish', $model),
            'archive' => Gate::allows('archive', $model),
            'restore' => Gate::allows('restore', $model),
            'delete' => Gate::allows('delete', $model),
        ];
    }

    private function publicImageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return str_starts_with($path, '/')
            ? $path
            : Storage::disk('public')->url($path);
    }

    /** @param Collection<int, ServiceCategory> $categories
     * @return list<array<string, mixed>>
     */
    private function packageCategories(Collection $categories): array
    {
        return $categories->map(fn (ServiceCategory $category): array => [
            'id' => (int) $category->id,
            'slug' => $category->slug,
            'nameId' => $category->name_id,
            'nameEn' => $category->name_en,
            'status' => $category->catalogStatus()->value,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function packageChoice(mixed $choice): array
    {
        return [
            'id' => (int) $choice->id,
            'code' => $choice->code,
            'mode' => $choice->treatment_mode->value,
            'durationMinutes' => (int) $choice->duration_minutes,
            'priceIdr' => (int) $choice->price_idr,
            'isActive' => (bool) $choice->is_active,
            'displayOrder' => (int) $choice->display_order,
            'isPublished' => $choice->published_at !== null,
        ];
    }
}
