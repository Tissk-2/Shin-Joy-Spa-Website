<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\ChangeCategoryLifecycle;
use App\Actions\Catalog\SaveCatalogCategory;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CatalogLifecycleRequest;
use App\Http\Requests\Admin\StoreCatalogCategoryRequest;
use App\Http\Requests\Admin\UpdateCatalogCategoryRequest;
use App\Models\ServiceCategory;
use App\Support\Catalog\CatalogImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminCatalogCategoryController extends Controller
{
    public function store(
        StoreCatalogCategoryRequest $request,
        SaveCatalogCategory $save,
    ): RedirectResponse {
        $save->create($request->toData());

        return to_route('admin.catalog.index', status: 303);
    }

    public function update(
        UpdateCatalogCategoryRequest $request,
        ServiceCategory $category,
        SaveCatalogCategory $save,
    ): RedirectResponse {
        $save->update($category, (int) $request->validated('version'), $request->toData());

        return to_route('admin.catalog.index', status: 303);
    }

    public function publish(
        CatalogLifecycleRequest $request,
        ServiceCategory $category,
        ChangeCategoryLifecycle $changeLifecycle,
    ): RedirectResponse {
        $changeLifecycle($category, $request->version(), CatalogStatus::Published);

        return to_route('admin.catalog.index', status: 303);
    }

    public function archive(
        CatalogLifecycleRequest $request,
        ServiceCategory $category,
        ChangeCategoryLifecycle $changeLifecycle,
    ): RedirectResponse {
        $changeLifecycle($category, $request->version(), CatalogStatus::Archived);

        return to_route('admin.catalog.index', status: 303);
    }

    public function restore(
        CatalogLifecycleRequest $request,
        ServiceCategory $category,
        ChangeCategoryLifecycle $changeLifecycle,
    ): RedirectResponse {
        $changeLifecycle->restore($category, $request->version());

        return to_route('admin.catalog.index', status: 303);
    }

    public function destroy(
        CatalogLifecycleRequest $request,
        ServiceCategory $category,
        CatalogImageStorage $images,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $category, $images): void {
            $locked = ServiceCategory::query()
                ->whereKey($category->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->lock_version !== $request->version()) {
                throw ValidationException::withMessages([
                    'version' => SaveCatalogCategory::staleValidationMessage(),
                ]);
            }

            if ($locked->published_at !== null || $locked->services()->exists()) {
                throw ValidationException::withMessages([
                    'status' => __('validation.in', ['attribute' => 'status']),
                ]);
            }

            $path = $locked->image_path;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();
            $locked->delete();
            DB::afterCommit(fn () => $images->deleteManaged([$path]));
        });

        return to_route('admin.catalog.index', status: 303);
    }
}
