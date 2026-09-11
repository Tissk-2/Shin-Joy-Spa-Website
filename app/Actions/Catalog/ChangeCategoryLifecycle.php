<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Models\ServiceCategory;
use Closure;
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
        return $this->transition(
            $category,
            $version,
            function (ServiceCategory $locked) use ($target): void {
                $current = $locked->catalogStatus();

                if ($target === CatalogStatus::Published && $current === CatalogStatus::Draft) {
                    $this->validatePublishable($locked);
                    $locked->published_at = now();
                    $locked->is_active = true;

                    return;
                }

                if ($target === CatalogStatus::Archived && $current === CatalogStatus::Published) {
                    $locked->is_active = false;

                    return;
                }

                $this->invalidTransition();
            },
        );
    }

    public function restore(ServiceCategory $category, int $version): ServiceCategory
    {
        return $this->transition(
            $category,
            $version,
            function (ServiceCategory $locked): void {
                if ($locked->catalogStatus() !== CatalogStatus::Archived) {
                    $this->invalidTransition();
                }

                $this->validatePublishable($locked);
                $locked->is_active = true;
            },
        );
    }

    /** @param Closure(ServiceCategory): void $mutation */
    private function transition(
        ServiceCategory $category,
        int $version,
        Closure $mutation,
    ): ServiceCategory {
        return DB::transaction(function () use ($category, $version, $mutation): ServiceCategory {
            $locked = ServiceCategory::query()
                ->whereKey($category->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->lock_version !== $version) {
                throw ValidationException::withMessages([
                    'version' => SaveCatalogCategory::staleValidationMessage(),
                ]);
            }

            $mutation($locked);
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            return $locked;
        });
    }

    private function invalidTransition(): never
    {
        throw ValidationException::withMessages([
            'status' => __('validation.in', ['attribute' => 'status']),
        ]);
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
