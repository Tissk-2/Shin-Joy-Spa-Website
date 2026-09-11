<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\AdminCatalogPresenter;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

final class AdminCatalogController extends Controller
{
    public function index(Request $request, AdminCatalogPresenter $presenter): Response
    {
        Gate::authorize('viewAny', Service::class);

        $categories = ServiceCategory::query()
            ->with([
                'services' => fn (HasMany $services): HasMany => $services
                    ->orderBy('display_order')
                    ->orderBy('id'),
                'services.variants',
                'services.extensions',
            ])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return inertia('admin/catalog/Index', $presenter->index($categories));
    }
}
