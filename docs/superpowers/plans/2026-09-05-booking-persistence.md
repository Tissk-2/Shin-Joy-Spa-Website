# Booking Persistence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Follow strict RED-GREEN-REFACTOR and do not dispatch additional subagents.

**Goal:** Persist verified-customer bookings with authoritative snapshots, duplicate-submit safety, and bilingual customer booking history.

**Architecture:** A single Laravel `Booking` aggregate captures customer and catalog snapshots at confirmation. A request and quote resolver own boundary validation and database-backed price calculation. Inertia pages render allow-listed booking view models; Laravel scopes every read to the authenticated user.

**Tech Stack:** Laravel 13, Fortify, Eloquent, Inertia 3, Vue 3.5, TypeScript, Vitest, Playwright, SQLite tests.

**Spec:** `docs/superpowers/specs/2026-09-05-booking-persistence-design.md`

## Global Constraints

- Only authenticated, email-verified customers may use booking routes.
- Laravel is authoritative for ownership, account email, reference, status, catalog names, durations, and prices.
- Store immutable customer and catalog snapshots; customer pages read snapshots rather than the live catalog.
- Initial status is exactly `pending`; allowed values are `pending`, `confirmed`, `completed`, and `cancelled`.
- Use one server-issued UUID per rendered form plus a canonical request fingerprint for idempotency.
- Same-user/same-key/same-payload replay returns the existing booking; conflicting reuse is rejected.
- Return 404 for cross-account booking references and expose no internal IDs or foreign keys.
- Keep all new visible copy in both Indonesian and English with translation-key parity.
- Preserve accessible labels, error associations, focus behavior, responsive branding, and processing states.
- Do not use `localStorage`, `sessionStorage`, legacy auth stores, or `vue-router`.
- Do not implement availability, admin operations, notifications, cancellation/rescheduling, or payment.
- Do not multiply the treatment subtotal by `guests` during this phase.
- Preserve unrelated files and do not modify the developer's real `.env`.

### Task 1: Booking domain and schema

**Files:**

- Create: `app/Enums/BookingStatus.php`
- Create: `app/Models/Booking.php`
- Create: `database/factories/BookingFactory.php`
- Create: `database/migrations/2026_09_05_000000_create_bookings_table.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/BookingSchemaTest.php`

1. Write failing tests for the schema, casts, relationships, status enum, null-on-delete ownership/catalog links, snapshot persistence, unique reference, and per-user submission-key uniqueness.
2. Run `php artisan test tests/Feature/BookingSchemaTest.php` and confirm RED because the domain does not exist.
3. Add the migration, enum, model, factory, and `User::bookings()` relation with the exact spec columns and indexes.
4. Run the focused test to GREEN, apply Pint to owned PHP files, and rerun it.
5. Commit as `feat: add booking persistence domain`.

### Task 2: Authoritative request and quote resolution

**Files:**

- Create: `app/Http/Requests/StoreBookingRequest.php`
- Create: `app/Support/ResolvedBookingQuote.php`
- Create: `app/Support/BookingQuoteResolver.php`
- Create: `tests/Feature/BookingQuoteTest.php`
- Modify only if sharing exact query normalization is beneficial: `app/Support/BookingSelectionResolver.php`

1. Write failing tests proving a valid Nezuko regular 90-minute choice plus the 60-minute extension resolves to 150 minutes and Rp340,000 without mutating inputs.
2. Add failures for inactive/non-public services, inactive variants/extensions, wrong-mode extensions, array-shaped fields, malformed contacts/date/time, guests outside 1-4, and notes beyond 1,000 characters.
3. Add a test proving client-supplied user, email, status, reference, labels, durations, and prices cannot affect the resolved quote.
4. Run `php artisan test tests/Feature/BookingQuoteTest.php` and confirm RED for the missing resolver/request.
5. Implement the immutable quote object, strict request rules, existing customer normalization, active public catalog lookup, compatibility checks, and server totals.
6. Run focused tests to GREEN, apply Pint, rerun, and commit as `feat: resolve authoritative booking quotes`.

### Task 3: Idempotent booking submission

**Files:**

- Create: `app/Actions/CreateBooking.php`
- Modify: `app/Http/Controllers/BookingController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/BookingSubmissionTest.php`

1. Write failing feature tests for guest and unverified rejection, a verified customer's successful pending booking, exact normalized snapshots, HTTP 303 detail redirect, ignored hostile value fields, and a store-route rate limiter.
2. Add failing tests for same-key/same-payload replay, same-key/different-payload rejection, different-user reuse, and a simulated unique-key race path.
3. Run `php artisan test tests/Feature/BookingSubmissionTest.php` and confirm RED because `POST /bookings` is missing.
4. Implement a transaction-backed action that hashes the canonical quote payload, creates a unique `SJ-` ULID reference, handles idempotent replays without cross-user disclosure, and persists only controlled attributes.
5. Register `POST /bookings` with `auth`, `verified`, and a booking-store limiter; keep `GET /booking` under the same auth/verified boundary and add a new server UUID prop.
6. Run focused tests to GREEN, apply Pint, rerun, and commit as `feat: persist customer bookings`.

### Task 4: Safe booking index and detail contracts

**Files:**

- Create: `app/Support/BookingPresenter.php`
- Modify: `app/Http/Controllers/BookingController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/types/index.ts`
- Create: `tests/Feature/BookingPagesTest.php`

1. Write failing tests for verified-only index/detail, current-user-only results, 404 cross-account access, safe exact Inertia prop shapes, snapshot stability after catalog edits, and upcoming/past grouping around a fixed Jakarta time.
2. Assert upcoming sorts soonest first and past sorts newest first; cancelled records remain chronological.
3. Run `php artisan test tests/Feature/BookingPagesTest.php` and confirm RED because the routes/components are missing.
4. Add safe presenter summary/detail methods, user-scoped queries, grouping, and `GET /bookings` plus `GET /bookings/{reference}` routes.
5. Add TypeScript booking interfaces matching only the presenter contract.
6. Run focused tests to GREEN, apply Pint, run `npm run types:check`, and commit as `feat: expose customer booking history`.

### Task 5: Confirm the booking from the existing review UI

**Files:**

- Modify: `resources/js/pages/Booking.vue`
- Modify: `resources/js/lib/booking.ts`
- Create: `tests/frontend/bookingSubmission.test.ts`
- Modify: `tests/frontend/serviceCatalog.test.ts`
- Modify as necessary: `tests/frontend/bookingValidation.test.ts`
- Modify: `tests/frontend/publicPages.test.ts`

1. Write mounted failing tests for the two-step edit/review/confirm flow, exact POST payload without client price/ownership/status fields, server error rendering, processing disablement, submission-key stability, and preserved account prefills.
2. Retain behavior tests for client validation and the explicit “not submitted yet” review message.
3. Run the focused Vitest files and confirm RED because confirmation does not post.
4. Implement `useForm`, merge client and server validation display, keep the server-issued key stable, and post only the allowed scalar payload to `/bookings` from the Confirm action.
5. Keep browser storage forbidden and preserve accessible error associations and live status copy.
6. Run focused Vitest and `npm run types:check` to GREEN, format owned frontend files, and commit as `feat: submit reviewed bookings`.

### Task 6: Bilingual My Bookings pages and navigation

**Files:**

- Create: `resources/js/pages/bookings/Index.vue`
- Create: `resources/js/pages/bookings/Show.vue`
- Modify: `resources/js/layouts/MainLayout.vue`
- Modify: `resources/js/data/translations.ts`
- Modify: `resources/css/menu.css` or the smallest existing public-style file
- Create: `tests/frontend/bookingPages.test.ts`
- Modify: `tests/frontend/translation-parity.test.mjs`

1. Write failing mounted tests for Indonesian and English index/detail rendering, localized package names, all four status labels, empty state, reference, appointment, customer snapshot, extension, totals, notes, and the account-menu My Bookings link.
2. Run focused Vitest/parity tests and confirm RED because the components/keys do not exist.
3. Implement responsive branded pages using safe props, semantic lists/definition lists, navigable references, visible statuses, and accessible headings.
4. Add paired translations and account-menu navigation without advertising admin functionality.
5. Run focused Vitest, parity, types, and format checks to GREEN; commit as `feat: add customer booking pages`.

### Task 7: Browser flow and production safeguards

**Files:**

- Create: `tests/e2e/booking-persistence.spec.ts`
- Reuse: `tests/e2e/helpers/auth.ts`
- Modify only where behavior changed: existing booking/catalog E2E tests

1. Write a failing Playwright journey that configures Nezuko regular 90 + extension, registers/verifies when needed, reviews and confirms the booking, lands on its `SJ-` reference page, and sees it under My Bookings with 150 minutes and Rp340,000.
2. Add browser coverage for processing/double-click behavior and another customer's inability to open the reference.
3. Run the focused E2E file and confirm RED before final UI wiring, then make the smallest fixes necessary through tests.
4. Run the complete final gates:

```bash
php artisan test
php artisan test --coverage --min=80
npm run test:frontend
npm run test:legacy
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

5. Treat a missing PHP coverage driver as an environment blocker, not a pass. Run secret and forbidden-runtime scans plus `git diff --check` and branch-owned formatting checks.
6. Dispatch fresh code-quality and security reviews, fix all Critical/Important findings with covering tests, rerun affected and full gates, and commit as `test: cover persistent booking flow`.
