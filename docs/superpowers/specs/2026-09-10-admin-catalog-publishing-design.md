# Phase 2B Admin Catalog Publishing Design

**Date:** 2026-09-10
**Status:** Design decisions approved; written specification pending review

## Summary

Phase 2B turns the current database-backed Nezuko menu into a catalog that authorized administrators can manage. Administrators can create categories and packages, upload images, configure price and duration choices, save new records as drafts, publish them, archive them, and restore them. The public homepage, menu, service detail pages, and booking quote flow will read the published catalog instead of assuming that exactly two Nezuko packages exist.

This phase preserves the existing Laravel, Fortify, Inertia, Vue 3, TypeScript, and normalized catalog architecture. It does not add payment processing, appointment scheduling rules, notifications, customer management, or a catalog edit-history interface.

## Goals

- Let administrators create entirely new public categories and packages.
- Provide one coherent editor for a package, its images, variants, and extensions.
- Support safe draft, publish, archive, restore, and limited hard-delete behavior.
- Keep published and booking-referenced records available for historical integrity.
- Replace Nezuko-only public queries and layouts with a dynamic catalog.
- Accept direct image uploads with production-safe validation and cleanup.
- Preserve the existing booking quote and immutable booking snapshot contracts.
- Cover the new behavior with Laravel, Vue, and Playwright tests.

## Existing Contracts to Preserve

The existing normalized tables remain the source of truth:

- `service_categories`
- `services`
- `service_variants`
- `service_extensions`

The current public package presenter remains the base package contract:

```text
slug
nameId
nameEn
descId
descEn
longId
longEn
benefitId
benefitEn
image
gallery[]
isVip
variants[]
extensions[]
```

Each selectable variant or extension retains this public shape:

```text
code
mode
duration
price
```

Existing bookings retain nullable foreign keys plus immutable service, option, price, duration, and customer snapshots. Catalog renames, archives, or deletions must not alter old booking records or their displayed history.

`BookingQuoteResolver` remains the only authority for resolving a submitted package, treatment mode, duration, extension, and server-calculated price. Client-provided prices remain untrusted.

## Scope

### Included

- Admin catalog index.
- Category create and edit.
- Package create and aggregate edit.
- Direct cover and gallery image uploads.
- Variant and extension management inside the package editor.
- Draft, publish, archive, restore, and safe delete lifecycle actions.
- A real public `/services` menu page.
- Dynamic homepage catalog sections.
- Dynamic service detail and related-package navigation.
- Authorization, validation, throttling, concurrency protection, and tests.

### Not included

- Payment gateways or payment records.
- Capacity, room, therapist, or time-slot scheduling.
- Email, WhatsApp, or push notifications.
- Customer-management screens.
- Two-person or multi-person booking redesign.
- Catalog revision history, approval chains, or scheduled publishing.
- Bulk imports or exports.
- Image editing, cropping, or CDN integration.

## Chosen Architecture

The catalog stays in the current normalized Laravel models. No separate CMS, repository abstraction, JSON document store, or second public catalog is introduced.

Categories and packages own their publication lifecycle. Variants and extensions are edited as children of a package and gain their own publication timestamps so previously public options can be distinguished from disposable draft-only rows.

The package editor is an aggregate boundary. One request reconciles package fields, images, variants, and extensions inside one database transaction. This keeps the editor understandable and prevents partially saved pricing configurations.

## Lifecycle Model

Add nullable `published_at` timestamps to categories, services, variants, and extensions. Continue using `is_active` as the current visibility flag.

| State     | `published_at` | `is_active` |                         Publicly visible |
| --------- | -------------: | ----------: | ---------------------------------------: |
| Draft     |         `null` |     `false` |                                       No |
| Published |       non-null |      `true` | Yes, when its parents are also published |
| Archived  |       non-null |     `false` |                                       No |

The first publish sets `published_at`. Later archives and restores never clear or replace it.

### New draft records

A draft category requires a unique slug plus Indonesian and English names. Its image may be absent.

A draft package requires a category, unique slug, Indonesian and English names, VIP flag, and display order. Publication-only descriptions, benefit text, cover image, variants, and extensions may be incomplete or absent.

Any variant or extension row that is present must be structurally complete, even while its package is a draft. A draft package may contain zero rows.

### Published record edits

Phase 2B does not implement parallel live and draft revisions. Once a category or package has been published:

- edits are validated as publishable and take effect immediately;
- an administrator may archive the record before a major rewrite;
- an archived record may be edited, but it must pass all publication rules before restoration;
- the UI labels the action `Save changes`, not `Save draft`.

This rule avoids implying versioned drafts that the data model cannot provide. Versioned editing can be introduced later with dedicated revision tables.

### Archive and restore

Archiving a category sets only the category to inactive. Its packages keep their own state but disappear publicly because their parent category is inactive. Restoring the category makes eligible active packages visible again.

Archiving a package sets the package to inactive. Its child rows retain their state for later restoration and booking history.

Restoring a category or package reruns every publication validation. A package cannot be restored under a draft or archived category.

### Hard delete

Hard delete is allowed only when a record has never been published and is not referenced:

- A category draft may be deleted only when it has no packages.
- A package draft may be deleted only when it has no booking references.
- A variant or extension draft may be deleted only when it has no booking references and its package has never been published.
- Published or archived records are never hard-deleted through the admin UI.
- Removing a previously published or referenced child row from the editor archives it instead of deleting it.

Database foreign keys remain the final integrity guard. Booking foreign keys continue using `nullOnDelete` where already established, while booking snapshots remain authoritative for historical display.

## Database Changes

### Publication fields

Add nullable indexed `published_at` columns to:

- `service_categories`
- `services`
- `service_variants`
- `service_extensions`

### Optimistic concurrency

Add unsigned bigint `lock_version` columns with a default of zero to categories and services. Every successful category or package aggregate mutation increments the relevant version.

The browser submits the version it most recently received. The server locks the record, compares versions, and rejects stale updates with a translated validation error attached to `version`. The response uses HTTP 422 so the existing Inertia form error boundary can display it consistently.

Child rows do not need independent lock versions because they are mutated only through the locked package aggregate.

### Draft-compatible nullability

Keep slugs, category ownership, names, flags, and ordering required. Make publication-only category and service media/content nullable where the current schema would otherwise force fake draft data:

- category `image_path` may be null;
- service `description_id`, `description_en`, `long_description_id`, `long_description_en`, and `image_path` may be null;
- `gallery_paths` remains a non-null JSON array with an empty-array default;
- `good_for_id` and `good_for_en` remain nullable.

### Indexes and constraints

- Preserve unique category slugs, service slugs, variant codes, and extension codes.
- Preserve unique service, mode, and duration combinations for variants and extensions.
- Add visibility and ordering indexes suited to public queries, including active state, display order, and stable ID tie-breaking.
- Preserve required category ownership for services.
- Preserve database-level price and duration validity where already enforced.

### Migration backfill

For retention safety, every pre-existing category, service, variant, and extension receives the migration timestamp as `published_at`.

- Existing active rows become published.
- Existing inactive rows become archived.
- No legacy row is accidentally reclassified as a disposable draft.
- Current Nezuko and Nezuko VIP packages remain public after migration.

The migration must be reversible without losing existing catalog content. Rolling back removes only the newly introduced lifecycle and lock columns and restores previous nullability where safe.

## Category Rules

A category contains:

- immutable-after-publish slug;
- Indonesian and English names;
- optional draft image, required for publication;
- display order;
- lifecycle state and lock version.

Publication requires both localized names, a valid image, and non-negative display order.

A category slug may be changed only while `published_at` is null. The server rejects later slug changes rather than silently ignoring them.

## Package Aggregate Rules

A package contains:

- required category;
- immutable-after-publish slug;
- Indonesian and English names;
- Indonesian and English short descriptions;
- Indonesian and English long descriptions;
- optional paired Indonesian and English benefit text;
- VIP flag;
- cover image;
- up to six gallery images;
- display order;
- variants;
- extensions;
- lifecycle state and lock version.

Benefit text is either absent in both languages or present in both languages.

Publication requires:

- an active, published category;
- all bilingual names and descriptions;
- a cover image;
- at least one active base variant;
- valid option codes, treatment modes, prices, durations, and ordering;
- no duplicate active mode and duration combination;
- no invalid or pending image reference.

Slugs and option codes may be edited only before their first publication. Stable public identifiers prevent old links and booking integrations from changing meaning.

## Variant and Extension Rules

Supported treatment modes remain the existing enum values:

- `regular`
- `double_therapist`

Prices are integer Indonesian rupiah values and must be positive. Durations are positive whole minutes. Display orders are non-negative integers.

A package may contain multiple variants across its treatment modes and durations.

Phase 2B permits at most one active extension per treatment mode for each package. This matches the current configurator and official menu behavior. An archived extension may coexist with its replacement. Supporting multiple simultaneous extensions within one mode would require a separate customer selection design and is deferred.

When a draft package is first published, all active child rows receive `published_at` in the same transaction. When an active child is later added to a published package, it receives `published_at` during that successful aggregate save and becomes public immediately.

Inactive child rows are excluded from public payloads and booking quotes.

## Image Storage

Uploads use Laravel's configured `public` disk. Database values store relative public paths, never absolute filesystem paths or client-supplied URLs.

Managed files are stored under randomized names in:

```text
catalog/categories/{category-uuid}/
catalog/packages/{package-uuid}/
```

Categories receive one image. Packages receive one cover plus at most six gallery images.

Every upload must pass all of these checks:

- JPEG, PNG, or WebP only;
- MIME and decoded image content agree;
- maximum 5 MB per file;
- minimum dimensions 600 by 400 pixels;
- maximum dimensions 6000 by 6000 pixels;
- no SVG, GIF, executable, script, or arbitrary document content;
- random server-generated filename and approved extension;
- original client filename is never used as a storage path.

The request may retain existing gallery paths by their server-issued identifiers, remove selected images, and add new uploads. It may not submit arbitrary paths.

Files under the existing `/img` seeded-asset paths are unmanaged legacy assets and must never be deleted by catalog cleanup. Only paths created under `catalog/` are eligible for replacement cleanup.

### Transaction compensation

Filesystem writes cannot share the database transaction, so mutations use this order:

1. Validate the request and uploads.
2. Store new files using random paths.
3. Lock and update database records inside one transaction.
4. If the transaction fails, delete the newly stored managed files.
5. After commit, delete replaced managed files that are no longer referenced.

Failure to delete an old file after commit is logged with record and path context. It does not roll back a valid database update or expose a broken public record.

## Public Catalog Queries

Remove the Nezuko and Nezuko VIP slug whitelist from `Service::publiclyVisible()`.

A package is publicly visible only when:

- the package is active and has been published;
- its category is active and has been published;
- it has at least one active published variant.

Public ordering is deterministic:

1. category `display_order`;
2. category ID;
3. package `display_order`;
4. package ID;
5. option `display_order`;
6. option ID.

All public catalog queries eager-load their required category, variant, and extension relationships to avoid per-card queries.

Draft and archived slugs return HTTP 404 from public detail and booking quote endpoints. They are not leaked through shared Inertia props, sitemaps, navigation, or related-package suggestions.

## Public Data Contracts

Home and menu pages receive grouped categories:

```text
categories[]
  slug
  nameId
  nameEn
  image
  packages[]
```

Each package inside a category uses the preserved public package presenter contract.

The compact shared `catalog.packages` list remains available for global navigation and authenticated booking entry points. It contains only published packages and does not include admin fields, database IDs, storage internals, lifecycle timestamps, or lock versions.

Service detail responses keep the existing package contract. Related packages come from the same published category, exclude the current package, and are limited to three in public order.

## Admin Routes

All routes live under `/admin`, use the existing `auth`, `verified`, and `admin` middleware, and require policy authorization.

| Method | Path                                           | Purpose                        |
| ------ | ---------------------------------------------- | ------------------------------ |
| GET    | `/admin/catalog`                               | Catalog index                  |
| POST   | `/admin/catalog/categories`                    | Create category draft          |
| PATCH  | `/admin/catalog/categories/{category}`         | Update category                |
| POST   | `/admin/catalog/categories/{category}/publish` | First publish                  |
| POST   | `/admin/catalog/categories/{category}/archive` | Archive                        |
| POST   | `/admin/catalog/categories/{category}/restore` | Restore                        |
| DELETE | `/admin/catalog/categories/{category}`         | Delete eligible draft          |
| GET    | `/admin/catalog/packages/create`               | Package create page            |
| POST   | `/admin/catalog/packages`                      | Create package draft aggregate |
| GET    | `/admin/catalog/packages/{service}/edit`       | Package editor                 |
| PATCH  | `/admin/catalog/packages/{service}`            | Update package aggregate       |
| POST   | `/admin/catalog/packages/{service}/publish`    | First publish                  |
| POST   | `/admin/catalog/packages/{service}/archive`    | Archive                        |
| POST   | `/admin/catalog/packages/{service}/restore`    | Restore                        |
| DELETE | `/admin/catalog/packages/{service}`            | Delete eligible draft          |

Named routes use an `admin.catalog.*` namespace. Lifecycle changes use POST endpoints to avoid overloading regular field updates with hidden state transitions.

## Admin Request Contracts

Category writes use multipart form data when an image is included. The accepted fields are:

```text
version
slug
nameId
nameEn
displayOrder
image
removeImage
```

Package aggregate writes use multipart form data. The accepted fields are:

```text
version
categoryId
slug
nameId
nameEn
descId
descEn
longId
longEn
benefitId
benefitEn
isVip
displayOrder
cover
removeCover
retainedGalleryIds[]
galleryUploads[]
variants[]
  id
  code
  mode
  durationMinutes
  priceIdr
  isActive
  displayOrder
extensions[]
  id
  code
  mode
  durationMinutes
  priceIdr
  isActive
  displayOrder
```

IDs in admin forms identify records within the current aggregate only. The server rejects foreign child IDs, duplicate submitted IDs, unknown gallery IDs, and unknown top-level or nested keys.

Create requests omit `version` and child IDs. Update requests require the current aggregate version. Boolean values are normalized through explicit Laravel validation rather than PHP truthiness.

The server constructs explicit validated value objects and persistence arrays. It never mass-assigns the raw request.

## Aggregate Persistence

Every package write follows this sequence:

1. Authorize the current administrator.
2. Validate the complete request and image files.
3. Resolve every submitted child ID as belonging to the package.
4. Store new files in temporary final paths.
5. Begin a database transaction and lock the package row.
6. Compare `lock_version` and reject stale edits.
7. Update package fields.
8. Reconcile submitted variants and extensions.
9. Archive or delete omitted children according to lifecycle rules.
10. Apply first-publication timestamps when required.
11. Increment the package lock version.
12. Commit, then remove replaced managed files.

Category writes use the same version and file-compensation pattern without child reconciliation.

No partial package state is committed if any nested option fails validation or persistence.

## Authorization and Security

Create catalog policies for categories and services. Policy methods cover:

- `viewAny`
- `view`
- `create`
- `update`
- `publish`
- `archive`
- `restore`
- `delete`

Phase 2B grants these abilities only to users who satisfy the existing administrator boundary. Controllers still call policies so later role separation does not require route rewrites.

CSRF protection remains enabled through Laravel's web middleware. No catalog mutation uses GET. Public and admin errors never expose storage paths, SQL details, exception messages, or unauthorized record existence.

Register and attach a named catalog-write limiter to every mutation route:

- 20 attempts per minute per administrator ID and IP;
- 100 attempts per hour per IP.

Laravel's standard HTTP 429 response is returned when either boundary is exceeded.

All uploads are validated again server-side. Browser previews are convenience only. Rich HTML is not accepted in catalog copy, so Vue renders catalog text using escaped interpolation and never `v-html`.

## Admin Interface

Add `Catalog` to the existing admin sidebar without changing the booking console.

### Catalog index

The index displays categories and their packages with:

- localized primary name and secondary translation;
- Draft, Published, or Archived badge;
- package and option counts;
- public visibility warning when a published package belongs to an inactive category;
- edit, publish, archive, restore, or delete actions when allowed.

It includes clear empty states and links to create a category or package. It does not add speculative search, bulk actions, pagination, or drag-and-drop ordering in this phase.

### Category editor

The category form includes bilingual names, slug, display order, image preview, replacement, and removal. The slug becomes read-only after first publication. Lifecycle actions remain separate from field save.

### Package editor

One page contains:

- category and identity fields;
- bilingual content fields;
- VIP and display-order controls;
- cover and gallery upload previews;
- variant rows;
- extension rows;
- validation summary and field-level errors;
- lifecycle actions appropriate to the current state.

New packages show `Save draft` and `Publish`. Previously published packages show `Save changes`, `Archive`, or `Restore` as applicable.

Rows can be added and removed without navigating away. Removing a row from the browser is only a requested reconciliation; the server decides whether to delete or archive it.

Destructive actions use accessible confirmation dialogs that identify the affected record and explain whether the action archives or permanently deletes a draft. Processing states disable repeat submission without hiding errors.

## Public Interface

`GET /services` renders a real menu page grouped by published category. Each category has localized heading, image where available, and package cards with starting price, available durations, VIP status, and a detail or booking action.

The homepage menu section uses the same grouped catalog source. The current hard-coded two-package comparison is replaced with a layout that handles zero, one, or many categories and packages.

`GET /services/{slug}` remains the detail route. It renders the chosen package, all active published options, and up to three related packages from the same category.

Booking entry points continue requiring authentication according to the existing flow. Catalog browsing remains public.

All public layouts must work in Indonesian and English, preserve the current Shin Joy visual direction, and remain usable at mobile, tablet, and desktop widths.

## Internationalization and Accessibility

All new admin and public strings are added to the existing Indonesian and English translation boundary. Database content requires paired localized fields where specified.

Forms provide:

- persistent labels;
- help text for slug permanence and image rules;
- `aria-describedby` links to field errors;
- keyboard-operable add, remove, publish, archive, and dialog controls;
- visible focus states;
- announced processing and status messages;
- meaningful image preview alternatives.

Status is communicated with text and not color alone.

## Error Handling

- Validation failures return normal translated Laravel validation errors.
- Stale versions return a translated `version` validation error instructing the administrator to reload.
- Unauthorized access returns 403 without record details.
- Missing public, draft, or archived public slugs return 404.
- Duplicate slug or code database collisions are converted to normal field validation errors rather than HTTP 500.
- Storage failures produce a generic admin-facing error and detailed server log context without leaking paths.
- Deleting an ineligible record returns a clear translated lifecycle error.

## Testing Strategy

Implementation follows test-driven development: focused failing test, minimal implementation, refactor, and regression verification.

### Laravel feature and integration tests

Cover:

- exact safe Inertia props for catalog index, category form, package form, home, menu, and service detail pages;
- administrator middleware and every policy action;
- non-admin and unverified access denial;
- category and aggregate package create and update;
- draft, publish, archive, restore, and hard-delete rules;
- legacy publication backfill;
- public exclusion of draft and archived records;
- dynamic categories and more than two packages;
- deterministic ordering and eager-loading query behavior;
- related packages limited to the same category;
- publication validation boundaries;
- slug and code immutability after publication;
- optimistic concurrency conflicts;
- nested child ownership and duplicate-ID rejection;
- active extension limit per mode;
- unique collision conversion to validation errors;
- both write-throttle boundaries;
- image MIME, size, dimension, count, and arbitrary-path rejection;
- file cleanup after validation, transaction, and replacement outcomes;
- legacy `/img` assets never being deleted;
- booking quote rejection for hidden packages and options;
- historical booking snapshots surviving catalog lifecycle changes.

Storage tests use `Storage::fake('public')`. Database tests continue using the isolated test database strategy already established by the project.

### Vue component tests

Mount real components and cover:

- catalog state badges and available actions;
- category form labels, errors, processing, and payload;
- aggregate package form input and exact multipart payload construction;
- add and remove behavior for variant and extension rows;
- image preview, replacement, retained gallery, and limit behavior;
- slug read-only state after publication;
- stale-update and server-validation display;
- accessible confirmations and keyboard behavior;
- Indonesian and English switching;
- homepage and menu rendering with zero, one, and many records;
- service detail and related-package rendering.

Mock Inertia navigation and forms only at their boundary. Avoid brittle source-text, attribute-order, and whitespace assertions.

### Playwright tests

Use the dedicated SQLite Playwright database and global setup to cover:

1. administrator creates a category draft and publishes it;
2. administrator creates a package with uploaded cover, gallery, variants, and extension;
3. draft remains absent from public menu and direct URL;
4. package publication makes it visible on home, menu, and detail pages;
5. a customer can select the published option and enter the existing authenticated booking flow;
6. package archive removes it from public surfaces and booking quotes;
7. package restore returns it after validation;
8. a second administrator session receives a stale-edit error;
9. non-admin users cannot reach or mutate the catalog;
10. Indonesian and English catalog content render correctly.

Uploaded test fixtures are small committed JPEG, PNG, and WebP files with no secrets or client assets.

## Verification Gates

Before Phase 2B is declared complete, run:

```text
php artisan test
php artisan test --coverage --min=80
npm run test:frontend
npm run test:e2e
npm run types:check
npm run build
php artisan route:cache
php artisan route:clear
php artisan config:cache
php artisan config:clear
composer audit
npm audit --audit-level=high
```

Also run secret scans, forbidden-runtime scans, focused formatter checks on owned files, and fresh code-quality and security reviews.

A missing PHP coverage driver is reported as an environment blocker. It is never reported as passing coverage. Existing unrelated formatter debt is recorded separately and is not silently rewritten as part of this phase.

## Deployment Notes

- Run database migrations before exposing the new admin routes.
- Ensure the production public storage disk is configured and writable.
- Run `php artisan storage:link` or provide the deployment platform's equivalent public-disk mapping.
- Set application and asset URLs correctly for HTTPS so uploaded image URLs are secure.
- Back up the catalog tables and managed upload directory before migration.
- Confirm current Nezuko and Nezuko VIP records remain visible after backfill.
- Warm route and configuration caches after deployment.
- No real `.env` values, credentials, or secrets are committed.

## Acceptance Criteria

Phase 2B is accepted when:

- an authorized administrator can create a new category and package without code changes;
- images are uploaded directly and safely stored;
- an incomplete draft never appears publicly;
- publishing exposes only a valid complete package;
- published edits are explicit and immediately public;
- archived content disappears from public pages and booking quotes;
- eligible records restore successfully;
- only unused never-published drafts can be permanently deleted;
- published and booking-referenced records retain historical integrity;
- the homepage and `/services` support arbitrary catalog size;
- public and admin pages work in Indonesian and English;
- stale edits cannot overwrite newer changes;
- Laravel, Vue, Playwright, type, build, cache, audit, and security gates pass, apart from explicitly documented environment-only blockers.
