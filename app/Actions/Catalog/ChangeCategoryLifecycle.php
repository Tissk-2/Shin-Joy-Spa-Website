<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ChangeCategoryLifecycle
{
    public function __invoke(
        ServiceCategory $category,
        int $version,
        CatalogStatus $target,
    ): ServiceCategory {
        return DB::transaction(function () use ($category, $version, $target): ServiceCategory {
            $locked = ServiceCategory::query()
                ->whereKey($category->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->lock_version !== $version) {
                throw ValidationException::withMessages([
                    'version' => SaveCatalogCategory::staleValidationMessage(),
                ]);
            }

            if ($target === CatalogStatus::Published) {
                $this->validatePublishable($locked);
                $locked->published_at ??= now();
                $locked->is_active = true;
            } elseif ($target === CatalogStatus::Archived && $locked->published_at !== null) {
                $locked->is_active = false;
            } else {
                throw ValidationException::withMessages([
                    'status' => __('validation.in', ['attribute' => 'status']),
                ]);
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            return $locked;
        });
    }

    private function validatePublishable(ServiceCategory $category): void
    {
        Validator::make([
            'nameId' => $category->name_id,
            'nameEn' => $category->name_en,
            'image' => $category->image_path,
            'displayOrder' => $category->display_order,
        ], [
            'nameId' => ['required', 'string', 'max:255'],
            'nameEn' => ['required', 'string', 'max:255'],
            'image' => ['required', 'string'],
            'displayOrder' => ['required', 'integer', 'min:0'],
        ])->validate();
    }
}
