# Task 3 — Package-shaped public catalog contracts

## TDD evidence

- RED: Replaced the category/scalar page contracts with seeded Nezuko package tests, including active-choice filtering, exact prop allowlists, compact shared links, the opposite-package detail comparison, and no-active-variant exclusion. `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/InertiaSharedDataTest.php` failed as expected: `packages.*`, `service.variants.*`, and `catalog.packages.*` did not exist in the previous payload.
- GREEN: `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/InertiaSharedDataTest.php tests/Feature/PublicPagesTest.php` — **11 passed, 152 assertions** (duration 1.58s).

## Changed files

- `app/Support/ServiceCatalogPresenter.php`: explicit package, compact-link, variant, and extension allowlists; enum values and numeric casts; normalized gallery paths; no Eloquent `toArray()` serialization.
- `app/Http/Controllers/HomeController.php`: public packages with active ordered variants and extensions only.
- `app/Http/Controllers/ServiceController.php`: one active package plus one compact comparison link, with no category or related-service props.
- `app/Http/Middleware/HandleInertiaRequests.php`: lazy compact public package links replace shared categories.
- `resources/js/types/index.ts`: exact `TreatmentMode`, package choice, package, and link contracts.
- `tests/Feature/ServiceCatalogPagesTest.php`, `tests/Feature/InertiaSharedDataTest.php`, and `tests/Feature/PublicPagesTest.php`: package-safe public page contracts.

## Sequencing rulings

- The legacy `BookingController` remains owned by Task 4. Its temporary call to `ServiceCatalogPresenter::service()` is retained as a documented compatibility alias that delegates only to the new package serializer and actively queries child choices. It preserves no category/scalar payload shape; Task 4 removes this bridge.
- The `/services` fragment stays `/#services` in this checkpoint. Task 6 owns the route/UI transition to `/#menu`, so the Task 3 public-page test intentionally retains the current fragment expectation.

## Verification and self-review

- `git diff --check` — clean; Git emitted only LF/CRLF working-copy notices.
- Confirmed models are never serialized with `toArray()`, all props are manual field allowlists, internal IDs/timestamps/foreign keys are absent, and public queries exclude inactive packages and packages without active variants.
- `npm run types:check` was run and currently fails only in unchanged Task 5/6 frontend consumers still importing removed `ServiceData`/`ServiceCategoryData` or accessing `catalog.categories` (`ServiceCard.vue`, `Home.vue`, `ServiceDetail.vue`, `Booking.vue`, `MainLayout.vue`). The new type contract is intentional; those consumers are owned by the downstream frontend tasks.
