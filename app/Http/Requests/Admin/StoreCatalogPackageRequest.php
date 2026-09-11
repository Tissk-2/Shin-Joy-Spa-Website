<?php

namespace App\Http\Requests\Admin;

use App\Models\Service;

final class StoreCatalogPackageRequest extends UpdateCatalogPackageRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Service::class) === true;
    }

    protected function isCreate(): bool
    {
        return true;
    }
}
