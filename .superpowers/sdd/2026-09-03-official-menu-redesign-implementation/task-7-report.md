# Task 7 — Package Detail Redesign

## RED

`npx vitest run tests/frontend/serviceCatalog.test.ts -t "package detail"` failed before implementation because `ServiceDetail` still required the obsolete `category` and `related` props and attempted scalar detail rendering.

## GREEN

- `npx vitest run tests/frontend/serviceCatalog.test.ts` — 6 passing tests.
- `php artisan test tests/Feature/ServiceCatalogPagesTest.php` — 7 passing tests, 181 assertions.
- `npm run types:check` — Task 7 has no diagnostics. The command continues to report only the approved unchanged diagnostics in `resources/js/pages/Booking.vue` and obsolete `resources/js/components/ServiceCard.vue`.

## Delivered

- Detail props now consume the package and one comparison link only.
- Breadcrumb, gallery, one-shot local image fallback, localized package content, VIP badge, and shared immutable configurator are rendered on both package pages.
- The scalar/category and related-grid detail UI is removed.

## Fix round 1 — VIP contract and fallback audit

### RED

The new test coverage was introduced for the previously unprotected VIP detail contract and detail-image error paths. The existing implementation was already green: it correctly supplied the VIP package's active variants/extensions and had the one-shot `applyImageFallback` handler attached to the main and thumbnail gallery images. No implementation change was needed.

### GREEN

- `npx vitest run tests/frontend/serviceCatalog.test.ts -t "package detail"` — 2 passing detail tests.
- `php artisan test tests/Feature/ServiceCatalogPagesTest.php` — 8 passing tests, 205 assertions.
- `npm run types:check` — no Task 7 diagnostics; only the existing approved `Booking.vue` and obsolete `ServiceCard.vue` diagnostics remain.

### Added coverage

- `/services/nezuko-vip` exposes only the exact allow-listed VIP package fields, four active variants, two active extensions, and the reverse Nezuko comparison link.
- Mounted VIP detail coverage uses the real official prices and codes, validates regular/double duration choices, a Rp449.000 regular total with extension, and the configured booking URL.
- Detail coverage dispatches image errors on the main image and every gallery thumbnail, verifies the local fallback, and confirms the fallback remains one-shot.
