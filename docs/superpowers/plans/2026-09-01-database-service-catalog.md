# Database Service Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve Shin Joy Spa's bilingual service catalog from Laravel/MySQL without changing the current public design.

**Architecture:** Persist categories and services in normalized Laravel models, seed the exact current catalog idempotently, and expose allow-listed camelCase data through focused Inertia controllers. Vue pages consume typed server props; Laravel remains authoritative for service identity, availability, duration, and price.

**Tech Stack:** Laravel 13, Eloquent, MySQL/SQLite tests, Inertia 3, Vue 3, TypeScript, Vitest/Vue Test Utils, Playwright.

**Spec:** `docs/superpowers/specs/2026-09-01-database-service-catalog-design.md`

## Global Constraints

- Implement only service categories, services, public catalog queries, booking-page service options, and their tests.
- Do not create bookings, therapists, schedules, administrator CRUD, payments, or notifications.
- Preserve the current branded bilingual UI and URLs.
- Prices are integer IDR values and are authoritative on the server.
- Public props use an explicit allow-list and never expose timestamps or internal foreign keys.
- Missing, malformed, or inactive service slugs return HTTP 404.
- Seed the exact four categories and twelve services currently defined in `resources/js/data/categories.ts` and `resources/js/data/services.ts`.
- Repeated catalog seeding must not duplicate rows or overwrite unrelated user accounts.
- Do not edit, stage, delete, or revert unrelated dirty `src/**`, diagram/document deletions, or the older untracked auth plan.
- Do not edit the real `.env`.
- Apply strict RED-GREEN-REFACTOR TDD and keep PHP coverage absence classified as an environment blocker.

---

### Task 1: Persist and seed the service catalog

**Files:**

- Create: `database/migrations/2026_09_01_000000_create_service_categories_table.php`
- Create: `database/migrations/2026_09_01_000001_create_services_table.php`
- Create: `app/Models/ServiceCategory.php`
- Create: `app/Models/Service.php`
- Create: `database/seeders/ServiceCatalogSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/ServiceCatalogPersistenceTest.php`

**Interfaces:**

- Produces: `ServiceCategory::services(): HasMany`, `Service::category(): BelongsTo`.
- Produces: active scopes ordered by `display_order`, then `id`.
- Produces: idempotent `ServiceCatalogSeeder` with the exact existing 4/12 catalog.

- [ ] **Step 1: Write failing persistence tests**

Add feature tests that run migrations, invoke `ServiceCatalogSeeder` twice, and assert literal counts of four categories and twelve services, exact representative records, unique slugs, integer IDR prices, gallery array casts, and category relationships. Assert inactive services and services under inactive categories are excluded by the public scope.

- [ ] **Step 2: Run the focused tests and verify RED**

Run: `php artisan test tests/Feature/ServiceCatalogPersistenceTest.php --compact`

Expected: FAIL because the migrations, models, and seeder do not exist.

- [ ] **Step 3: Add the minimal migrations and models**

Use restrictive foreign keys and deterministic scopes. Required casts:

```php
protected function casts(): array
{
    return [
        'gallery_paths' => 'array',
        'is_active' => 'boolean',
        'duration_minutes' => 'integer',
        'price_idr' => 'integer',
        'popularity' => 'integer',
        'display_order' => 'integer',
    ];
}
```

- [ ] **Step 4: Add the idempotent seeder**

Use `updateOrCreate(['slug' => ...], [...])` for the exact records in the two current TypeScript data modules. Change `DatabaseSeeder::run()` to call `ServiceCatalogSeeder::class` only; do not create a default user.

- [ ] **Step 5: Verify GREEN and regression safety**

Run the focused test, then `php artisan test --compact`.

Expected: all pass.

- [ ] **Step 6: Commit the task**

Commit only owned schema/model/seeder/test files with `feat: persist service catalog`.

---

### Task 2: Serve safe catalog data through Inertia

**Files:**

- Create: `app/Support/ServiceCatalogPresenter.php`
- Create: `app/Http/Controllers/HomeController.php`
- Create: `app/Http/Controllers/ServiceController.php`
- Create: `app/Http/Controllers/BookingController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/PublicPagesTest.php`
- Create: `tests/Feature/ServiceCatalogPagesTest.php`

**Interfaces:**

- Consumes: Task 1 models and active scopes.
- Produces: `ServiceCatalogPresenter::category(ServiceCategory): array` with `slug`, `nameId`, `nameEn`, `image`.
- Produces: `ServiceCatalogPresenter::service(Service): array` with the existing public camelCase service shape.
- Produces: Home props `categories` and `services`; detail props `service`, `category`, `related`; booking props `services`, `selectedService`.

- [ ] **Step 1: Write failing route/prop tests**

Seed controlled active/inactive records and assert exact allow-listed Inertia props, deterministic ordering, three-related-service limit, active-only behavior, safe booking preselection, and HTTP 404 for unknown/inactive/malformed service slugs.

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Feature/PublicPagesTest.php tests/Feature/ServiceCatalogPagesTest.php --compact`

Expected: FAIL because controllers and catalog props are absent and unknown slugs still render a client-side empty state.

- [ ] **Step 3: Implement the presenter and controllers**

Map fields explicitly. For booking selection, accept `service` from the query only when it matches a service in the active collection; otherwise return an empty string.

- [ ] **Step 4: Replace route closures**

Register `HomeController@index`, `ServiceController@show`, and `BookingController@create`; retain existing route names, URI patterns, locale route, auth routes, and middleware.

- [ ] **Step 5: Verify GREEN and route caching**

Run focused tests, full Laravel tests, `php artisan route:cache`, and `php artisan route:clear`.

Expected: all pass and caching succeeds.

- [ ] **Step 6: Commit the task**

Commit only owned controller/presenter/route/test files with `feat: serve service catalog through inertia`.

---

### Task 3: Consume the database catalog in Vue

**Files:**

- Modify: `resources/js/types/index.ts`
- Modify: `resources/js/pages/Home.vue`
- Modify: `resources/js/pages/ServiceDetail.vue`
- Modify: `resources/js/pages/Booking.vue`
- Modify: `resources/js/components/ServiceCard.vue`
- Delete: `resources/js/data/services.ts`
- Delete: `resources/js/data/categories.ts`
- Modify/Create: `tests/frontend/publicPages.test.ts`
- Create: `tests/frontend/serviceCatalog.test.ts`

**Interfaces:**

- Consumes: Task 2's exact Inertia prop shapes.
- Produces: exported `ServiceCategoryData`, `ServiceData`, and page-prop interfaces.
- Preserves: current filtering, bilingual text, gallery, related cards, pricing, query deep links, validation, and accessible labels.

- [ ] **Step 1: Write mounted failing Vue tests**

Mount the real affected pages/components with complete Inertia props. Assert category filtering, ID/EN service names, detail gallery and related links, booking option values, selected service, price/duration summaries, and `/booking?service=<slug>` links. Assert no runtime import of `@/data/services` or `@/data/categories` remains.

- [ ] **Step 2: Verify RED**

Run: `npm run test:frontend -- tests/frontend/serviceCatalog.test.ts tests/frontend/publicPages.test.ts`

Expected: FAIL because pages still import static data and do not declare the server prop interfaces.

- [ ] **Step 3: Implement typed server props**

Use `defineProps` for page-specific catalog data and typed `ServiceCard` props. Preserve existing markup and CSS except for changes required to consume props.

- [ ] **Step 4: Remove obsolete runtime catalog modules**

Delete the two TypeScript catalog files only after `rg` confirms no active import remains under `resources/js`.

- [ ] **Step 5: Verify GREEN**

Run focused frontend tests, `npm run test:frontend`, `npm run types:check`, and `npm run build`.

Expected: all pass; existing non-fatal local-font build warnings may remain documented.

- [ ] **Step 6: Commit the task**

Commit only owned Vue/type/test/catalog-module paths with `refactor: load service catalog from laravel`.

---

### Task 4: Verify the database-backed journey and production safeguards

**Files:**

- Modify: `tests/e2e/global-setup.ts`
- Modify: `tests/e2e/auth.spec.ts` or create `tests/e2e/service-catalog.spec.ts`
- Modify: `.env.example` only if a catalog-specific production setting is required by implementation; otherwise leave it unchanged.

**Interfaces:**

- Consumes: Tasks 1-3 complete service catalog journey.
- Produces: seeded Playwright database and browser proof of service-to-booking preselection.

- [ ] **Step 1: Write the failing Playwright journey**

Navigate from the homepage database-backed service card to its detail page, click Book, and assert the booking select contains and preselects that exact service with the server-provided IDR price and duration.

- [ ] **Step 2: Verify RED**

Run the focused Playwright test.

Expected: FAIL because Playwright's fresh database has not seeded catalog rows.

- [ ] **Step 3: Seed the isolated browser database**

Change global setup to run `php artisan migrate:fresh --seed --force --no-interaction` with the existing isolated testing environment.

- [ ] **Step 4: Verify GREEN and all final gates**

Run:

```text
php artisan test --compact
php artisan test --coverage --min=80
npm run test:frontend
npm run test:e2e
npm run types:check
npm run build
php artisan route:cache
php artisan route:clear
php artisan config:cache
php artisan config:clear
php .tools/composer.phar audit
npm audit --audit-level=high
```

Report a missing PHP coverage driver as an environment blocker, never as a pass. Run secret and forbidden-runtime scans, verify the real `.env` hash, verify generated Playwright artifacts/listeners are cleaned, and verify unrelated dirty files remain untouched.

- [ ] **Step 5: Seed the developer MySQL catalog**

Run `php artisan db:seed --class=ServiceCatalogSeeder --force --no-interaction` against the already configured local `shin_joy_spa` database, then read back literal counts of four categories and twelve services. Do not modify `.env`.

- [ ] **Step 6: Commit the task**

Commit only owned E2E/config paths with `test: verify database service catalog`.

---

## Completion gate

Run an independent task review after every task and one whole-milestone specification, correctness, and security review after Task 4. Stop after Milestone 2 and report the result before planning or implementing booking persistence.
