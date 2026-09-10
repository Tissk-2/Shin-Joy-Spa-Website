# Admin catalog publishing implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let authorized administrators create, upload, publish, archive, restore, and safely delete catalog categories and packages while every public and booking flow reads only valid published data.

**Architecture:** Keep the normalized Laravel catalog tables and add explicit publication timestamps plus optimistic lock versions. Put public visibility behind one query contract, treat package writes as one transactional aggregate, and keep file writes recoverable through compensating cleanup. Vue receives separate safe admin and public presenters, with mounted component and Playwright coverage at the browser boundary.

**Tech Stack:** Laravel 13, PHP 8.3+, Fortify, Inertia 3, Vue 3, TypeScript, SQLite and MySQL, Laravel public storage, Vitest, Vue Test Utils, jsdom, and Playwright.

**Spec:** `docs/superpowers/specs/2026-09-10-admin-catalog-publishing-design.md`

## Global constraints

- Preserve Laravel, Fortify, Inertia, Vue 3, TypeScript, and the existing Shin Joy visual direction.
- Keep payment gateways, scheduling rules, notifications, customer management, and catalog revision history out of Phase 2B.
- Payment remains deferred until the rest of the backend is complete.
- Public content requires `published_at` and `is_active` on the package, category, and selected option.
- Allow at most one active extension per treatment mode per package.
- Keep booking foreign keys nullable and preserve immutable booking snapshots.
- Store JPEG, PNG, or WebP only, at most 5 MB each, between 600 by 400 and 6000 by 6000 pixels.
- Allow at most six gallery images per package.
- Use `lock_version` on categories and packages. Return stale edits as a translated `version` validation error.
- Throttle writes at 20 attempts per minute per administrator and IP, plus 100 attempts per hour per IP.
- Never expose database IDs, filesystem paths, lifecycle timestamps, or lock versions in public props.
- Never use `v-html`, browser-local authentication storage, arbitrary image URLs, or client-provided prices.
- Do not modify or stage the unrelated working-tree edit in `tests/frontend/bookingValidation.test.ts`.
- Stage exact owned paths for every commit. Never use `git add -A` or `git add .`.
- Start each behavior with a focused failing test, implement the minimum passing behavior, then refactor.

---

## Planned file map

### Catalog foundation

- Create `database/migrations/2026_09_10_000000_add_catalog_publishing_lifecycle.php` for lifecycle, concurrency, nullability, and indexes.
- Create `app/Enums/CatalogStatus.php` for `draft`, `published`, and `archived` presentation values.
- Modify the four catalog models to add casts, guarded writes, lifecycle helpers, and published scopes.
- Modify `database/seeders/ServiceCatalogSeeder.php` so fresh seeded records are published.

### Public catalog

- Create `app/Queries/PublicCatalogQuery.php` as the only controller-facing public catalog query.
- Modify `app/Support/ServiceCatalogPresenter.php` to present grouped categories and storage-backed URLs.
- Modify public controllers, booking resolvers, shared Inertia data, and `routes/web.php` to use the published query contract.

### Admin backend

- Create `app/Policies/ServiceCategoryPolicy.php` and `app/Policies/ServicePolicy.php`.
- Create focused category, package, and lifecycle form requests under `app/Http/Requests/Admin/`.
- Create `app/Support/Catalog/ManagedCatalogPath.php` and `app/Support/Catalog/CatalogImageStorage.php`.
- Create immutable aggregate data classes under `app/Data/Admin/Catalog/`.
- Create category and package write actions under `app/Actions/Catalog/`.
- Create `app/Support/AdminCatalogPresenter.php`.
- Create `app/Http/Controllers/Admin/AdminCatalogController.php`, `AdminCatalogCategoryController.php`, and `AdminCatalogPackageController.php`.
- Modify `app/Providers/AppServiceProvider.php` and `routes/admin.php`.

### Vue and styles

- Extend `resources/js/types/index.ts` with public category and admin catalog types.
- Add admin catalog components under `resources/js/components/admin/catalog/`.
- Add admin pages under `resources/js/pages/admin/catalog/`.
- Create `resources/js/pages/Services.vue`.
- Modify `Home.vue`, `ServiceDetail.vue`, `PackageMenuCard.vue`, `MainLayout.vue`, and translations.
- Create `resources/css/admin-catalog.css` and `resources/css/public-catalog.css`, imported by `resources/css/app.css`.

### Tests

- Add focused Laravel feature tests under `tests/Feature/Admin/` and extend public and booking tests.
- Add mounted Vue tests under `tests/frontend/`.
- Add `tests/e2e/admin-catalog.spec.ts`, `tests/e2e/helpers/admin.ts`, and small image fixtures under `tests/e2e/fixtures/catalog/`.

---

### Task 1: Add the catalog lifecycle schema and model contract

**Files:**

- Create: `database/migrations/2026_09_10_000000_add_catalog_publishing_lifecycle.php`
- Create: `app/Enums/CatalogStatus.php`
- Modify: `app/Models/ServiceCategory.php`
- Modify: `app/Models/Service.php`
- Modify: `app/Models/ServiceVariant.php`
- Modify: `app/Models/ServiceExtension.php`
- Modify: `database/seeders/ServiceCatalogSeeder.php`
- Create: `tests/Feature/Admin/CatalogSchemaTest.php`
- Modify: `tests/Feature/ServiceCatalogPersistenceTest.php`

**Interfaces:**

- Produces: `CatalogStatus::fromLifecycle(?CarbonInterface $publishedAt, bool $isActive): CatalogStatus`.
- Produces: `ServiceCategory::scopePubliclyVisible(Builder $query): void`.
- Produces: `Service::scopePubliclyVisible(Builder $query): void` without a slug whitelist.
- Produces: `published_at` casts on all four catalog models and integer `lock_version` casts on categories and services.
- Consumes: existing normalized catalog tables and official seed data.

- [ ] **Step 1: Write failing schema and lifecycle tests**

Add tests that assert the new columns, draft/published/archived classification, legacy backfill, nullable draft content, and a third published package becoming publicly visible.

```php
public function test_catalog_rows_have_publication_and_lock_columns(): void
{
    $this->assertTrue(Schema::hasColumns('service_categories', ['published_at', 'lock_version']));
    $this->assertTrue(Schema::hasColumns('services', ['published_at', 'lock_version']));
    $this->assertTrue(Schema::hasColumn('service_variants', 'published_at'));
    $this->assertTrue(Schema::hasColumn('service_extensions', 'published_at'));
}

public function test_any_published_package_can_be_publicly_visible(): void
{
    $category = ServiceCategory::query()->create([
        'slug' => 'body-care', 'name_id' => 'Perawatan Tubuh', 'name_en' => 'Body Care',
        'image_path' => '/img/body.jpg', 'published_at' => now(),
        'is_active' => true, 'display_order' => 2,
    ]);
    $service = Service::query()->create([
        'category_id' => $category->id, 'slug' => 'body-scrub-deluxe',
        'name_id' => 'Body Scrub Deluxe', 'name_en' => 'Deluxe Body Scrub',
        'description_id' => 'Perawatan tubuh.', 'description_en' => 'A body treatment.',
        'long_description_id' => 'Perawatan tubuh lengkap.',
        'long_description_en' => 'A complete body treatment.',
        'image_path' => '/img/body.jpg', 'gallery_paths' => [],
        'is_vip' => false, 'is_active' => true, 'published_at' => now(),
        'display_order' => 1, 'popularity' => 0,
    ]);
    ServiceVariant::query()->create([
        'service_id' => $service->id, 'code' => 'body-scrub-60',
        'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
        'price_idr' => 175000, 'is_active' => true,
        'published_at' => now(), 'display_order' => 1,
    ]);

    $this->assertSame(['body-scrub-deluxe'], Service::query()->publiclyVisible()->pluck('slug')->all());
}
```

- [ ] **Step 2: Run the focused tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Admin/CatalogSchemaTest.php tests/Feature/ServiceCatalogPersistenceTest.php
```

Expected: failures for missing columns, missing enum, and the current Nezuko-only scope.

- [ ] **Step 3: Add the migration and lifecycle enum**

Use one migration. Backfill every pre-existing catalog row before relying on publication scopes.

```php
$publishedAt = now();

Schema::table('service_categories', function (Blueprint $table): void {
    $table->string('image_path')->nullable()->change();
    $table->timestamp('published_at')->nullable()->index();
    $table->unsignedBigInteger('lock_version')->default(0);
    $table->index(['is_active', 'display_order', 'id'], 'categories_public_order_index');
});

Schema::table('services', function (Blueprint $table): void {
    $table->text('description_id')->nullable()->change();
    $table->text('description_en')->nullable()->change();
    $table->text('long_description_id')->nullable()->change();
    $table->text('long_description_en')->nullable()->change();
    $table->string('image_path')->nullable()->change();
    $table->json('gallery_paths')->default('[]')->change();
    $table->unsignedInteger('popularity')->default(0)->change();
    $table->timestamp('published_at')->nullable()->index();
    $table->unsignedBigInteger('lock_version')->default(0);
    $table->index(['is_active', 'display_order', 'id'], 'services_public_order_index');
});

Schema::table('service_variants', fn (Blueprint $table) => $table->timestamp('published_at')->nullable()->index());
Schema::table('service_extensions', fn (Blueprint $table) => $table->timestamp('published_at')->nullable()->index());

DB::table('service_categories')->update(['published_at' => $publishedAt]);
DB::table('services')->update(['published_at' => $publishedAt]);
DB::table('service_variants')->update(['published_at' => $publishedAt]);
DB::table('service_extensions')->update(['published_at' => $publishedAt]);
```

The `down()` method drops indexes and new columns in reverse order. Before restoring non-null content columns, replace only null draft values with empty strings and keep every existing non-null value unchanged. Restore the earlier gallery and popularity definitions so a rollback does not depend on database-specific implicit defaults.

Define the enum exactly as:

```php
enum CatalogStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public static function fromLifecycle(?CarbonInterface $publishedAt, bool $isActive): self
    {
        if ($publishedAt === null) {
            return self::Draft;
        }

        return $isActive ? self::Published : self::Archived;
    }
}
```

- [ ] **Step 4: Update model casts, lifecycle helpers, and public scopes**

Keep the existing explicit content-field lists for seeding and test setup. Controllers never pass raw request arrays to a model. Write `published_at`, `is_active`, and `lock_version` explicitly inside catalog actions. Add `catalogStatus()` methods and require active published parents and variants.

```php
public function scopePubliclyVisible(Builder $query): void
{
    $query->whereNotNull('published_at')
        ->where('is_active', true)
        ->whereHas('category', fn (Builder $category) => $category
            ->whereNotNull('published_at')
            ->where('is_active', true))
        ->whereHas('variants', fn (Builder $variant) => $variant
            ->whereNotNull('published_at')
            ->where('is_active', true))
        ->orderBy('display_order')
        ->orderBy('id');
}
```

`ServiceVariant::scopeActive()` and `ServiceExtension::scopeActive()` must also require non-null `published_at` because those scopes feed public presenters and quote resolution.

- [ ] **Step 5: Update the official seeder**

Set `published_at` on active official categories, packages, variants, and extensions. When the seeder archives a collision, preserve an existing timestamp and set one if the row predates the lifecycle migration.

- [ ] **Step 6: Run focused tests and migration rollback coverage**

Run:

```bash
php artisan test tests/Feature/Admin/CatalogSchemaTest.php tests/Feature/ServiceCatalogPersistenceTest.php
php artisan migrate:fresh --seed --force --no-interaction
php artisan migrate:rollback --step=1 --force --no-interaction
php artisan migrate --force --no-interaction
```

Expected: all tests pass, official packages remain public, and the lifecycle migration rolls backward and forward without SQL errors.

- [ ] **Step 7: Commit Task 1**

```bash
git add app/Enums/CatalogStatus.php app/Models/ServiceCategory.php app/Models/Service.php app/Models/ServiceVariant.php app/Models/ServiceExtension.php database/migrations/2026_09_10_000000_add_catalog_publishing_lifecycle.php database/seeders/ServiceCatalogSeeder.php tests/Feature/Admin/CatalogSchemaTest.php tests/Feature/ServiceCatalogPersistenceTest.php
git commit -m "feat: add catalog publishing lifecycle"
```

---

### Task 2: Centralize the public catalog and grouped page contract

**Files:**

- Create: `app/Queries/PublicCatalogQuery.php`
- Modify: `app/Support/ServiceCatalogPresenter.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `app/Http/Controllers/HomeController.php`
- Modify: `app/Http/Controllers/ServiceController.php`
- Modify: `app/Http/Controllers/BookingController.php`
- Modify: `app/Support/BookingSelectionResolver.php`
- Modify: `app/Support/BookingQuoteResolver.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/ServiceCatalogPagesTest.php`
- Modify: `tests/Feature/BookingQuoteTest.php`
- Modify: `tests/Feature/InertiaSharedDataTest.php`

**Interfaces:**

- Consumes: lifecycle scopes from Task 1.
- Produces: `PublicCatalogQuery::categories(): Collection`.
- Produces: `PublicCatalogQuery::findService(string $slug): Service`.
- Produces: `PublicCatalogQuery::related(Service $service, int $limit = 3): Collection`.
- Produces: `ServiceCatalogPresenter::category(ServiceCategory $category): array`.
- Produces: `GET /services` named `services.index`, rendering `Services` with `categories`.
- Preserves: shared `catalog.packages` as compact links only.

- [ ] **Step 1: Write failing public contract tests**

Assert exact grouped props, deterministic order, same-category related packages, safe shared links, hidden drafts, and quote rejection for an archived child option.

```php
$response = $this->get(route('services.index'))->assertOk()->assertInertia(
    fn (AssertableInertia $page) => $page
        ->component('Services')
        ->has('categories', 2)
        ->where('categories.0.slug', 'massage')
        ->where('categories.0.packages.0.slug', 'balinese-60')
        ->missing('categories.0.id')
        ->missing('categories.0.published_at'),
);

$this->get(route('services.show', $service->slug))->assertInertia(
    fn (AssertableInertia $page) => $page
        ->has('related', 3)
        ->where('related.0.categorySlug', $service->category->slug)
        ->missing('comparison'),
);
```

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/BookingQuoteTest.php tests/Feature/InertiaSharedDataTest.php
```

Expected: `/services` redirects, the detail prop is still `comparison`, and grouped categories do not exist.

- [ ] **Step 3: Implement the public query object**

The query owns every public eager-load rule. Controllers must not rebuild these conditions.

```php
final class PublicCatalogQuery
{
    public function categories(): Collection
    {
        return ServiceCategory::query()
            ->publiclyVisible()
            ->with(['services' => fn (HasMany $services) => $services
                ->publiclyVisible()
                ->with([
                    'variants' => fn (HasMany $variants) => $variants->active(),
                    'extensions' => fn (HasMany $extensions) => $extensions->active(),
                ])])
            ->get();
    }

    public function findService(string $slug): Service
    {
        return Service::query()->publiclyVisible()->withPublicChoices()
            ->where('slug', $slug)->firstOrFail();
    }

    public function related(Service $service, int $limit = 3): Collection
    {
        return Service::query()->publiclyVisible()
            ->where('category_id', $service->category_id)
            ->whereKeyNot($service)->limit($limit)->get();
    }
}
```

Add a named `withPublicChoices()` model scope so quote resolution, booking selection, and page controllers share the same eager loads.

- [ ] **Step 4: Extend the presenter without leaking admin data**

Resolve managed storage paths with `Storage::disk('public')->url($path)`, preserve absolute seeded `/img/...` values, and add this grouped contract:

```php
public function category(ServiceCategory $category): array
{
    return [
        'slug' => $category->slug,
        'nameId' => $category->name_id,
        'nameEn' => $category->name_en,
        'image' => $this->publicImageUrl($category->image_path),
        'packages' => $category->services
            ->map($this->package(...))->values()->all(),
    ];
}
```

- [ ] **Step 5: Update controllers, middleware, booking resolvers, and routes**

Replace `Route::redirect('/services', '/#menu')` with:

```php
Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
```

Home and menu pages use grouped categories. Detail pages return `related`. Shared props keep compact package links. Booking creation, selection, and quote resolution use the same published query or scopes, so inactive or unpublished choices fail normal validation.

- [ ] **Step 6: Run focused and query-count tests**

```bash
php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/BookingQuoteTest.php tests/Feature/InertiaSharedDataTest.php tests/Feature/BookingPagesTest.php
```

Expected: all pass with no query count growth as more packages are inserted.

- [ ] **Step 7: Commit Task 2**

```bash
git add app/Queries/PublicCatalogQuery.php app/Support/ServiceCatalogPresenter.php app/Http/Middleware/HandleInertiaRequests.php app/Http/Controllers/HomeController.php app/Http/Controllers/ServiceController.php app/Http/Controllers/BookingController.php app/Support/BookingSelectionResolver.php app/Support/BookingQuoteResolver.php routes/web.php tests/Feature/ServiceCatalogPagesTest.php tests/Feature/BookingQuoteTest.php tests/Feature/InertiaSharedDataTest.php tests/Feature/BookingPagesTest.php
git commit -m "feat: serve a dynamic published catalog"
```

---

### Task 3: Build safe catalog image storage

**Files:**

- Create: `app/Support/Catalog/ManagedCatalogPath.php`
- Create: `app/Support/Catalog/CatalogImageStorage.php`
- Create: `tests/Feature/Admin/CatalogImageStorageTest.php`

**Interfaces:**

- Produces: `ManagedCatalogPath::retentionId(string $path): string`.
- Produces: `ManagedCatalogPath::resolveRetained(array $currentPaths, array $submittedIds): array`.
- Produces: `ManagedCatalogPath::isManaged(?string $path): bool`.
- Produces: `CatalogImageStorage::store(UploadedFile $file, string $scope): string`.
- Produces: `CatalogImageStorage::deleteManaged(iterable $paths): void`.
- Consumes: Laravel's configured `public` disk and application key.

- [ ] **Step 1: Write failing storage tests**

Use `Storage::fake('public')` and `UploadedFile::fake()->image()` to prove random managed paths, opaque retention IDs, ownership checks, cleanup, and legacy asset protection.

```php
public function test_storage_uses_random_managed_paths_and_never_deletes_legacy_assets(): void
{
    Storage::fake('public');
    $storage = app(CatalogImageStorage::class);
    $path = $storage->store(
        UploadedFile::fake()->image('client-name.jpg', 1200, 800)->size(500),
        'packages',
    );

    $this->assertMatchesRegularExpression('#^catalog/packages/[0-9a-f-]+/[0-9a-f-]+\.jpg$#', $path);
    Storage::disk('public')->assertExists($path);

    $storage->deleteManaged([$path, '/img/massage.jpg']);
    Storage::disk('public')->assertMissing($path);
}
```

- [ ] **Step 2: Run the storage test and confirm RED**

```bash
php artisan test tests/Feature/Admin/CatalogImageStorageTest.php
```

Expected: failures because both support classes are absent.

- [ ] **Step 3: Implement managed-path ownership and opaque retention IDs**

Use an HMAC rather than sending storage paths as browser identifiers.

```php
public function retentionId(string $path): string
{
    return hash_hmac('sha256', $path, (string) config('app.key'));
}

public function resolveRetained(array $currentPaths, array $submittedIds): array
{
    $known = collect($currentPaths)->mapWithKeys(
        fn (string $path): array => [$this->retentionId($path) => $path],
    );

    if (count(array_unique($submittedIds)) !== count($submittedIds)
        || collect($submittedIds)->contains(fn (string $id): bool => ! $known->has($id))) {
        throw ValidationException::withMessages([
            'retainedGalleryIds' => __('admin.catalog.validation.gallery_reference'),
        ]);
    }

    return collect($submittedIds)->map(fn (string $id): string => $known->get($id))->all();
}
```

- [ ] **Step 4: Implement storage and idempotent cleanup**

Accept only `categories` or `packages` as the scope. Generate UUID directory and filename segments. Delete only normalized relative paths beginning with `catalog/`; log failures without throwing after a database commit.

- [ ] **Step 5: Run focused tests**

```bash
php artisan test tests/Feature/Admin/CatalogImageStorageTest.php
```

Expected: all storage and path tests pass.

- [ ] **Step 6: Commit Task 3**

```bash
git add app/Support/Catalog/ManagedCatalogPath.php app/Support/Catalog/CatalogImageStorage.php tests/Feature/Admin/CatalogImageStorageTest.php
git commit -m "feat: add safe catalog image storage"
```

---

### Task 4: Add admin catalog policies, the index, and write throttling

**Files:**

- Create: `app/Policies/ServiceCategoryPolicy.php`
- Create: `app/Policies/ServicePolicy.php`
- Create: `app/Support/AdminCatalogPresenter.php`
- Create: `app/Http/Controllers/Admin/AdminCatalogController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/admin.php`
- Create: `tests/Feature/Admin/AdminCatalogAuthorizationTest.php`
- Create: `tests/Feature/Admin/AdminCatalogIndexTest.php`

**Interfaces:**

- Consumes: `CatalogStatus` and the current `User::isAdmin()` boundary.
- Produces: policy methods `viewAny`, `view`, `create`, `update`, `publish`, `archive`, `restore`, and `delete`.
- Produces: `AdminCatalogPresenter::index(Collection $categories): array`.
- Produces: `GET /admin/catalog` named `admin.catalog.index`.
- Produces: `catalog-write` rate limiter for later mutation routes.

- [ ] **Step 1: Write failing authorization and safe-prop tests**

Test guest redirect, unverified redirect, customer 403, verified-admin success, middleware ordering, lifecycle action ability values, and absence of raw storage paths or booking data.

```php
$this->actingAs(User::factory()->admin()->create())
    ->get(route('admin.catalog.index'))
    ->assertOk()
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->component('admin/catalog/Index')
        ->has('categories')
        ->has('packages')
        ->missing('categories.0.image_path')
        ->missing('packages.0.lock_version'));
```

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
php artisan test tests/Feature/Admin/AdminCatalogAuthorizationTest.php tests/Feature/Admin/AdminCatalogIndexTest.php
```

Expected: the catalog route and policies are absent.

- [ ] **Step 3: Implement policies and safe admin presentation**

Every policy method delegates to one private verified-admin check for Phase 2B. The presenter exposes camelCase fields, a public image URL, an opaque gallery retention ID, status, counts, and explicit `can` flags. It does not expose timestamps, raw paths, or unrelated model attributes.

- [ ] **Step 4: Add the index controller and route**

```php
public function index(Request $request, AdminCatalogPresenter $presenter): Response
{
    Gate::authorize('viewAny', Service::class);

    $categories = ServiceCategory::query()
        ->with(['services.variants', 'services.extensions'])
        ->orderBy('display_order')->orderBy('id')->get();

    return inertia('admin/catalog/Index', $presenter->index($categories));
}
```

Keep the route inside the existing `auth`, `verified`, and `admin` group.

- [ ] **Step 5: Register the write limiter**

```php
RateLimiter::for('catalog-write', fn (Request $request): array => [
    Limit::perMinute(20)->by('admin:'.$request->user()?->getAuthIdentifier().'|ip:'.$request->ip()),
    Limit::perHour(100)->by('ip:'.$request->ip()),
]);
```

Do not attach it to the read-only index route.

- [ ] **Step 6: Run focused tests**

```bash
php artisan test tests/Feature/Admin/AdminCatalogAuthorizationTest.php tests/Feature/Admin/AdminCatalogIndexTest.php
```

Expected: all authorization, middleware, and safe-prop tests pass.

- [ ] **Step 7: Commit Task 4**

```bash
git add app/Policies/ServiceCategoryPolicy.php app/Policies/ServicePolicy.php app/Support/AdminCatalogPresenter.php app/Http/Controllers/Admin/AdminCatalogController.php app/Providers/AppServiceProvider.php routes/admin.php tests/Feature/Admin/AdminCatalogAuthorizationTest.php tests/Feature/Admin/AdminCatalogIndexTest.php
git commit -m "feat: add the admin catalog boundary"
```

---

### Task 5: Implement category draft and lifecycle writes

**Files:**

- Create: `app/Http/Requests/Admin/StoreCatalogCategoryRequest.php`
- Create: `app/Http/Requests/Admin/UpdateCatalogCategoryRequest.php`
- Create: `app/Http/Requests/Admin/CatalogLifecycleRequest.php`
- Create: `app/Data/Admin/Catalog/CatalogCategoryData.php`
- Create: `app/Actions/Catalog/SaveCatalogCategory.php`
- Create: `app/Actions/Catalog/ChangeCategoryLifecycle.php`
- Create: `app/Http/Controllers/Admin/AdminCatalogCategoryController.php`
- Modify: `routes/admin.php`
- Create: `tests/Feature/Admin/AdminCatalogCategoryTest.php`
- Create: `tests/Feature/Admin/AdminCatalogThrottleTest.php`

**Interfaces:**

- Produces: readonly `CatalogCategoryData` with `slug`, `nameId`, `nameEn`, `displayOrder`, `image`, and `removeImage`.
- Produces: `SaveCatalogCategory::create(CatalogCategoryData $data): ServiceCategory`.
- Produces: `SaveCatalogCategory::update(ServiceCategory $category, int $version, CatalogCategoryData $data): ServiceCategory`.
- Produces: `ChangeCategoryLifecycle::__invoke(ServiceCategory $category, int $version, CatalogStatus $target): ServiceCategory`.
- Produces: category store, update, publish, archive, restore, and delete routes from the approved spec.

- [ ] **Step 1: Write failing category behavior tests**

Cover exact allowed fields, normalized copy, image validation, duplicate slug errors, slug immutability, publication requirements, archive and restore, stale versions, draft deletion, category-with-packages deletion denial, file compensation, and both throttle limits.

```php
$response = $this->actingAs($admin)->post(route('admin.catalog.categories.store'), [
    'slug' => 'body-care',
    'nameId' => '  Perawatan Tubuh  ',
    'nameEn' => '  Body Care  ',
    'displayOrder' => 2,
]);

$response->assertStatus(303);
$this->assertDatabaseHas('service_categories', [
    'slug' => 'body-care', 'name_id' => 'Perawatan Tubuh',
    'name_en' => 'Body Care', 'is_active' => false, 'published_at' => null,
]);
```

- [ ] **Step 2: Run category tests and confirm RED**

```bash
php artisan test tests/Feature/Admin/AdminCatalogCategoryTest.php tests/Feature/Admin/AdminCatalogThrottleTest.php
```

Expected: missing request, action, controller, and routes.

- [ ] **Step 3: Implement request normalization and strict validation**

Use `Rule::unique`, `File::types(['jpg', 'jpeg', 'png', 'webp'])->image()->max(5 * 1024)->dimensions(...)`, scalar guards, and an `after()` hook that rejects any key outside:

```php
['version', 'slug', 'nameId', 'nameEn', 'displayOrder', 'image', 'removeImage']
```

`version` is forbidden on create and required as a non-negative integer on update and lifecycle requests. Trim strings before validation. Reject slug changes when `published_at` is non-null.

- [ ] **Step 4: Implement transactional category saving with file compensation**

Store a new image first. Inside a transaction, lock the existing category for update, compare `lock_version`, write explicit columns, and increment the version. Delete the new file if the transaction fails. Delete the replaced managed image only after commit.

```php
if ($locked->lock_version !== $version) {
    throw ValidationException::withMessages([
        'version' => __('admin.catalog.validation.stale'),
    ]);
}
```

Catch duplicate-key races and convert the collision to a `slug` validation error.

- [ ] **Step 5: Implement category lifecycle rules**

Publish requires paired names, image, and valid order. Updates to an active published category rerun the same publication validation, while an archived category may remain incomplete until restore. Archive sets only the category `is_active` flag to false, preserves `published_at`, and does not change any package row. Restore reruns publish validation. Delete requires `published_at === null` and `services()->doesntExist()`.

- [ ] **Step 6: Add routes with throttling**

Attach `throttle:catalog-write` only to POST, PATCH, and DELETE catalog routes. Keep route model binding behind the existing authorization middleware ordering.

- [ ] **Step 7: Run category tests and the broader admin boundary**

```bash
php artisan test tests/Feature/Admin/AdminCatalogCategoryTest.php tests/Feature/Admin/AdminCatalogThrottleTest.php tests/Feature/Admin/AdminCatalogAuthorizationTest.php
```

Expected: all pass, including standard HTTP 429 responses on attempts 21 and 101.

- [ ] **Step 8: Commit Task 5**

```bash
git add app/Http/Requests/Admin/StoreCatalogCategoryRequest.php app/Http/Requests/Admin/UpdateCatalogCategoryRequest.php app/Http/Requests/Admin/CatalogLifecycleRequest.php app/Data/Admin/Catalog/CatalogCategoryData.php app/Actions/Catalog/SaveCatalogCategory.php app/Actions/Catalog/ChangeCategoryLifecycle.php app/Http/Controllers/Admin/AdminCatalogCategoryController.php routes/admin.php tests/Feature/Admin/AdminCatalogCategoryTest.php tests/Feature/Admin/AdminCatalogThrottleTest.php
git commit -m "feat: manage catalog category lifecycle"
```

---

### Task 6: Implement transactional package aggregate writes

**Files:**

- Create: `app/Http/Requests/Admin/StoreCatalogPackageRequest.php`
- Create: `app/Http/Requests/Admin/UpdateCatalogPackageRequest.php`
- Create: `app/Data/Admin/Catalog/CatalogChoiceData.php`
- Create: `app/Data/Admin/Catalog/CatalogPackageData.php`
- Create: `app/Actions/Catalog/SaveCatalogPackage.php`
- Create: `app/Actions/Catalog/ChangePackageLifecycle.php`
- Create: `app/Actions/Catalog/DeleteCatalogDraft.php`
- Create: `app/Support/Catalog/CatalogCollisionReporter.php`
- Create: `app/Http/Controllers/Admin/AdminCatalogPackageController.php`
- Modify: `app/Support/AdminCatalogPresenter.php`
- Modify: `routes/admin.php`
- Create: `tests/Feature/Admin/AdminCatalogPackageTest.php`
- Create: `tests/Feature/Admin/AdminCatalogLifecycleTest.php`

**Interfaces:**

- Produces: readonly `CatalogChoiceData` with nullable `id`, `code`, `TreatmentMode $mode`, `durationMinutes`, `priceIdr`, `isActive`, and `displayOrder`.
- Produces: readonly `CatalogPackageData` matching the approved aggregate request fields.
- Produces: `SaveCatalogPackage::create(CatalogPackageData $data): Service`.
- Produces: `SaveCatalogPackage::update(Service $service, int $version, CatalogPackageData $data): Service`.
- Produces: `ChangePackageLifecycle::__invoke(Service $service, int $version, CatalogStatus $target): Service`.
- Produces: package create, store, edit, update, publish, archive, restore, and delete routes.

- [ ] **Step 1: Write failing aggregate tests**

Cover draft creation, complete nested update, unknown keys, foreign child IDs, duplicate child IDs, invalid enums, duplicate mode-duration pairs, one-active-extension-per-mode, image and gallery boundaries, stale versions, code and slug collisions, immediate published edits, omitted-child archive/delete rules, and complete rollback after a nested failure.

```php
$payload = [
    'categoryId' => $category->id,
    'slug' => 'balinese-signature',
    'nameId' => 'Balinese Signature',
    'nameEn' => 'Balinese Signature',
    'descId' => null,
    'descEn' => null,
    'longId' => null,
    'longEn' => null,
    'benefitId' => null,
    'benefitEn' => null,
    'isVip' => false,
    'displayOrder' => 1,
    'removeCover' => false,
    'retainedGalleryIds' => [],
    'variants' => [],
    'extensions' => [],
];

$this->actingAs($admin)
    ->post(route('admin.catalog.packages.store'), $payload)
    ->assertStatus(303);

$this->assertDatabaseHas('services', [
    'slug' => 'balinese-signature', 'is_active' => false, 'published_at' => null,
]);
```

- [ ] **Step 2: Run aggregate tests and confirm RED**

```bash
php artisan test tests/Feature/Admin/AdminCatalogPackageTest.php tests/Feature/Admin/AdminCatalogLifecycleTest.php
```

Expected: missing package requests, data classes, actions, controller, and routes.

- [ ] **Step 3: Implement exact nested request validation**

Reject unknown top-level keys and unknown keys inside every variant and extension. Convert strings to trimmed nullable values, booleans to real booleans, and numeric fields to integers only after validation.

Use these exact nested keys:

```php
['id', 'code', 'mode', 'durationMinutes', 'priceIdr', 'isActive', 'displayOrder']
```

Validate `mode` with `Rule::enum(TreatmentMode::class)`. Use after-validation checks for duplicate submitted IDs, duplicate active mode-duration pairs, foreign child IDs, paired benefit fields, maximum six retained plus uploaded gallery images, and at most one active extension for each mode.

- [ ] **Step 4: Build immutable aggregate data**

```php
final readonly class CatalogChoiceData
{
    public function __construct(
        public ?int $id,
        public string $code,
        public TreatmentMode $mode,
        public int $durationMinutes,
        public int $priceIdr,
        public bool $isActive,
        public int $displayOrder,
    ) {}
}
```

`CatalogPackageData` contains only validated scalar values, immutable choice lists, uploaded file objects, and opaque retained gallery IDs. It never contains the raw request.

- [ ] **Step 5: Implement aggregate create and update**

Store uploads first, then reconcile within one transaction. On update, lock the service row and compare its version. Resolve retained IDs only against the current gallery. Resolve submitted child IDs only against current children.

An update to an active published package must pass the same completeness rules as publication before commit. An archived package may be saved while incomplete, but restore must fail until every publication requirement passes.

For every submitted child:

- update an owned row or create a new row;
- reject changes to a code whose `published_at` is non-null;
- set `published_at` when an active row first becomes public under a published package;
- preserve inactive rows without exposing them publicly.

For every omitted existing child:

- delete it only when the package and child have never been published and no booking references it;
- otherwise set `is_active` false and preserve its code and publication timestamp.

Increment the package version once per successful aggregate request, not once per child.

- [ ] **Step 6: Convert unique races to field errors**

`CatalogCollisionReporter` re-queries slug and code ownership after a `QueryException`. It throws a validation error for the exact conflicting field such as `slug`, `variants.1.code`, or `extensions.0.code`. If no known collision exists, it rethrows the original exception.

- [ ] **Step 7: Implement package lifecycle and deletion**

First publish validates category state, paired bilingual copy, cover, gallery references, active published-ready variant, every active child, and extension count. It sets service and active child publication timestamps in the same transaction.

Archive keeps child rows unchanged and sets the service inactive. Restore reruns full publication validation. Delete permits only a never-published package with no direct or child booking references.

- [ ] **Step 8: Add controllers, safe form props, and routes**

`create()` returns every category with its status plus empty form data, so an administrator can assemble a package under a draft category. `edit()` returns the exact aggregate with public image URLs, retention IDs, lock version, status, and `can` flags. Publication still requires an active published category. All writes redirect with status 303 and translated flash status.

- [ ] **Step 9: Run package and booking regression tests**

```bash
php artisan test tests/Feature/Admin/AdminCatalogPackageTest.php tests/Feature/Admin/AdminCatalogLifecycleTest.php tests/Feature/BookingQuoteTest.php tests/Feature/BookingSubmissionTest.php tests/Feature/Admin/AdminBookingDetailTest.php
```

Expected: all pass, and catalog changes never rewrite a booking snapshot.

- [ ] **Step 10: Commit Task 6**

```bash
git add app/Http/Requests/Admin/StoreCatalogPackageRequest.php app/Http/Requests/Admin/UpdateCatalogPackageRequest.php app/Data/Admin/Catalog/CatalogChoiceData.php app/Data/Admin/Catalog/CatalogPackageData.php app/Actions/Catalog/SaveCatalogPackage.php app/Actions/Catalog/ChangePackageLifecycle.php app/Actions/Catalog/DeleteCatalogDraft.php app/Support/Catalog/CatalogCollisionReporter.php app/Http/Controllers/Admin/AdminCatalogPackageController.php app/Support/AdminCatalogPresenter.php routes/admin.php tests/Feature/Admin/AdminCatalogPackageTest.php tests/Feature/Admin/AdminCatalogLifecycleTest.php
git commit -m "feat: save catalog packages as aggregates"
```

---

### Task 7: Add admin catalog types, navigation, and index UI

**Files:**

- Modify: `resources/js/types/index.ts`
- Modify: `resources/js/layouts/admin/AdminLayout.vue`
- Modify: `resources/js/data/translations.ts`
- Create: `resources/js/components/admin/catalog/CatalogStatusBadge.vue`
- Create: `resources/js/pages/admin/catalog/Index.vue`
- Create: `resources/css/admin-catalog.css`
- Modify: `resources/css/app.css`
- Modify: `tests/frontend/adminLayout.test.ts`
- Create: `tests/frontend/adminCatalogIndex.test.ts`
- Modify: `tests/frontend/translation-parity.test.mjs`

**Interfaces:**

- Consumes: safe index props and `can` flags from Task 4.
- Produces: `CatalogStatus`, `AdminCatalogCategoryData`, `AdminCatalogPackageData`, and `AdminCatalogIndexProps` TypeScript types.
- Produces: a `/admin/catalog` sidebar link and responsive catalog index.
- Produces: `CatalogStatusBadge` using text plus styling, not color alone.

- [ ] **Step 1: Write failing mounted component tests**

Test both locale labels, three states, empty content, category/package counts, lifecycle buttons from `can` flags, focus order, and absence of forbidden imports or storage APIs.

```ts
const wrapper = mount(AdminCatalogIndex, {
  props: { categories, packages },
  global: inertiaStubs(),
});

expect(wrapper.get('[data-catalog-status="draft"]').text()).toBe("Draft");
expect(wrapper.get('a[href="/admin/catalog/packages/create"]').text()).toBe(
  "Create package",
);
expect(wrapper.find("[data-catalog-empty]").exists()).toBe(false);
```

- [ ] **Step 2: Run frontend tests and confirm RED**

```bash
npm run test:frontend -- --run tests/frontend/adminLayout.test.ts tests/frontend/adminCatalogIndex.test.ts tests/frontend/translation-parity.test.mjs
```

Expected: missing types, component, page, link, and translations.

- [ ] **Step 3: Add immutable TypeScript contracts and translations**

Use readonly fields and readonly arrays. Add matching `ad.catalog.*` keys to both locale maps in the same edit, including statuses, actions, confirmations, errors, empty states, form labels, upload help, and processing text.

- [ ] **Step 4: Implement navigation, status badge, and index**

Keep booking navigation intact. Add a Catalog link with active state based on the current Inertia URL. Render semantic lists or tables with mobile cards, explicit actions, and a warning when a package is published but hidden by its category.

- [ ] **Step 5: Add catalog-specific CSS**

Use the existing admin color and spacing variables. Provide 44-pixel minimum targets, visible focus, narrow-screen reflow, state text, and reduced-motion behavior. Do not enlarge `admin.css`; keep this feature in `admin-catalog.css`.

- [ ] **Step 6: Run mounted tests, types, and formatting**

```bash
npm run test:frontend -- --run tests/frontend/adminLayout.test.ts tests/frontend/adminCatalogIndex.test.ts tests/frontend/translation-parity.test.mjs
npm run types:check
npx prettier --check resources/js/types/index.ts resources/js/layouts/admin/AdminLayout.vue resources/js/pages/admin/catalog/Index.vue resources/js/components/admin/catalog/CatalogStatusBadge.vue resources/js/data/translations.ts resources/css/admin-catalog.css resources/css/app.css
```

Expected: all pass.

- [ ] **Step 7: Commit Task 7**

```bash
git add resources/js/types/index.ts resources/js/layouts/admin/AdminLayout.vue resources/js/data/translations.ts resources/js/components/admin/catalog/CatalogStatusBadge.vue resources/js/pages/admin/catalog/Index.vue resources/css/admin-catalog.css resources/css/app.css tests/frontend/adminLayout.test.ts tests/frontend/adminCatalogIndex.test.ts tests/frontend/translation-parity.test.mjs
git commit -m "feat: add the admin catalog index"
```

---

### Task 8: Build the category editor and lifecycle confirmations

**Files:**

- Create: `resources/js/components/admin/catalog/CatalogImageInput.vue`
- Create: `resources/js/components/admin/catalog/CatalogLifecycleActions.vue`
- Create: `resources/js/components/admin/catalog/CategoryEditor.vue`
- Modify: `resources/js/pages/admin/catalog/Index.vue`
- Create: `tests/frontend/adminCatalogCategoryForm.test.ts`

**Interfaces:**

- Consumes: category request contract from Task 5.
- Produces: `CatalogImageInput` emitting `File | null` and removal state.
- Produces: `CatalogLifecycleActions` posting `{ version }` to explicit lifecycle routes.
- Produces: `CategoryEditor` sending exact multipart category payloads.

- [ ] **Step 1: Write failing category form tests**

Exercise real inputs and file selection. Assert trimmed values, exact submitted payload, error associations, processing disablement, published slug lock, image validation help, and alert-dialog focus restoration.

```ts
await wrapper.get("#category-name-id").setValue(" Perawatan Tubuh ");
await wrapper.get("#category-name-en").setValue(" Body Care ");
await wrapper.get("form").trigger("submit");

expect(inertia.posts[0]).toMatchObject({
  url: "/admin/catalog/categories",
  data: expect.objectContaining({
    slug: "body-care",
    nameId: "Perawatan Tubuh",
    nameEn: "Body Care",
  }),
});
```

- [ ] **Step 2: Run the category component test and confirm RED**

```bash
npm run test:frontend -- --run tests/frontend/adminCatalogCategoryForm.test.ts
```

Expected: components do not exist.

- [ ] **Step 3: Implement image input and category form**

Create object URLs only for local previews and revoke them on replacement and unmount. Accept `image/jpeg,image/png,image/webp`. Browser checks improve feedback but never replace server validation.

Build `FormData` explicitly. Omit `version` on create. Send `_method=patch` with `version` on update because file-bearing Inertia updates use POST method spoofing reliably across PHP upload handling.

- [ ] **Step 4: Implement lifecycle confirmations**

Use `role="alertdialog"`, move focus to the dialog heading, trap focus, close on Escape, restore trigger focus, disable repeat submission, and show archive versus permanent-delete copy.

- [ ] **Step 5: Run tests, types, and formatting**

```bash
npm run test:frontend -- --run tests/frontend/adminCatalogCategoryForm.test.ts tests/frontend/adminCatalogIndex.test.ts
npm run types:check
npx prettier --check resources/js/components/admin/catalog/CatalogImageInput.vue resources/js/components/admin/catalog/CatalogLifecycleActions.vue resources/js/components/admin/catalog/CategoryEditor.vue resources/js/pages/admin/catalog/Index.vue tests/frontend/adminCatalogCategoryForm.test.ts
```

Expected: all pass.

- [ ] **Step 6: Commit Task 8**

```bash
git add resources/js/components/admin/catalog/CatalogImageInput.vue resources/js/components/admin/catalog/CatalogLifecycleActions.vue resources/js/components/admin/catalog/CategoryEditor.vue resources/js/pages/admin/catalog/Index.vue tests/frontend/adminCatalogCategoryForm.test.ts
git commit -m "feat: add the admin category editor"
```

---

### Task 9: Build the aggregate package editor

**Files:**

- Create: `resources/js/components/admin/catalog/PackageChoiceRows.vue`
- Create: `resources/js/components/admin/catalog/PackageGalleryInput.vue`
- Create: `resources/js/pages/admin/catalog/packages/Form.vue`
- Modify: `resources/js/pages/admin/catalog/Index.vue`
- Create: `tests/frontend/adminCatalogPackageForm.test.ts`

**Interfaces:**

- Consumes: package create/edit props and aggregate write contract from Task 6.
- Produces: immutable local `CatalogChoiceInput[]` updates from `PackageChoiceRows`.
- Produces: retained gallery ID list plus new `File[]` from `PackageGalleryInput`.
- Produces: exact multipart aggregate submission to create or update routes.

- [ ] **Step 1: Write failing aggregate editor tests**

Mount the page and exercise bilingual fields, category selection, VIP state, cover, gallery, add/remove option rows, enum select, numeric inputs, published code lock, six-image cap, errors, stale version, processing state, lifecycle actions, and locale switch.

```ts
await wrapper.get('[data-add-variant]').trigger("click");
await wrapper.get('[name="variants.0.code"]').setValue("balinese-regular-60");
await wrapper.get('[name="variants.0.mode"]').setValue("regular");
await wrapper.get('[name="variants.0.durationMinutes"]').setValue("60");
await wrapper.get('[name="variants.0.priceIdr"]').setValue("175000");
await wrapper.get("form").trigger("submit");

const form = inertia.posts[0].data as FormData;
expect(form.get("variants[0][code]")).toBe("balinese-regular-60");
expect(form.get("variants[0][priceIdr]")).toBe("175000");
expect(form.get("isVip")).toBe("0");
```

- [ ] **Step 2: Run the package form test and confirm RED**

```bash
npm run test:frontend -- --run tests/frontend/adminCatalogPackageForm.test.ts
```

Expected: components and page are absent.

- [ ] **Step 3: Implement immutable option-row editing**

Every add, edit, and remove emits a new array and new changed row object. Keep row keys separate from server IDs so new unsaved rows have stable browser identity. Disable code editing when the row has already been published.

- [ ] **Step 4: Implement retained and uploaded gallery editing**

Keep server-issued retention IDs separate from display URLs. Revoke preview object URLs. Stop at six combined retained and new images. Removing a retained item removes only its ID from the next request.

- [ ] **Step 5: Implement the package form and exact multipart payload**

Append every scalar and nested field explicitly. Append files as `cover` and `galleryUploads[]`. Append retained IDs as `retainedGalleryIds[]`. Use method spoofing for updates. Never append public image URLs, raw paths, server errors, or calculated prices.

- [ ] **Step 6: Run mounted tests, types, and formatting**

```bash
npm run test:frontend -- --run tests/frontend/adminCatalogPackageForm.test.ts tests/frontend/adminCatalogIndex.test.ts
npm run types:check
npx prettier --check resources/js/components/admin/catalog/PackageChoiceRows.vue resources/js/components/admin/catalog/PackageGalleryInput.vue resources/js/pages/admin/catalog/packages/Form.vue resources/js/pages/admin/catalog/Index.vue tests/frontend/adminCatalogPackageForm.test.ts
```

Expected: all pass.

- [ ] **Step 7: Commit Task 9**

```bash
git add resources/js/components/admin/catalog/PackageChoiceRows.vue resources/js/components/admin/catalog/PackageGalleryInput.vue resources/js/pages/admin/catalog/packages/Form.vue resources/js/pages/admin/catalog/Index.vue tests/frontend/adminCatalogPackageForm.test.ts
git commit -m "feat: add the admin package editor"
```

---

### Task 10: Replace the two-package public layout with the dynamic catalog

**Files:**

- Modify: `resources/js/types/index.ts`
- Create: `resources/js/pages/Services.vue`
- Modify: `resources/js/pages/Home.vue`
- Modify: `resources/js/pages/ServiceDetail.vue`
- Modify: `resources/js/components/PackageMenuCard.vue`
- Modify: `resources/js/layouts/MainLayout.vue`
- Modify: `resources/js/data/translations.ts`
- Create: `resources/css/public-catalog.css`
- Modify: `resources/css/app.css`
- Modify: `tests/frontend/serviceCatalog.test.ts`
- Modify: `tests/frontend/officialMenuComponents.test.ts`
- Modify: `tests/frontend/publicPages.test.ts`
- Modify: `tests/frontend/translation-parity.test.mjs`

**Interfaces:**

- Consumes: grouped `categories` and detail `related` props from Task 2.
- Produces: readonly `PublicCatalogCategoryData` TypeScript type.
- Produces: `/services` page grouped by category.
- Preserves: `PackageData` and `PackageConfigurator` public option contracts.

- [ ] **Step 1: Write failing dynamic rendering tests**

Test zero, one, and multiple categories and packages; category ordering; starting prices; VIP marker; grouped headings; related links; `/services` navigation; locale switching; image fallback; and no hard-coded package-index access.

```ts
const wrapper = mount(Home, {
  props: { categories: [massageCategory, bodyCategory] },
  global: inertiaStubs(),
});

expect(wrapper.findAll("[data-catalog-category]")).toHaveLength(2);
expect(wrapper.findAll("[data-package-card]")).toHaveLength(3);
expect(wrapper.text()).toContain("Body Care");
expect(wrapper.text()).not.toContain("undefined");
```

- [ ] **Step 2: Run public component tests and confirm RED**

```bash
npm run test:frontend -- --run tests/frontend/serviceCatalog.test.ts tests/frontend/officialMenuComponents.test.ts tests/frontend/publicPages.test.ts tests/frontend/translation-parity.test.mjs
```

Expected: Home still requires flat `packages`, detail still expects one `comparison`, and `Services.vue` is missing.

- [ ] **Step 3: Add public types and the grouped menu page**

Define:

```ts
export interface PublicCatalogCategoryData {
  readonly slug: string;
  readonly nameId: string;
  readonly nameEn: string;
  readonly image: string | null;
  readonly packages: readonly PackageData[];
}
```

`Services.vue` renders a localized heading and package grid for every category. It provides a useful empty state when no packages are published.

- [ ] **Step 4: Make Home and detail data-driven**

Remove `packageName(index)` and the fixed standard-versus-VIP comparison. Render category sections from props. Replace detail `comparison` with `related: readonly PackageLinkData[]` and link each related package.

Update the main Services navigation destination to `/services`; keep the homepage menu section ID so direct homepage anchors already in external links remain valid.

- [ ] **Step 5: Add focused catalog CSS and accessible behavior**

Use CSS grid with one-column mobile flow and no fixed package count. Ensure card controls remain reachable at 320 pixels, 200 percent zoom-equivalent width, and keyboard focus. Respect reduced motion.

- [ ] **Step 6: Run public regressions, types, and build**

```bash
npm run test:frontend -- --run tests/frontend/serviceCatalog.test.ts tests/frontend/officialMenuComponents.test.ts tests/frontend/publicPages.test.ts tests/frontend/translation-parity.test.mjs
npm run types:check
npm run build
```

Expected: all pass and no source test depends on exactly two package names.

- [ ] **Step 7: Commit Task 10**

```bash
git add resources/js/types/index.ts resources/js/pages/Services.vue resources/js/pages/Home.vue resources/js/pages/ServiceDetail.vue resources/js/components/PackageMenuCard.vue resources/js/layouts/MainLayout.vue resources/js/data/translations.ts resources/css/public-catalog.css resources/css/app.css tests/frontend/serviceCatalog.test.ts tests/frontend/officialMenuComponents.test.ts tests/frontend/publicPages.test.ts tests/frontend/translation-parity.test.mjs
git commit -m "feat: render the public catalog dynamically"
```

- [ ] **Step 8: Run the pre-browser code-quality and security review**

Dispatch fresh code-quality and security reviewers over Tasks 1 through 10. Fix every confirmed critical or high finding with a focused failing regression test before starting Playwright coverage. Re-run the affected Laravel or Vue task suites after each fix and commit only reviewed fixes with `fix: harden catalog management`.

---

### Task 11: Cover the complete administrator-to-customer browser journey

**Files:**

- Create: `tests/e2e/helpers/admin.ts`
- Create: `tests/e2e/admin-catalog.spec.ts`
- Create: `tests/e2e/fixtures/catalog/category.jpg`
- Create: `tests/e2e/fixtures/catalog/cover.png`
- Create: `tests/e2e/fixtures/catalog/gallery.webp`
- Modify: `tests/e2e/global-setup.ts`

**Interfaces:**

- Consumes: existing registration, verification, login, SQLite setup, and admin grant helpers.
- Produces: `registerVerifiedAdmin(page, email): Promise<void>`.
- Produces: one serial catalog journey that verifies persisted data through real Laravel, Inertia, Vue, and browser requests.

- [ ] **Step 1: Add small valid image fixtures**

Generate or commit non-client test images with dimensions at least 600 by 400 and each below 50 KB. Keep them free of EXIF identity data and secrets. Assert the fixture paths exist in global setup so missing binary files fail before the browser starts.

- [ ] **Step 2: Write the failing Playwright journey**

The test must:

1. create and verify an administrator;
2. create a category draft with an upload;
3. prove its unpublished package route is absent;
4. publish the category;
5. create a package draft with cover, gallery, variants, and one extension;
6. prove the draft is absent from home, `/services`, detail, and quote submission;
7. publish the package and prove all public surfaces show it;
8. enter the authenticated booking flow with its server-priced option;
9. archive it and prove public and quote removal;
10. restore it and prove it returns;
11. open two edit pages and prove the stale one cannot overwrite;
12. prove a verified customer receives 403 and no catalog admin data;
13. switch between Indonesian and English;
14. check mobile width and 200 percent zoom-equivalent reflow.

```ts
await page.goto("/admin/catalog/packages/create");
await page.getByLabel(/slug/i).fill("balinese-signature");
await page.getByLabel(/nama indonesia|indonesian name/i).fill("Balinese Signature");
await page.getByLabel(/nama inggris|english name/i).fill("Balinese Signature");
await page.getByLabel(/cover/i).setInputFiles(coverFixture);
await page.getByRole("button", { name: /save draft|simpan draf/i }).click();
await expect(page.getByText(/draft|draf/i)).toBeVisible();
```

- [ ] **Step 3: Run the E2E file and confirm RED**

```bash
npm run test:e2e -- tests/e2e/admin-catalog.spec.ts
```

Expected: failure at the first missing admin catalog UI or behavior.

- [ ] **Step 4: Add the admin helper and finish browser assertions**

Reuse `registerCustomer`, `verifyCustomer`, the existing password, and `php artisan admin:grant`. Do not create test-only catalog mutation routes. Use the same visible forms and lifecycle controls a real administrator uses.

- [ ] **Step 5: Run catalog and existing critical E2E flows**

```bash
npm run test:e2e -- tests/e2e/admin-catalog.spec.ts tests/e2e/service-catalog.spec.ts tests/e2e/booking-persistence.spec.ts tests/e2e/admin-bookings.spec.ts
```

Expected: all pass against the dedicated seeded SQLite database.

- [ ] **Step 6: Commit Task 11**

```bash
git add tests/e2e/helpers/admin.ts tests/e2e/admin-catalog.spec.ts tests/e2e/fixtures/catalog/category.jpg tests/e2e/fixtures/catalog/cover.png tests/e2e/fixtures/catalog/gallery.webp tests/e2e/global-setup.ts
git commit -m "test: cover admin catalog publishing"
```

---

### Task 12: Run full verification and independent reviews

**Files:**

- Modify only files required to fix failures found by the gates.
- Do not modify: `tests/frontend/bookingValidation.test.ts` unless its owner explicitly authorizes folding that separate edit into this phase.

**Interfaces:**

- Consumes: Tasks 1 through 11.
- Produces: a verified Phase 2B implementation with documented environment-only blockers.

- [ ] **Step 1: Run the complete Laravel and coverage gates**

```bash
php artisan test
php artisan test --coverage --min=80
```

Expected: the full Laravel suite passes. If no Xdebug or PCOV driver exists, record coverage as blocked by the environment and do not call it passing.

- [ ] **Step 2: Run frontend, browser, type, and build gates**

```bash
npm run test:frontend
npm run test:e2e
npm run types:check
npm run build
```

Expected: all pass.

- [ ] **Step 3: Run cache and migration gates**

```bash
php artisan route:cache
php artisan route:clear
php artisan config:cache
php artisan config:clear
php artisan migrate:fresh --seed --force --no-interaction
php artisan storage:link
```

Expected: every command succeeds, the public storage link exists, and official catalog data remains published.

- [ ] **Step 4: Run formatting, secret, and forbidden-runtime scans**

```bash
vendor/bin/pint --test app database/migrations database/seeders routes tests/Feature/Admin
npx prettier --check resources/js resources/css tests/frontend tests/e2e
rg -n "localStorage|sessionStorage|vue-router|RouterLink|useAuth" resources/js
rg -n "APP_KEY=|DB_PASSWORD=|API_KEY=|SECRET_KEY=|BEGIN (RSA|OPENSSH|EC) PRIVATE KEY" --glob "!vendor/**" --glob "!node_modules/**" .
```

Expected: owned files pass formatting; forbidden runtime imports and committed secrets return no matches. Record inherited unrelated formatting debt separately.

- [ ] **Step 5: Run dependency security audits**

```bash
composer audit
npm audit --audit-level=high
```

Expected: no known high-severity production vulnerability. Report registry or network failures as blockers rather than clean audits.

- [ ] **Step 6: Request code-quality and security reviews**

Dispatch fresh reviewers against the complete branch diff. Require them to check authorization before route binding, upload validation and deletion boundaries, query visibility, mass assignment, unique-race handling, stale writes, booking snapshot preservation, XSS, CSRF, rate limiting, accessibility, and missing tests.

- [ ] **Step 7: Fix every confirmed critical or high issue test-first**

For each issue, add the smallest failing regression test, run it to confirm RED, patch the implementation, rerun the focused test, then rerun the affected full gate. Do not weaken a correct test to make the build green.

- [ ] **Step 8: Review the exact final diff and working tree**

```bash
git diff --check
git status --short
git diff --stat bd0f21a..HEAD
git log --oneline bd0f21a..HEAD
```

Expected: only planned Phase 2B files and the known unrelated unstaged test edit are present. No generated screenshots, test databases, hot files, upload artifacts, or secrets are tracked.

- [ ] **Step 9: Commit verification fixes if any exist**

Stage only the exact reviewed fix paths, then commit:

```bash
git commit -m "fix: harden catalog publishing"
```

If no files changed, do not create an empty commit.

---

## Completion record

At handoff, report:

- commit range and current HEAD;
- migrations added and deployment commands;
- Laravel, frontend, E2E, type, build, cache, and audit results;
- coverage percentage or the exact missing-driver blocker;
- review findings and fixes;
- the untouched status of `tests/frontend/bookingValidation.test.ts`;
- confirmation that payment remains deferred.
