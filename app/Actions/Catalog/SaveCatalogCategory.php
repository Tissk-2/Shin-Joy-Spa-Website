<?php

namespace App\Actions\Catalog;

use App\Data\Admin\Catalog\CatalogCategoryData;
use App\Models\ServiceCategory;
use App\Support\Catalog\CatalogImageStorage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SaveCatalogCategory
{
    public function __construct(private readonly CatalogImageStorage $images) {}

    public function create(CatalogCategoryData $data): ServiceCategory
    {
        $this->validateDraft($data);
        $newPath = $data->image === null ? null : $this->images->store($data->image, 'categories');

        try {
            return DB::transaction(function () use ($data, $newPath): ServiceCategory {
                $category = new ServiceCategory;
                $category->slug = $data->slug;
                $category->name_id = $data->nameId;
                $category->name_en = $data->nameEn;
                $category->display_order = $data->displayOrder;
                $category->image_path = $newPath;
                $category->is_active = false;
                $category->published_at = null;
                $category->lock_version = 0;
                $category->save();

                return $category;
            });
        } catch (Throwable $exception) {
            $this->compensateAndRethrow($newPath, $exception);
        }
    }

    public function update(
        ServiceCategory $category,
        int $version,
        CatalogCategoryData $data,
    ): ServiceCategory {
        $newPath = $data->image === null ? null : $this->images->store($data->image, 'categories');
        $oldPath = null;

        try {
            $updated = DB::transaction(function () use (
                $category,
                $version,
                $data,
                $newPath,
                &$oldPath,
            ): ServiceCategory {
                $locked = ServiceCategory::query()
                    ->whereKey($category->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureFreshVersion($locked, $version);
                $oldPath = $locked->image_path;
                $nextPath = $newPath ?? ($data->removeImage ? null : $oldPath);

                if ($locked->published_at === null || $locked->is_active) {
                    $this->validatePublishable([
                        'nameId' => $data->nameId,
                        'nameEn' => $data->nameEn,
                        'image' => $nextPath,
                        'displayOrder' => $data->displayOrder,
                    ], requireImage: $locked->is_active);
                }

                $locked->slug = $data->slug;
                $locked->name_id = $data->nameId;
                $locked->name_en = $data->nameEn;
                $locked->display_order = $data->displayOrder;
                $locked->image_path = $nextPath;
                $locked->lock_version = $locked->lock_version + 1;
                $locked->save();

                return $locked;
            });
        } catch (Throwable $exception) {
            $this->compensateAndRethrow($newPath, $exception);
        }

        if ($oldPath !== null && $oldPath !== $updated->image_path) {
            $this->images->deleteManaged([$oldPath]);
        }

        return $updated;
    }

    public static function staleValidationMessage(): string
    {
        return app()->getLocale() === 'id'
            ? 'Kategori katalog ini telah berubah. Muat ulang halaman lalu coba lagi.'
            : 'This catalog category has changed. Refresh the page and try again.';
    }

    private function validateDraft(CatalogCategoryData $data): void
    {
        $this->validatePublishable([
            'nameId' => $data->nameId,
            'nameEn' => $data->nameEn,
            'image' => null,
            'displayOrder' => $data->displayOrder,
        ], requireImage: false);
    }

    /** @param array{nameId: string, nameEn: string, image: ?string, displayOrder: int} $values */
    private function validatePublishable(array $values, bool $requireImage): void
    {
        Validator::make($values, [
            'nameId' => ['required', 'string', 'max:255'],
            'nameEn' => ['required', 'string', 'max:255'],
            'image' => [$requireImage ? 'required' : 'nullable', 'string'],
            'displayOrder' => ['required', 'integer', 'min:0'],
        ])->validate();
    }

    private function ensureFreshVersion(ServiceCategory $category, int $version): void
    {
        if ($category->lock_version !== $version) {
            throw ValidationException::withMessages([
                'version' => self::staleValidationMessage(),
            ]);
        }
    }

    private function compensateAndRethrow(?string $newPath, Throwable $exception): never
    {
        if ($newPath !== null) {
            $this->images->deleteManaged([$newPath]);
        }

        if ($exception instanceof UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'slug' => __('validation.unique', ['attribute' => 'slug']),
            ]);
        }

        throw $exception;
    }
}
