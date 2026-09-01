# Database Service Catalog Design

## Goal

Move Shin Joy Spa's public service catalog from bundled TypeScript constants to MySQL-backed Laravel models while preserving the current bilingual Inertia/Vue experience.

## Scope

This milestone owns service categories, services, their seed data, public catalog queries, and the Vue pages that consume them. It does not create bookings, therapists, schedules, administrator CRUD, payments, or notifications.

## Data model

`service_categories` stores a unique slug, Indonesian and English names, an image path, display order, and active flag.

`services` belongs to a category and stores a unique slug, bilingual names and descriptions, optional bilingual "good for" text, duration in minutes, integer IDR price, cover image path, JSON gallery paths, popularity, display order, and active flag. Prices remain server-authoritative integers; the browser never supplies or derives authoritative prices.

Deleting a category with services is restricted. Public queries return only active services whose category is also active, ordered deterministically by display order and primary key.

## Seed strategy

The existing four categories in `resources/js/data/categories.ts` and twelve services in `resources/js/data/services.ts` are the migration source. `ServiceCatalogSeeder` upserts them by slug so repeated seeding is safe. `DatabaseSeeder` calls only the catalog seeder and does not create a known default user account.

## Backend boundary

Focused controllers replace the public route closures:

- `HomeController@index` supplies safe category and service arrays.
- `ServiceController@show` resolves an active service by slug, supplies its category and up to three related active services, and returns HTTP 404 otherwise.
- `BookingController@create` supplies active booking options and a sanitized selected service slug.

`ServiceCatalogPresenter` is the single allow-list boundary that converts models to the existing camelCase frontend shape. It exposes no timestamps, internal foreign keys, or inactive records.

## Frontend boundary

`Home.vue`, `ServiceDetail.vue`, `Booking.vue`, and `ServiceCard.vue` consume typed Inertia props instead of importing catalog constants. The visual design, bilingual strings, filtering, pricing display, related-service cards, and `/booking?service=<slug>` behavior remain unchanged.

The old TypeScript catalog modules are deleted only after no active runtime import remains and all tests pass. Unrelated dirty `src/**` files remain untouched.

## Failure and security behavior

- Missing, malformed, or inactive service slugs return HTTP 404.
- Booking preselection accepts only an active server-provided service slug.
- All public props are explicitly allow-listed.
- No service price, duration, active flag, or category relationship is trusted from browser input.
- Seed execution is idempotent and does not create authentication credentials.

## Testing

Laravel feature tests cover schema constraints, relationships, idempotent seeding, active-only ordering, exact safe Inertia props, 404 behavior, and booking preselection. Mounted Vue tests cover filtering, bilingual rendering, service links, detail rendering, and selected booking options. Playwright covers selecting a database-backed service and arriving at the booking form with the correct preselection.

Final gates are the full Laravel suite, frontend suite, Playwright suite, type checking, production build, route/config cache checks, Composer audit, npm audit, secret scan, and verification that the real `.env` and unrelated dirty files are unchanged.
