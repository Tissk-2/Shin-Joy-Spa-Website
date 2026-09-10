<?php

namespace App\Http\Middleware;

use App\Queries\PublicCatalogQuery;
use App\Support\ServiceCatalogPresenter;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->fresh();
        $presenter = app(ServiceCatalogPresenter::class);
        $catalog = app(PublicCatalogQuery::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'email_verified_at' => $user->email_verified_at?->toISOString(),
                ] : null,
                'canAccessAdmin' => $user?->hasVerifiedEmail() === true && $user->isAdmin(),
            ],
            'locale' => app()->getLocale(),
            'flash' => [
                'status' => fn (): ?string => $request->session()->get('status'),
            ],
            'catalog' => [
                'packages' => fn (): array => $catalog
                    ->categories()
                    ->flatMap->services
                    ->map($presenter->link(...))
                    ->values()
                    ->all(),
            ],
        ];
    }
}
