<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\ChangePackageLifecycle;
use App\Actions\Catalog\DeleteCatalogDraft;
use App\Actions\Catalog\SaveCatalogPackage;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCatalogPackageRequest;
use App\Http\Requests\Admin\UpdateCatalogPackageRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\AdminCatalogPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use Inertia\Response;

final class AdminCatalogPackageController extends Controller
{
    public function create(AdminCatalogPresenter $presenter): Response
    {
        Gate::authorize('create', Service::class);

        return inertia('admin/catalog/packages/Form', $presenter->packageCreate($this->categories()));
    }

    public function store(StoreCatalogPackageRequest $request, SaveCatalogPackage $save): RedirectResponse
    {
        $save->create($request->toData());

        return $this->redirect('created');
    }

    public function edit(Service $service, AdminCatalogPresenter $presenter): Response
    {
        Gate::authorize('view', $service);
        $service->load(['variants', 'extensions']);

        return inertia('admin/catalog/packages/Form', $presenter->packageEdit($service, $this->categories()));
    }

    public function update(
        UpdateCatalogPackageRequest $request,
        Service $service,
        SaveCatalogPackage $save,
    ): RedirectResponse {
        $save->update($service, (int) $request->validated('version'), $request->toData());

        return $this->redirect('updated');
    }

    public function publish(
        Request $request,
        Service $service,
        ChangePackageLifecycle $lifecycle,
    ): RedirectResponse {
        Gate::authorize('publish', $service);
        $lifecycle($service, $this->version($request), CatalogStatus::Published);

        return $this->redirect('published');
    }

    public function archive(
        Request $request,
        Service $service,
        ChangePackageLifecycle $lifecycle,
    ): RedirectResponse {
        Gate::authorize('archive', $service);
        $lifecycle($service, $this->version($request), CatalogStatus::Archived);

        return $this->redirect('archived');
    }

    public function restore(
        Request $request,
        Service $service,
        ChangePackageLifecycle $lifecycle,
    ): RedirectResponse {
        Gate::authorize('restore', $service);
        $lifecycle->restore($service, $this->version($request));

        return $this->redirect('restored');
    }

    public function destroy(
        Request $request,
        Service $service,
        DeleteCatalogDraft $delete,
    ): RedirectResponse {
        Gate::authorize('delete', $service);
        $delete($service, $this->version($request));

        return $this->redirect('deleted');
    }

    /** @return Collection<int, ServiceCategory> */
    private function categories(): Collection
    {
        return ServiceCategory::query()->orderBy('display_order')->orderBy('id')->get();
    }

    private function version(Request $request): int
    {
        $validator = Validator::make($request->all(), [
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $validator->after(function (ValidationValidator $validator) use ($request): void {
            foreach (array_diff(array_keys($request->all()), ['version']) as $field) {
                $validator->errors()->add($field, __('admin.status.unsupported_field'));
            }
        });

        return (int) $validator->validate()['version'];
    }

    private function redirect(string $action): RedirectResponse
    {
        $messages = app()->getLocale() === 'id'
            ? [
                'created' => 'Draf paket berhasil dibuat.',
                'updated' => 'Paket berhasil diperbarui.',
                'published' => 'Paket berhasil diterbitkan.',
                'archived' => 'Paket berhasil diarsipkan.',
                'restored' => 'Paket berhasil dipulihkan.',
                'deleted' => 'Draf paket berhasil dihapus.',
            ]
            : [
                'created' => 'Package draft created successfully.',
                'updated' => 'Package updated successfully.',
                'published' => 'Package published successfully.',
                'archived' => 'Package archived successfully.',
                'restored' => 'Package restored successfully.',
                'deleted' => 'Package draft deleted successfully.',
            ];

        return to_route('admin.catalog.index', status: 303)->with('status', $messages[$action]);
    }
}
