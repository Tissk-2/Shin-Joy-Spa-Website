# Official Menu Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the placeholder catalog with the two official Nezuko packages and deliver a responsive bilingual menu, package configurator, and verified-account booking handoff using exact official pricing.

**Architecture:** Keep `services` as the package aggregate and add normalized child records for base variants and mode-compatible extensions. Laravel resolves active catalog data and untrusted booking query parameters into allow-listed Inertia props; Vue renders reusable immutable selection controls on the home, detail, and booking pages. Booking persistence, admin CRUD, and payment remain outside this milestone.

**Tech Stack:** PHP 8.3+, Laravel 13, Fortify, Inertia 3, Vue 3.5, TypeScript, Vitest, Vue Test Utils, Playwright, SQLite/MySQL.

**Spec:** `docs/superpowers/plans/2026-09-03-official-menu-redesign-design.md`

## Global Constraints

- The complete public menu is exactly Nezuko and Nezuko VIP with the prices in the approved spec.
- `Extra 60 Menit` is valid only for regular treatment; `Extra 30 Menit` is valid only for double-therapist treatment.
- Package names and IDR prices do not change with locale; descriptions and interface labels support `id` and `en`.
- Booking access requires both `auth` and `verified` middleware.
- Laravel is authoritative for active choices and prices; never trust query-string or browser values.
- Do not store authentication, price, or booking history in local or session storage.
- Display the conditional hot-water disruption discount as policy text only; never deduct it automatically.
- Keep payment gateway, booking persistence, admin CRUD, schedules, capacity, cancellation, notifications, and authorization redesign out of scope.
- Do not edit the real `.env`, the legacy standalone `src/` tree, or unrelated dirty files.
- Add forward migrations; do not rewrite migrations that may already have run.
- Preserve WCAG AA contrast, visible keyboard focus, semantic headings, 44px touch targets, and reduced-motion behavior.
- Every task follows RED → GREEN → REFACTOR and commits only its owned files.

---

## File Map

### New backend files

- `app/Enums/TreatmentMode.php` — canonical regular/double-therapist values.
- `app/Models/ServiceVariant.php` — one selectable base duration and price.
- `app/Models/ServiceExtension.php` — one mode-compatible duration extension.
- `app/Support/BookingSelectionResolver.php` — converts untrusted query input into a valid active selection.
- `app/Support/SafeIntendedPath.php` — validates and preserves only local intended URLs through login and verification.
- `database/migrations/2026_09_03_000000_update_services_for_package_catalog.php` — package positioning flag and nullable legacy scalar columns.
- `database/migrations/2026_09_03_000001_create_service_variants_table.php` — normalized base offerings.
- `database/migrations/2026_09_03_000002_create_service_extensions_table.php` — normalized extensions.

### New frontend files

- `resources/js/lib/serviceSelection.ts` — immutable selection normalization, totals, and URL building.
- `resources/js/lib/media.ts` — one-shot local fallback for broken package images.
- `resources/js/components/PackageConfigurator.vue` — accessible mode, duration, extension, total, and CTA control.
- `resources/js/components/PackageMenuCard.vue` — editorial package presentation used by the homepage.
- `resources/css/menu.css` — isolated official-menu and responsive component styles.
- `tests/frontend/serviceSelection.test.ts` — pure selection behavior.
- `tests/frontend/officialMenuComponents.test.ts` — mounted package control behavior.
- `tests/e2e/helpers/auth.ts` — shared registration and verification helpers.

### Existing files with changed responsibility

- `database/seeders/ServiceCatalogSeeder.php` — seeds two official packages, eight variants, and four extensions; retires known placeholders.
- `app/Models/Service.php` — package aggregate and active-child relationships.
- `app/Support/ServiceCatalogPresenter.php` — allow-listed package/variant/extension props.
- `app/Http/Controllers/HomeController.php` — supplies ordered packages only.
- `app/Http/Controllers/ServiceController.php` — supplies one package plus the comparison package.
- `app/Http/Controllers/BookingController.php` — supplies active packages and a normalized selection.
- `app/Http/Middleware/HandleInertiaRequests.php` — shares compact package links instead of categories.
- `app/Http/Responses/LoginResponse.php` and `app/Http/Controllers/Auth/VerifyEmailController.php` — safely preserve configured booking intent.
- `routes/web.php` — menu anchor redirect and verified booking protection.
- `resources/js/types/index.ts` — package and selection transport contracts.
- `resources/js/lib/booking.ts` — validates package configuration with customer/date/time fields.
- `resources/js/pages/Home.vue` — official-menu editorial layout.
- `resources/js/pages/ServiceDetail.vue` — selectable package detail layout.
- `resources/js/pages/Booking.vue` — authenticated configuration and review page.
- `resources/js/layouts/MainLayout.vue` — menu navigation and two-package footer.
- `resources/js/data/translations.ts` — exact Indonesian/English menu, policy, and booking-review copy.
- `resources/css/app.css` — imports `menu.css`.
- Catalog feature, component, and E2E tests — replace scalar placeholder assumptions.

---

### Task 1: Add the normalized package-offering domain

**Files:**

- Create: `app/Enums/TreatmentMode.php`
- Create: `app/Models/ServiceVariant.php`
- Create: `app/Models/ServiceExtension.php`
- Create: `database/migrations/2026_09_03_000000_update_services_for_package_catalog.php`
- Create: `database/migrations/2026_09_03_000001_create_service_variants_table.php`
- Create: `database/migrations/2026_09_03_000002_create_service_extensions_table.php`
- Modify: `app/Models/Service.php`
- Test: `tests/Feature/ServiceCatalogPersistenceTest.php`

**Interfaces:**

- Produces: `TreatmentMode::Regular`, `TreatmentMode::DoubleTherapist`.
- Produces: `Service::variants(): HasMany` and `Service::extensions(): HasMany`.
- Produces: active ordered scopes on both child models.
- Produces: integer `duration_minutes`, integer `price_idr`, boolean `is_active`, and enum `treatment_mode` casts.

- [ ] **Step 1: Replace scalar-domain persistence expectations with failing relationship tests**

Add tests that create a service, variant, and extension and assert enum/integer casts, deterministic ordering, unique package/mode/duration constraints, and aggregate-owned child deletion:

```php
public function test_package_variants_and_extensions_are_typed_ordered_and_owned(): void
{
    $category = ServiceCategory::query()->create([
        'slug' => 'package', 'name_id' => 'Paket', 'name_en' => 'Packages',
        'image_path' => '/img/package.jpg', 'is_active' => true, 'display_order' => 1,
    ]);
    $service = Service::query()->create($this->packageAttributes($category, 'nezuko'));

    ServiceVariant::query()->create([
        'service_id' => $service->id, 'code' => 'nezuko-regular-90',
        'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 90,
        'price_idr' => 265000, 'is_active' => true, 'display_order' => 2,
    ]);
    ServiceVariant::query()->create([
        'service_id' => $service->id, 'code' => 'nezuko-regular-60',
        'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
        'price_idr' => 199000, 'is_active' => true, 'display_order' => 1,
    ]);
    ServiceExtension::query()->create([
        'service_id' => $service->id, 'code' => 'nezuko-regular-extra-60',
        'treatment_mode' => TreatmentMode::Regular, 'duration_minutes' => 60,
        'price_idr' => 75000, 'is_active' => true, 'display_order' => 1,
    ]);

    $service->load(['variants' => fn ($query) => $query->active(), 'extensions' => fn ($query) => $query->active()]);

    $this->assertSame([60, 90], $service->variants->pluck('duration_minutes')->all());
    $this->assertSame(TreatmentMode::Regular, $service->variants->first()->treatment_mode);
    $this->assertSame(75000, $service->extensions->first()->price_idr);
    $service->delete();
    $this->assertDatabaseMissing('service_variants', ['code' => 'nezuko-regular-60']);
    $this->assertDatabaseMissing('service_extensions', ['code' => 'nezuko-regular-extra-60']);
}

/** @return array<string, mixed> */
private function packageAttributes(ServiceCategory $category, string $slug): array
{
    return [
        'category_id' => $category->id,
        'slug' => $slug,
        'name_id' => 'Nezuko',
        'name_en' => 'Nezuko',
        'description_id' => 'Deskripsi',
        'description_en' => 'Description',
        'long_description_id' => 'Deskripsi lengkap',
        'long_description_en' => 'Full description',
        'good_for_id' => 'Relaksasi',
        'good_for_en' => 'Relaxation',
        'duration_minutes' => null,
        'price_idr' => null,
        'image_path' => '/img/massage.jpg',
        'gallery_paths' => ['massage.jpg'],
        'popularity' => 100,
        'is_vip' => false,
        'is_active' => true,
        'display_order' => 1,
    ];
}
```

- [ ] **Step 2: Run the focused persistence test and verify RED**

Run: `php artisan test tests/Feature/ServiceCatalogPersistenceTest.php`

Expected: FAIL because the enum, child models, tables, and relationships do not exist.

- [ ] **Step 3: Add the enum and forward migrations**

Create the enum exactly as:

```php
<?php

namespace App\Enums;

enum TreatmentMode: string
{
    case Regular = 'regular';
    case DoubleTherapist = 'double_therapist';
}
```

The service-update migration must add `services.is_vip` with default `false` and make the legacy `duration_minutes` and `price_idr` columns nullable. The child-table migrations use `foreignId('service_id')->constrained()->cascadeOnDelete()`, globally unique `code`, `treatment_mode` length 32, unsigned integer duration, unsigned big integer price, active flag, display order, timestamps, and these composite unique keys:

```php
$table->unique(['service_id', 'treatment_mode', 'duration_minutes'], 'service_variant_choice_unique');
$table->unique(['service_id', 'treatment_mode', 'duration_minutes'], 'service_extension_choice_unique');
```

On rollback, replace null legacy scalar values with `0`, restore both columns to non-null, and then drop `is_vip`; child migrations drop their own tables in reverse order.

- [ ] **Step 4: Add focused child models and aggregate relationships**

Each child model uses fillable fields matching its table, enum/integer/boolean casts, `service(): BelongsTo`, and this scope:

```php
public function scopeActive(Builder $query): void
{
    $query->where('is_active', true)->orderBy('display_order')->orderBy('id');
}
```

Add `is_vip` to `Service::$fillable` and casts, plus:

```php
public function variants(): HasMany
{
    return $this->hasMany(ServiceVariant::class)->orderBy('display_order')->orderBy('id');
}

public function extensions(): HasMany
{
    return $this->hasMany(ServiceExtension::class)->orderBy('display_order')->orderBy('id');
}
```

Extend `scopePubliclyVisible()` with `whereHas('variants', fn (Builder $query) => $query->where('is_active', true))` so packages without an active base choice cannot appear.

- [ ] **Step 5: Run migrations and the focused test**

Run: `php artisan migrate:fresh --env=testing --force`

Run: `php artisan test tests/Feature/ServiceCatalogPersistenceTest.php`

Expected: PASS.

- [ ] **Step 6: Commit the domain checkpoint**

```bash
git add app/Enums/TreatmentMode.php app/Models/Service.php app/Models/ServiceVariant.php app/Models/ServiceExtension.php database/migrations/2026_09_03_000000_update_services_for_package_catalog.php database/migrations/2026_09_03_000001_create_service_variants_table.php database/migrations/2026_09_03_000002_create_service_extensions_table.php tests/Feature/ServiceCatalogPersistenceTest.php
git commit -m "feat: add package offering domain"
```

### Task 2: Seed the exact official menu idempotently

**Files:**

- Modify: `database/seeders/ServiceCatalogSeeder.php`
- Test: `tests/Feature/ServiceCatalogPersistenceTest.php`

**Interfaces:**

- Consumes: `TreatmentMode`, `ServiceVariant`, and `ServiceExtension` from Task 1.
- Produces: active `nezuko` and `nezuko-vip` packages with exact stable child codes.
- Produces: eight active variants and four active extensions.

- [ ] **Step 1: Write failing exact-catalog and targeted-retirement tests**

Replace the old 4-category/12-service assertions with:

```php
public function test_catalog_seeder_persists_the_exact_official_menu_idempotently(): void
{
    $this->seed(ServiceCatalogSeeder::class);
    $this->seed(ServiceCatalogSeeder::class);

    $this->assertSame(['nezuko', 'nezuko-vip'], Service::query()->publiclyVisible()->pluck('slug')->all());
    $this->assertSame(8, ServiceVariant::query()->where('is_active', true)->count());
    $this->assertSame(4, ServiceExtension::query()->where('is_active', true)->count());
    $this->assertDatabaseHas('service_variants', [
        'code' => 'nezuko-regular-60', 'duration_minutes' => 60, 'price_idr' => 199000,
    ]);
    $this->assertDatabaseHas('service_variants', [
        'code' => 'nezuko-vip-double-60', 'duration_minutes' => 60, 'price_idr' => 350000,
    ]);
    $this->assertDatabaseHas('service_extensions', [
        'code' => 'nezuko-regular-extra-60', 'duration_minutes' => 60, 'price_idr' => 75000,
    ]);
    $this->assertDatabaseHas('service_extensions', [
        'code' => 'nezuko-vip-double-extra-30', 'duration_minutes' => 30, 'price_idr' => 100000,
    ]);
}
```

Add a second test that inserts an unknown active service before seeding, verifies it remains untouched, and verifies all twelve known placeholder slugs are inactive.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php artisan test tests/Feature/ServiceCatalogPersistenceTest.php`

Expected: FAIL because the seeder still creates the placeholder catalog.

- [ ] **Step 3: Replace the seeder data with the approved official package records**

Use one transaction. Upsert the `package` category, make it active, and deactivate only the known `massage`, `facial`, and `body` categories. Upsert package records with:

```php
$packages = [
    'nezuko' => [
        'name_id' => 'Nezuko', 'name_en' => 'Nezuko', 'is_vip' => false,
        'description_id' => 'Pijatan lembut dengan perpaduan pijat tradisional dan pijat Thailand, menggunakan minyak atau tanpa minyak, yang menghadirkan sensasi relaksasi.',
        'description_en' => 'A gentle treatment combining traditional and Thai massage techniques, with or without oil, designed to create a deeply relaxing experience.',
        'good_for_id' => 'Membantu memperlancar peredaran darah dan meningkatkan kualitas tidur.',
        'good_for_en' => 'Helps support healthy circulation and improve sleep quality.',
        'image_path' => '/img/massage.jpg',
        'gallery_paths' => ['massage.jpg', 'oil.jpg', 'about.jpg'],
        'duration_minutes' => null, 'price_idr' => null, 'display_order' => 1,
    ],
    'nezuko-vip' => [
        'name_id' => 'Nezuko VIP', 'name_en' => 'Nezuko VIP', 'is_vip' => true,
        'description_id' => 'Perpaduan pijat tradisional dan pijat Thailand, menggunakan minyak atau tanpa minyak, yang disesuaikan dengan kebutuhan dan keluhan klien untuk menghadirkan sensasi relaksasi.',
        'description_en' => "A tailored blend of traditional and Thai massage, with or without oil, adjusted to the client's needs and preferences for a deeply relaxing experience.",
        'good_for_id' => 'Membantu memperlancar peredaran darah dan meningkatkan kualitas tidur.',
        'good_for_en' => 'Helps support healthy circulation and improve sleep quality.',
        'image_path' => '/img/package.jpg',
        'gallery_paths' => ['package.jpg', 'hands.jpg', 'oil.jpg'],
        'duration_minutes' => null, 'price_idr' => null, 'display_order' => 2,
    ],
];
```

Use the same description as `long_description_*`, set popularity to 100/99, and keep the nullable legacy scalar duration/price columns empty so child variants are the only pricing source.

- [ ] **Step 4: Upsert exact child choices and retire only known stale data**

Use these stable rows:

```php
$variants = [
    ['service' => 'nezuko', 'code' => 'nezuko-regular-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 199000],
    ['service' => 'nezuko', 'code' => 'nezuko-regular-90', 'mode' => TreatmentMode::Regular, 'duration' => 90, 'price' => 265000],
    ['service' => 'nezuko', 'code' => 'nezuko-regular-120', 'mode' => TreatmentMode::Regular, 'duration' => 120, 'price' => 315000],
    ['service' => 'nezuko', 'code' => 'nezuko-double-60', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 60, 'price' => 250000],
    ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 299000],
    ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-90', 'mode' => TreatmentMode::Regular, 'duration' => 90, 'price' => 349000],
    ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-120', 'mode' => TreatmentMode::Regular, 'duration' => 120, 'price' => 499000],
    ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-double-60', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 60, 'price' => 350000],
];

$extensions = [
    ['service' => 'nezuko', 'code' => 'nezuko-regular-extra-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 75000],
    ['service' => 'nezuko', 'code' => 'nezuko-double-extra-30', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 30, 'price' => 100000],
    ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-regular-extra-60', 'mode' => TreatmentMode::Regular, 'duration' => 60, 'price' => 100000],
    ['service' => 'nezuko-vip', 'code' => 'nezuko-vip-double-extra-30', 'mode' => TreatmentMode::DoubleTherapist, 'duration' => 30, 'price' => 100000],
];
```

Resolve every row through its exact `service` slug, `updateOrCreate(['code' => $row['code']], values)`, and deactivate child codes belonging to either official package when they are absent from these lists. Deactivate the twelve known placeholder service slugs with `whereIn`; do not delete or deactivate unknown records.

- [ ] **Step 5: Run persistence tests twice**

Run: `php artisan test tests/Feature/ServiceCatalogPersistenceTest.php`

Expected: PASS, including the second seeder invocation and unknown-record preservation.

- [ ] **Step 6: Commit the official data checkpoint**

```bash
git add database/seeders/ServiceCatalogSeeder.php tests/Feature/ServiceCatalogPersistenceTest.php
git commit -m "feat: seed official Nezuko menu"
```

### Task 3: Expose safe package-shaped Inertia contracts

**Files:**

- Modify: `app/Support/ServiceCatalogPresenter.php`
- Modify: `app/Http/Controllers/HomeController.php`
- Modify: `app/Http/Controllers/ServiceController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/types/index.ts`
- Modify: `tests/Feature/ServiceCatalogPagesTest.php`
- Modify: `tests/Feature/InertiaSharedDataTest.php`

**Interfaces:**

- Produces TS `TreatmentMode`, `PackageVariantData`, `PackageExtensionData`, `PackageData`, and `PackageLinkData`.
- Produces `ServiceCatalogPresenter::package(Service): array` and `ServiceCatalogPresenter::link(Service): array`.
- Home props: `{ packages: PackageData[] }`.
- Detail props: `{ service: PackageData, comparison: PackageLinkData|null }`.
- Shared props: `{ catalog: { packages: PackageLinkData[] } }`.

- [ ] **Step 1: Rewrite page-contract tests for package data and verify they fail**

Seed the official catalog and assert exact keys:

```php
$response->assertOk()->assertInertia(fn (Assert $page) => $page
    ->component('Home')
    ->where('packages.0.slug', 'nezuko')
    ->where('packages.1.slug', 'nezuko-vip')
    ->where('packages.0.variants.1.price', 265000)
    ->where('packages.1.extensions.1.duration', 30)
    ->has('packages', 2));

$this->assertSame([
    'slug', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn',
    'benefitId', 'benefitEn', 'image', 'gallery', 'isVip', 'variants', 'extensions',
], array_keys($props['packages'][0]));
$this->assertSame(['code', 'mode', 'duration', 'price'], array_keys($props['packages'][0]['variants'][0]));
```

Assert inactive children are absent, a service with no active variant is absent, internal IDs/timestamps are absent, detail includes only the opposite package link, and shared props contain only `slug`, `nameId`, `nameEn`, `isVip`.

- [ ] **Step 2: Run contract tests and verify RED**

Run: `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/InertiaSharedDataTest.php`

Expected: FAIL on the old category/scalar payload.

- [ ] **Step 3: Define the exact TypeScript transport contracts**

Replace public category/scalar service types with:

```ts
export type TreatmentMode = "regular" | "double_therapist";

export interface PackageVariantData {
  code: string;
  mode: TreatmentMode;
  duration: number;
  price: number;
}

export interface PackageExtensionData {
  code: string;
  mode: TreatmentMode;
  duration: number;
  price: number;
}

export interface PackageLinkData {
  slug: string;
  nameId: string;
  nameEn: string;
  isVip: boolean;
}

export interface PackageData extends PackageLinkData {
  descId: string;
  descEn: string;
  longId: string;
  longEn: string;
  benefitId: string | null;
  benefitEn: string | null;
  image: string;
  gallery: string[];
  variants: PackageVariantData[];
  extensions: PackageExtensionData[];
}
```

Change `AppPageProps.catalog` to `{ packages: PackageLinkData[] }`.

- [ ] **Step 4: Replace presenter serialization with package serialization**

`package()` must map only the allow-listed fields above, map enum modes with `->value`, cast numeric values to integers, and normalize gallery paths. `link()` returns only compact footer fields. Never call `toArray()` on an Eloquent model.

- [ ] **Step 5: Update package queries and eager loading**

Home loads publicly visible services with active ordered variants/extensions. Detail resolves one publicly visible slug, eagerly loads active children, and queries the first other visible package for `comparison`. Shared data lazily queries compact visible package links. Remove public category props and the related-service collection.

- [ ] **Step 6: Run the focused backend contracts**

Run: `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/InertiaSharedDataTest.php tests/Feature/PublicPagesTest.php`

Expected: PASS.

- [ ] **Step 7: Commit the transport checkpoint**

```bash
git add app/Support/ServiceCatalogPresenter.php app/Http/Controllers/HomeController.php app/Http/Controllers/ServiceController.php app/Http/Middleware/HandleInertiaRequests.php resources/js/types/index.ts tests/Feature/ServiceCatalogPagesTest.php tests/Feature/InertiaSharedDataTest.php tests/Feature/PublicPagesTest.php
git commit -m "refactor: expose package catalog contracts"
```

### Task 4: Protect booking and preserve only safe configured intent

**Files:**

- Create: `app/Support/BookingSelectionResolver.php`
- Create: `app/Support/SafeIntendedPath.php`
- Modify: `app/Http/Controllers/BookingController.php`
- Modify: `app/Http/Responses/LoginResponse.php`
- Modify: `app/Http/Controllers/Auth/VerifyEmailController.php`
- Modify: `app/Http/Controllers/Auth/EmailVerificationPromptController.php`
- Modify: `app/Http/Controllers/Auth/EmailVerificationNotificationController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/ServiceCatalogPagesTest.php`
- Modify: `tests/Feature/PublicPagesTest.php`
- Modify: `tests/Feature/Auth/AuthenticationTest.php`
- Modify: `tests/Feature/Auth/EmailVerificationTest.php`

**Interfaces:**

- Produces `BookingSelectionResolver::resolve(Request, Collection): array{service:string,mode:string,duration:int|null,extension:string}`.
- Produces `SafeIntendedPath::peek(Request): ?string`, `pull(Request): ?string`, and `retain(Request): void`.
- Booking props: `{ packages: PackageData[], selection: { service: string, mode: string, duration: int|null, extension: string } }`.

- [ ] **Step 1: Add failing access, normalization, and verification-intent tests**

Cover these exact cases:

```php
$this->get('/booking?service=nezuko&mode=regular&duration=90')
    ->assertRedirect('/login');

$unverified = User::factory()->unverified()->create();
$this->actingAs($unverified)
    ->get('/booking?service=nezuko&mode=regular&duration=90')
    ->assertRedirect('/verify-email');

$verified = User::factory()->create(['email_verified_at' => now()]);
$this->actingAs($verified)
    ->get('/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60')
    ->assertInertia(fn (Assert $page) => $page
        ->where('selection.service', 'nezuko')
        ->where('selection.mode', 'regular')
        ->where('selection.duration', 90)
        ->where('selection.extension', 'nezuko-regular-extra-60'));
```

Also assert that a double-therapist selection cannot accept the regular extension, inactive codes clear safely, `duration[]=90` clears safely, a configured internal intended URL survives login plus verification, and `https://attacker.example` / `//attacker.example` intended URLs are discarded.

- [ ] **Step 2: Run focused security behavior and verify RED**

Run: `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/EmailVerificationTest.php`

Expected: FAIL because booking is public, selection is scalar, and unverified login forgets intended state.

- [ ] **Step 3: Add the immutable server-side selection resolver**

The resolver must validate progressively: retain a valid active service; retain a mode only when that service has active variants in the mode; retain duration only when an active variant exactly matches; retain extension only when an active extension code belongs to the same service and mode. Parse duration only from a scalar string matching `/^\d{1,3}$/`.

Return a fresh array on every call:

```php
return [
    'service' => $service?->slug ?? '',
    'mode' => $mode?->value ?? '',
    'duration' => $variant?->duration_minutes,
    'extension' => $extension?->code ?? '',
];
```

- [ ] **Step 4: Protect the route and update the controller**

Apply `->middleware(['auth', 'verified'])` to `GET /booking`. Query active packages with active children, pass presenter package arrays, and pass the resolver result as `selection`. Update `/services` to redirect to `/#menu`.

- [ ] **Step 5: Extract one safe-intended-path policy and use it across login/verification**

Move the current origin/path validation out of `LoginResponse` into `SafeIntendedPath`. `peek()` reads without consuming, `pull()` validates then removes, and `retain()` removes the session key only when it is unsafe. For unverified login call `retain()` and redirect to verification; for verified login call `pull()`. After signed email verification, call `pull()` and redirect to that local configured URL, falling back to `/profile?verified=1`. Use the same policy when an already-verified user reaches the verification prompt or requests another verification notification, so those endpoints cannot bypass the safe redirect rules.

- [ ] **Step 6: Run focused backend and security tests**

Run: `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/PublicPagesTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/EmailVerificationTest.php`

Expected: PASS with safe query preservation and hostile redirect rejection.

- [ ] **Step 7: Commit the protected handoff checkpoint**

```bash
git add app/Support/BookingSelectionResolver.php app/Support/SafeIntendedPath.php app/Http/Controllers/BookingController.php app/Http/Responses/LoginResponse.php app/Http/Controllers/Auth/VerifyEmailController.php app/Http/Controllers/Auth/EmailVerificationPromptController.php app/Http/Controllers/Auth/EmailVerificationNotificationController.php routes/web.php tests/Feature/ServiceCatalogPagesTest.php tests/Feature/PublicPagesTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/EmailVerificationTest.php
git commit -m "feat: protect configured booking handoff"
```

### Task 5: Build immutable frontend selection controls

**Files:**

- Create: `resources/js/lib/serviceSelection.ts`
- Create: `resources/js/lib/media.ts`
- Create: `resources/js/components/PackageConfigurator.vue`
- Create: `resources/js/components/PackageMenuCard.vue`
- Create: `tests/frontend/serviceSelection.test.ts`
- Create: `tests/frontend/officialMenuComponents.test.ts`

**Interfaces:**

- Produces `ServiceSelection` with `{ service, mode, duration, extension }`.
- Produces `emptySelection(service)`, `normalizeSelection(package, candidate)`, `selectionTotal(package, selection)`, and `bookingUrl(selection)`.
- Produces `applyImageFallback(event, fallback = "/img/massage.jpg")` without exposing filesystem paths or looping.
- Produces `PackageConfigurator` with `v-model: ServiceSelection`, `servicePackage: PackageData`, `compact?: boolean`, and `showBookingLink?: boolean`.
- Produces `PackageMenuCard` with `servicePackage: PackageData`.

- [ ] **Step 1: Write pure failing tests for compatibility, totals, and immutability**

Use a complete Nezuko fixture and assert:

```ts
const original = structuredClone(nezuko);
const selected = normalizeSelection(nezuko, {
  service: "nezuko",
  mode: "regular",
  duration: 90,
  extension: "nezuko-regular-extra-60",
});

expect(selectionTotal(nezuko, selected)).toEqual({
  duration: 150,
  price: 340000,
});
expect(
  normalizeSelection(nezuko, { ...selected, mode: "double_therapist" }),
).toEqual({
  service: "nezuko",
  mode: "double_therapist",
  duration: null,
  extension: "",
});
expect(bookingUrl(selected)).toBe(
  "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
);
expect(nezuko).toEqual(original);
```

Also dispatch an `error` event to an image using `applyImageFallback`, assert it changes the URL to `/img/massage.jpg`, marks `data-fallback-applied="true"`, and does not change the URL on a second error.

- [ ] **Step 2: Run utility tests and verify RED**

Run: `npx vitest run tests/frontend/serviceSelection.test.ts`

Expected: FAIL because the module does not exist.

- [ ] **Step 3: Add pure selection functions**

Define `ServiceSelection` exactly as `{ service: string; mode: TreatmentMode | ""; duration: number | null; extension: string }`; match variants and extensions only by exact package/mode/code; construct URLs with `URLSearchParams`; return `{ duration: 0, price: 0 }` until a valid base variant exists. Every mode/package change returns a new selection object and clears incompatible descendants. `applyImageFallback` accepts only an `HTMLImageElement` current target and applies the local fallback once.

```ts
export function applyImageFallback(
  event: Event,
  fallback = "/img/massage.jpg",
): void {
  const image = event.currentTarget;

  if (!(image instanceof HTMLImageElement)) return;
  if (image.dataset.fallbackApplied === "true") return;

  image.dataset.fallbackApplied = "true";
  image.src = fallback;
}
```

- [ ] **Step 4: Write failing mounted component tests**

Mount `PackageConfigurator` with a `v-model` host and verify role-based behavior:

```ts
expect(
  wrapper.get('[role="radiogroup"][aria-label="Treatment type"]'),
).toBeTruthy();
await wrapper.get('button[data-mode="regular"]').trigger("click");
await wrapper.get('button[data-duration="90"]').trigger("click");
await wrapper.get('input[value="nezuko-regular-extra-60"]').setValue(true);
expect(wrapper.text()).toContain("Rp 340.000");
expect(wrapper.text()).toContain("150 min");
expect(wrapper.get("a[data-booking-cta]").attributes("href")).toBe(
  "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
);
```

Switch to double therapist and assert only 60 minutes and the 30-minute extension remain. Assert disabled CTA before a base duration, ID/EN labels, `aria-pressed`/radio state, a mobile CTA wrapper that becomes actionable only for a valid base selection, broken-image fallback, and no prop mutation.

- [ ] **Step 5: Add the reusable configurator and editorial card**

`PackageConfigurator` uses button groups for mode and duration, a native checkbox for the one valid extension, an `aria-live="polite"` total, and a disabled button or configured Inertia `Link` according to validity. Render the compact duplicate CTA inside `.package-configurator__mobile-cta`; CSS hides it above the mobile breakpoint and it has no valid `href` until the base selection is valid. `PackageMenuCard` renders an `<article>` with the localized package name as its accessible label, image with `applyImageFallback`, VIP badge, localized description/benefit, one `PackageConfigurator`, and `/services/{slug}` detail link.

- [ ] **Step 6: Run component tests and type checking**

Run: `npx vitest run tests/frontend/serviceSelection.test.ts tests/frontend/officialMenuComponents.test.ts`

Run: `npm run types:check`

Expected: PASS.

- [ ] **Step 7: Commit the frontend domain checkpoint**

```bash
git add resources/js/lib/serviceSelection.ts resources/js/lib/media.ts resources/js/components/PackageConfigurator.vue resources/js/components/PackageMenuCard.vue tests/frontend/serviceSelection.test.ts tests/frontend/officialMenuComponents.test.ts
git commit -m "feat: add package selection controls"
```

### Task 6: Redesign the homepage, navigation, copy, and responsive menu styling

**Files:**

- Modify: `resources/js/pages/Home.vue`
- Modify: `resources/js/layouts/MainLayout.vue`
- Modify: `resources/js/data/translations.ts`
- Modify: `resources/js/data/faq.ts`
- Create: `resources/css/menu.css`
- Modify: `resources/css/app.css`
- Modify: `tests/frontend/serviceCatalog.test.ts`
- Modify: `tests/frontend/language.test.ts`
- Modify: `tests/frontend/translation-parity.test.mjs`

**Interfaces:**

- Consumes: `PackageData`, `PackageLinkData`, and `PackageMenuCard`.
- Home props become `{ packages: PackageData[] }`.
- Canonical homepage menu anchor becomes `#menu`.

- [ ] **Step 1: Rewrite mounted homepage/layout tests for the approved content**

Assert two package articles, exact duration prices, VIP distinction, `/services/nezuko` and `/services/nezuko-vip` links, no category filter, `Menu`/`Menu Perawatan` navigation, two footer package links, official address, and policy copy. Assert English switches descriptions while preserving `Nezuko`, `Nezuko VIP`, and prices.

- [ ] **Step 2: Run focused frontend tests and verify RED**

Run: `npx vitest run tests/frontend/serviceCatalog.test.ts tests/frontend/language.test.ts`

Run: `npm run test:legacy -- --test-name-pattern="translation"`

Expected: FAIL on the old categories, address, and public copy.

- [ ] **Step 3: Replace category/listing sections with the official editorial menu**

Change `Home.vue` to accept `packages`, remove category/query filtering and `ServiceCard`, retain the accessible gallery/lightbox and supporting sections, and render:

```vue
<section id="menu" class="official-menu section" aria-labelledby="menu-title">
  <div class="container">
    <header class="official-menu__header">
      <span class="eyebrow">{{ tr("menu.eyebrow") }}</span>
      <h2 id="menu-title">{{ tr("menu.title") }}</h2>
      <p>{{ tr("menu.sub") }}</p>
    </header>
    <div class="official-menu__grid">
      <PackageMenuCard
        v-for="servicePackage in packages"
        :key="servicePackage.slug"
        :service-package="servicePackage"
      />
    </div>
    <p class="menu-policy" role="note">{{ tr("menu.hotWaterPolicy") }}</p>
  </div>
</section>
```

Update the hero to use the `Rp199.000` starting price and remove catalog-count statistics. Add a short comparison strip that describes standard as gentle/classic and VIP as tailored/premium without inventing benefits absent from the official source.

- [ ] **Step 4: Update shared navigation/footer and bilingual content**

Use `/#menu`, observe `menu` in scroll tracking, and render `catalog.packages` as `/services/{slug}` footer links. Set these exact location values:

```ts
"loc.address": "Jalan Terusan Batubara No. 12, Lantai 2",
```

for both locales, with the English label text around it translated. Update the map iframe query to `Jalan Terusan Batubara No. 12, Malang`. Add the hot-water policy as a bilingual FAQ entry. Add paired translation keys for menu eyebrow/title/subtitle, regular/double therapist, duration, extension, totals, VIP comparison, hot-water policy, booking review, and unavailable selections. Keep package names and numeric prices out of translation strings.

- [ ] **Step 5: Add the isolated responsive visual system**

Import `menu.css` after `legacy.css`. Define warm ivory, bronze, espresso, and gold custom properties scoped through menu classes; use two columns above 960px, one column below; make option controls at least 44px; add a curved-line pseudo-element; style VIP with a dark surface and non-color VIP badge; add visible `:focus-visible`; display `.package-configurator__mobile-cta` as a safe-area-aware sticky footer only below 640px; and disable transforms/transitions under `prefers-reduced-motion: reduce`. Do not override auth/admin selectors.

- [ ] **Step 6: Run focused frontend, parity, and type tests**

Run: `npx vitest run tests/frontend/serviceCatalog.test.ts tests/frontend/language.test.ts tests/frontend/officialMenuComponents.test.ts`

Run: `npm run test:legacy -- --test-name-pattern="translation"`

Run: `npm run types:check`

Expected: PASS.

- [ ] **Step 7: Commit the public-layout checkpoint**

```bash
git add resources/js/pages/Home.vue resources/js/layouts/MainLayout.vue resources/js/data/translations.ts resources/js/data/faq.ts resources/css/menu.css resources/css/app.css tests/frontend/serviceCatalog.test.ts tests/frontend/language.test.ts tests/frontend/translation-parity.test.mjs
git commit -m "feat: redesign homepage for official menu"
```

### Task 7: Redesign both package detail pages

**Files:**

- Modify: `resources/js/pages/ServiceDetail.vue`
- Modify: `tests/frontend/serviceCatalog.test.ts`
- Modify: `tests/Feature/ServiceCatalogPagesTest.php`

**Interfaces:**

- Consumes detail props `{ service: PackageData, comparison: PackageLinkData|null }`.
- Consumes `PackageConfigurator` and `emptySelection(service.slug)`.
- Produces configured booking URLs using `service`, `mode`, `duration`, and optional `extension`.

- [ ] **Step 1: Add failing mounted detail tests**

Assert image/gallery switching, localized description and benefit, regular and double modes, only allowed durations, exact totals, VIP badge, and a single comparison link to the other package. Assert the old category tag, scalar price row, and three-card related grid are absent.

- [ ] **Step 2: Run the detail tests and verify RED**

Run: `npx vitest run tests/frontend/serviceCatalog.test.ts -t "package detail"`

Expected: FAIL on the scalar detail implementation.

- [ ] **Step 3: Replace scalar detail rendering with package selection**

Keep the accessible breadcrumb and gallery. Initialize:

```ts
const selection = ref<ServiceSelection>(emptySelection(props.service.slug));
const name = computed(() =>
  language.value === "id" ? props.service.nameId : props.service.nameEn,
);
const benefit = computed(() =>
  language.value === "id" ? props.service.benefitId : props.service.benefitEn,
);
```

Render the package badge/name/description/benefit, then `<PackageConfigurator v-model="selection" :service-package="service" show-booking-link />`. Use `applyImageFallback` on the main and gallery images. Replace related cards with one text-and-arrow comparison link when `comparison` exists.

- [ ] **Step 4: Run detail, backend contract, and type tests**

Run: `npx vitest run tests/frontend/serviceCatalog.test.ts`

Run: `php artisan test tests/Feature/ServiceCatalogPagesTest.php`

Run: `npm run types:check`

Expected: PASS.

- [ ] **Step 5: Commit the detail checkpoint**

```bash
git add resources/js/pages/ServiceDetail.vue tests/frontend/serviceCatalog.test.ts tests/Feature/ServiceCatalogPagesTest.php
git commit -m "feat: redesign package detail pages"
```

### Task 8: Redesign the authenticated booking configurator without fake persistence

**Files:**

- Modify: `resources/js/lib/booking.ts`
- Modify: `resources/js/pages/Booking.vue`
- Modify: `tests/frontend/bookingValidation.test.ts`
- Modify: `tests/frontend/serviceCatalog.test.ts`

**Interfaces:**

- Consumes props `{ packages: PackageData[], selection: ServiceSelection }`.
- `BookingFormInput` extends selection with `name`, `phone`, `date`, and `time`.
- `validateBooking(form, packages, today)` returns field errors for customer, configuration, date, and time.
- Uses `selectionTotal()` for summary only; no POST endpoint is introduced.

- [ ] **Step 1: Write failing validation and mounted booking tests**

Assert validation rejects missing mode/duration, incompatible extensions, inactive/unknown codes, malformed phone/date/time, and accepts Nezuko regular 90 + 60 extension. Mounted tests must assert account email is read-only, name/WhatsApp are editable and prefilled, configured values render, total is Rp340.000/150 minutes, changing to double therapist clears incompatible values, and the final action says `Review booking` rather than claiming a reservation was sent.

- [ ] **Step 2: Run booking tests and verify RED**

Run: `npx vitest run tests/frontend/bookingValidation.test.ts tests/frontend/serviceCatalog.test.ts -t "booking"`

Expected: FAIL on scalar service validation and fake success state.

- [ ] **Step 3: Extend booking validation around normalized package selection**

Add `service`, `mode`, `duration`, and `extension` errors. Call `normalizeSelection()` and require the normalized values to equal submitted scalar values. Keep current name, normalized phone, non-past date, and time-slot checks. Never multiply totals by guest count.

- [ ] **Step 4: Replace the scalar service select with the package workflow**

Use package choice buttons followed by `PackageConfigurator` for the selected package. Initialize from the server-normalized selection. If the package changes, replace the complete selection with `emptySelection(newSlug)`. Keep date/time/name/phone/guests/notes inputs, render email from `page.props.auth.user.email` in a read-only input, and display package, mode, base duration, extension, total duration, and total price in the summary.

- [ ] **Step 5: Remove the misleading local success state**

On valid submit, switch to a review panel headed `Review your booking` / `Tinjau reservasi Anda`, repeat the chosen data, and show `No reservation has been created yet` / `Reservasi belum dibuat` as an explicit status. Provide an `Edit details` action. Do not call an endpoint, write browser storage, or use wording that claims confirmation.

- [ ] **Step 6: Run booking, public-route, and type tests**

Run: `npx vitest run tests/frontend/bookingValidation.test.ts tests/frontend/serviceCatalog.test.ts`

Run: `php artisan test tests/Feature/ServiceCatalogPagesTest.php`

Run: `npm run types:check`

Expected: PASS.

- [ ] **Step 7: Commit the booking-layout checkpoint**

```bash
git add resources/js/lib/booking.ts resources/js/pages/Booking.vue tests/frontend/bookingValidation.test.ts tests/frontend/serviceCatalog.test.ts
git commit -m "feat: add authenticated package booking review"
```

### Task 9: Replace obsolete catalog coverage and run full production gates

**Files:**

- Create: `tests/e2e/helpers/auth.ts`
- Modify: `tests/e2e/auth.spec.ts`
- Modify: `tests/e2e/service-catalog.spec.ts`
- Modify: `tests/frontend/publicPages.test.ts`
- Delete: `resources/js/components/ServiceCard.vue`
- Modify only if referenced by an active Laravel test: obsolete catalog assertions under `tests/Feature` and `tests/frontend`

**Interfaces:**

- E2E helpers export `registerCustomer(page, email, options?: { navigate?: boolean })`, `testingLink(page, endpoint, email)`, `verifyCustomer(page, email, expectedUrl?: string|RegExp)`, and `login(page, email, password)`.
- E2E menu flow uses role/name selectors and exact public prices, not class names.

- [ ] **Step 1: Extract reusable auth helpers without changing auth assertions**

Move the existing Playwright registration, testing-link, verification, and login functions into `tests/e2e/helpers/auth.ts`; import them from `auth.spec.ts`. Keep the password as an exported test constant and keep testing-link validation. `registerCustomer` navigates to `/register` unless `options.navigate === false`. `verifyCustomer` visits the signed link and defaults to asserting `/profile?verified=1`, but accepts an explicit expected URL for configured booking tests.

- [ ] **Step 2: Replace the old Javanese-service E2E test with official flows**

Cover:

```ts
test("Nezuko regular 90 plus extension survives verified login", async ({
  page,
}) => {
  await page.goto("/");
  const nezuko = page.getByRole("article", { name: "Nezuko" });
  await nezuko.getByRole("button", { name: /regular|reguler/i }).click();
  await nezuko.getByRole("button", { name: /90/ }).click();
  await nezuko.getByRole("checkbox", { name: /60/ }).check();
  await nezuko.getByRole("link", { name: /book|pesan/i }).click();
  await expect(page).toHaveURL(/\/login$/);

  await page
    .getByRole("link", { name: /create an account|buat akun/i })
    .click();
  await registerCustomer(page, "menu@example.com", { navigate: false });
  await verifyCustomer(
    page,
    "menu@example.com",
    /\/booking\?service=nezuko&mode=regular&duration=90/,
  );

  await expect(page).toHaveURL(
    /\/booking\?service=nezuko&mode=regular&duration=90/,
  );
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();
});
```

Add Nezuko VIP double therapist +30 minutes = 90 minutes/Rp450.000, locale switch preserving a valid selection, and hostile query values clearing without changing totals. The configured flow must pass `{ navigate: false }` so registration begins from the current register page and does not replace intended state.

- [ ] **Step 3: Remove dead scalar-card code and forbidden assumptions**

Delete `ServiceCard.vue` after `rg -n "ServiceCard|ServiceCategoryData|\.price\b|\.duration\b" resources/js` proves active pages use only package variants/totals. Update public-page tests to require `id="menu"`, forbid old placeholder names/imports, and retain scans forbidding `vue-router`, legacy auth stores, `localStorage`, and `sessionStorage` in active Laravel runtime files.

- [ ] **Step 4: Run format and focused browser checks**

Run: `npm run format`

Run: `npm run format:check`

Run: `npm run test:e2e -- tests/e2e/service-catalog.spec.ts`

Expected: PASS for both package flows, authentication handoff, locale retention, and hostile values.

- [ ] **Step 5: Run all automated gates**

Run in this order:

```bash
php artisan test
php artisan test --coverage --min=80
npm run test:frontend
npm run types:check
npm run build
npm run test:e2e
php artisan route:cache
php artisan route:clear
php artisan config:cache
php artisan config:clear
composer audit
npm audit
```

If no PHP coverage driver is installed, record that command as an environment blocker and do not describe coverage as passing. Fix code defects rather than weakening tests.

- [ ] **Step 6: Perform visual and security verification**

Inspect the homepage, both detail pages, login handoff, and booking review at 390px, 768px, and 1440px widths. Verify no horizontal scroll, visible focus, readable VIP contrast, 44px controls, reduced motion, correct official address, and exact prices. Run secret and forbidden-runtime scans with:

```bash
rg -n "(AKIA|BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|api[_-]?key\s*=|password\s*=)" app config database resources routes tests
rg -n "localStorage|sessionStorage|vue-router|useAuth|bookingHistory" resources/js
```

Dispatch fresh code-quality and security reviewers. Resolve every critical/high finding and rerun the affected focused tests plus the full PHP/frontend/type/build gates.

- [ ] **Step 7: Confirm workspace boundaries and commit the final checkpoint**

Run `git status --short` and `git diff --check`. Confirm `.env`, `src/`, and unrelated pre-existing dirty files were not staged. Then:

```bash
git add tests/e2e/helpers/auth.ts tests/e2e/auth.spec.ts tests/e2e/service-catalog.spec.ts tests/frontend/publicPages.test.ts resources/js/components/ServiceCard.vue
git commit -m "test: cover official menu customer flow"
```

Record the exact pass/fail result of every final gate in the handoff, including the coverage-driver status and any audit advisories.
