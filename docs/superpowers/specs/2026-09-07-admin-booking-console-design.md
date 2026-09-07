# Admin booking console design

## Summary

Phase 2A gives Shin Joy Spa a secure Laravel administrator area for reviewing persisted bookings and moving them through a controlled status lifecycle. It reuses the existing Fortify session, verified user accounts, booking snapshots, bilingual interface, and Inertia application. It does not add payment, scheduling capacity, notifications, or catalog editing.

The first useful admin release is intentionally narrow. Staff need to find a booking, inspect the contact and treatment details, and record what happened to it. Building menu management, content management, and payments at the same time would make the authorization boundary harder to verify.

## Current state

- Customer authentication uses Laravel Fortify, database sessions, email verification, password recovery, and safe intended redirects.
- The `users` table does not distinguish customers from administrators.
- Bookings persist an authoritative customer and catalog snapshot with one of four statuses: `pending`, `confirmed`, `completed`, or `cancelled`.
- Customer booking routes require authentication and email verification, then scope every record by `user_id`.
- The Laravel runtime has no administrator routes.
- `src/Pages/Admin.vue`, `src/Pages/AdminLogin.vue`, and `src/css/admin.css` belong to the retired standalone prototype. They contain dummy records, Vue Router navigation, browser-local sessions, hardcoded example credentials, destructive deletion, and the unsupported status `done`.
- `resources/js/data/translations.ts` already contains useful Indonesian and English admin copy, but the runtime has no Laravel-backed admin pages.

## Goals

- Authorize administrators without creating a second authentication system.
- Let a server operator grant and revoke administrator access without handling account passwords.
- Provide a responsive admin booking console with operational counts, search, filters, pagination, and booking detail.
- Enforce the booking status lifecycle on the server.
- Record every status change as immutable audit history.
- Keep all customer-facing booking pages synchronized with the persisted status.
- Preserve the existing Shin Joy visual direction and Indonesian and English locale boundary.
- Add backend, frontend, and browser coverage for the complete administrator flow.

## Non-goals

- Service, category, variant, extension, gallery, or price CRUD.
- Customer account management or administrator account management screens.
- Editing or deleting bookings.
- Editing booking contact details, appointment details, treatment snapshots, prices, or notes.
- Customer cancellation or rescheduling requests.
- Therapist, room, capacity, or availability management.
- Transactional email, WhatsApp, or other notifications.
- Payment, deposits, refunds, or a payment gateway.
- Revenue reporting or business analytics beyond the four booking counts in this phase.
- A separate REST API, SPA token authentication, Vue Router, or browser-local administrator sessions.

## Approaches considered

### One user table with explicit roles

This is the chosen approach. The existing `users` provider, web guard, Fortify flows, verification, password reset, session security, and throttling remain authoritative. A role cast and server-side authorization decide whether a verified user may enter `/admin`.

This has the smallest security and maintenance cost for a spa with a small staff. An administrator may still use the customer website under the same identity.

### Separate administrator table and guard

A separate provider, guard, password broker, login flow, reset flow, and session policy would isolate staff identities. That cost is justified only if administrators need a separate account lifecycle, stronger authentication requirements, or organizational separation. None of those requirements exist for Phase 2A.

### Shared administrator password

A single shared credential would make attribution, revocation, password recovery, and audit history unreliable. The project will not implement this approach.

## Authorization model

Create a backed `UserRole` enum with exactly two values:

```text
customer
admin
```

Add a required, indexed `role` column to `users`, defaulting to `customer`. The `User` model casts the field to `UserRole`. Public registration and profile updates never validate, fill, or accept `role`.

All admin content and mutation routes use the existing `web` guard and these checks in order:

1. The visitor has an authenticated Laravel session.
2. The account has a verified email address.
3. The account role is `admin`.

An `EnsureUserIsAdmin` middleware enforces the route boundary. A `BookingPolicy` also authorizes `viewAny`, `view`, and `updateStatus` so controller behavior does not depend on route placement alone.

Unauthenticated visitors follow the existing safe intended-login flow. Unverified accounts go to the verification notice. An authenticated customer receives HTTP 403. Revoking a role takes effect on the next request even if the old session remains active.

The Inertia shared data adds `auth.canAccessAdmin` as a boolean. It does not add a client-controlled permission store. Public navigation may use this boolean to show an Admin Panel link, but hiding a link never replaces the server checks.

## Administrator provisioning

There is no public administrator registration and no seeded administrator credential.

The server provides two console commands:

```text
php artisan admin:grant user@example.com
php artisan admin:revoke user@example.com
```

The target must already exist through the normal registration flow. Granting access requires a verified email address. Both commands normalize the email through the existing `CustomerIdentity` boundary, display the exact target, and request interactive confirmation. An explicit `--yes` option supports controlled non-interactive deployment and automated tests.

Granting an existing administrator and revoking an existing customer are successful no-ops with clear output. Revocation locks the current administrator rows in one transaction, recounts them after acquiring the locks, and refuses to revoke the final administrator. The `--yes` option never bypasses final-administrator protection.

The commands never accept, generate, print, or reset a password.

## Booking status lifecycle

The allowed transitions are:

| Current status | Allowed next status      |
| -------------- | ------------------------ |
| `pending`      | `confirmed`, `cancelled` |
| `confirmed`    | `completed`, `cancelled` |
| `completed`    | none                     |
| `cancelled`    | none                     |

The administrator interface does not render arbitrary status options. The server still validates the transition independently.

The status form submits both `currentStatus` and `nextStatus`. `currentStatus` is an optimistic concurrency value. The transition action locks the booking row, verifies that the persisted status still equals `currentStatus`, and rejects a stale form before changing anything.

Completed and cancelled bookings are final in Phase 2A. Correcting a terminal status needs a later exception workflow with a mandatory reason and stronger authorization. This phase does not silently reopen records.

## Audit data

Create an append-only `booking_status_changes` table with these fields:

| Column          | Purpose                                                |
| --------------- | ------------------------------------------------------ |
| `id`            | Primary key                                            |
| `booking_id`    | Required booking relationship with restricted deletion |
| `admin_user_id` | Nullable actor relationship using `nullOnDelete`       |
| `actor_name`    | Administrator name snapshot                            |
| `actor_email`   | Administrator email snapshot                           |
| `from_status`   | Previous `BookingStatus` value                         |
| `to_status`     | New `BookingStatus` value                              |
| `reason`        | Nullable trimmed reason, maximum 500 characters        |
| `ip_address`    | Nullable request IP, maximum 45 characters             |
| `user_agent`    | Nullable request user agent, maximum 1,024 characters  |
| `created_at`    | Change timestamp                                       |

The table has no `updated_at` column and no application update or delete route. The model guards all attributes against ordinary mass assignment. The status action creates audit rows through an explicit internal payload.

The booking status update and audit insertion occur in the same database transaction. If either write fails, neither change remains. The booking row lock prevents two administrators from applying transitions to the same old status at the same time.

Deleting bookings remains unsupported. Existing booking retention behavior therefore remains unchanged.

## Booking mass-assignment boundary

Before adding the status mutation endpoint, guard all `Booking` attributes against ordinary mass assignment. `CreateBooking` keeps sole ownership of booking creation and uses `forceCreate()` with its existing explicit server-built payload.

The status transition action changes only `status` through a direct model assignment after authorization and validation. No request payload can assign `user_id`, reference, submission key, fingerprint, contact snapshots, appointment fields, treatment snapshots, or prices.

## Admin routes

Put admin routes in `routes/admin.php` and require that file from the web route configuration. Use the `/admin` prefix and `admin.` route-name prefix.

| Method  | Path                                 | Route name                     | Purpose                                                           |
| ------- | ------------------------------------ | ------------------------------ | ----------------------------------------------------------------- |
| `GET`   | `/admin`                             | `admin.index`                  | Redirect to the booking console                                   |
| `GET`   | `/admin/login`                       | `admin.login`                  | Enter the existing safe intended-login flow for `/admin/bookings` |
| `GET`   | `/admin/bookings`                    | `admin.bookings.index`         | Render counts, filters, and paginated bookings                    |
| `GET`   | `/admin/bookings/{reference}`        | `admin.bookings.show`          | Render one booking and its status history                         |
| `PATCH` | `/admin/bookings/{reference}/status` | `admin.bookings.status.update` | Apply one allowed audited transition                              |

`GET /admin/login` does not own credentials or create another login POST. For a guest, it stores the fixed internal path `/admin/bookings` in the existing intended-path session key, then redirects to Fortify's login route. It never accepts a destination from request input. Authenticated users proceed directly to the admin boundary and receive the normal authorization result.

The login entry route is the only `/admin` route outside the administrator middleware group. It exposes no booking data and performs no authentication itself.

The status endpoint uses a named limiter with both boundaries:

- 30 attempts per minute for the authenticated administrator and IP combination.
- 100 attempts per hour per IP.

Exceeding either limit returns Laravel's standard HTTP 429 response.

## Index query contract

An `AdminBookingIndexRequest` accepts only these optional query fields:

```text
scope: upcoming | past | all
status: pending | confirmed | completed | cancelled
q: trimmed string, maximum 100 characters
dateFrom: valid Y-m-d date
dateTo: valid Y-m-d date on or after dateFrom
page: positive integer
```

The default scope is `upcoming`. Appointment comparisons use `Asia/Jakarta`, matching persisted booking timestamps. Upcoming results sort by appointment date and time ascending. Past results sort descending. The All scope sorts by appointment date and time descending.

Search performs a case-insensitive, parameter-bound match against reference, customer name, customer email, and customer phone. Literal `%`, `_`, and backslash characters do not become wildcard controls. Filters compose with search and preserve their values in pagination links.

The server returns 20 bookings per page. Invalid filters return a normal validation response instead of changing the query silently.

The four summary counts do not change with table filters:

- `today`: bookings whose appointment date is today in `Asia/Jakarta`.
- `pending`: all pending bookings.
- `confirmed`: all confirmed bookings.
- `completed`: all completed bookings.

## Inertia response contracts

An `AdminBookingPresenter` owns every administrator booking prop. It does not reuse the customer presenter because administrators need contact details and audit history.

The index response contains:

```text
counts.today
counts.pending
counts.confirmed
counts.completed
filters.scope
filters.status
filters.q
filters.dateFrom
filters.dateTo
bookings.data[]
bookings.links[]
bookings.currentPage
bookings.lastPage
bookings.total
```

Each index item contains only:

```text
reference
status
package.nameId
package.nameEn
package.isVip
treatmentMode
appointment.date
appointment.time
appointment.timezone
customer.name
customer.email
customer.phone
guests
totalDurationMinutes
totalPriceIdr
allowedTransitions[]
```

The detail response adds the base treatment, optional extension, customer notes, and ordered status history. Each history item contains actor name and email snapshots, old and new statuses, reason, and `createdAt`. It does not expose internal database IDs, submission keys, request fingerprints, IP addresses, user agents, exception text, or configuration.

The IP address and user agent remain server-side audit evidence. They are not needed for normal spa operations in this release.

## Status update request and action

`UpdateBookingStatusRequest` accepts exactly:

```text
currentStatus
nextStatus
reason
```

Both status fields must be scalar supported enum values. The reason is nullable, trimmed, and limited to 500 characters. The request authorizes through `BookingPolicy::updateStatus`.

`TransitionBookingStatus` receives the authorized administrator, booking, validated current status, validated next status, optional reason, IP address, and user agent. It owns the transaction, row lock, transition check, direct status assignment, and audit creation.

Invalid lifecycle changes return a translated field error for `nextStatus`. A stale `currentStatus` returns a translated form error that tells the administrator to refresh. Database failures return the existing production-safe error response and log server context without customer secrets or credentials.

A successful update redirects to the admin booking detail with HTTP 303 and a translated flash status.

## Admin interface

Create new runtime files under `resources/js/layouts/admin` and `resources/js/pages/admin`. Do not import the standalone prototype into the Laravel application.

The old prototype remains a visual reference for the plum sidebar, warm surfaces, rose and gold accents, compact data tables, and responsive top navigation. The implementation uses the current Laravel typography and design tokens. It removes dummy charts, content editing, service editing, category editing, delete controls, browser-local state, and Vue Router components.

The first release has two pages:

### Booking console

- Four summary cards for today, pending, confirmed, and completed.
- Upcoming, Past, and All scope controls.
- Search, status, date-from, and date-to filters submitted through an Inertia GET visit.
- A desktop table and a mobile card layout rendered from the same server data.
- Clear loading, empty-filter, and no-booking states.
- Pagination that preserves the active query.
- Each row links to the booking reference detail page.

### Booking detail

- Reference and current status.
- Appointment, package, treatment mode, duration, guests, and total price.
- Customer name, email, phone, and notes.
- Base treatment and optional extension snapshots.
- Valid next-status actions only.
- A confirmation step that states the old and new status before submission.
- Optional reason input.
- Chronological status history after at least one change.
- Back link that preserves the prior filtered index query when it is a safe internal URL.

The interface never offers deletion, editing, payment, availability, notification, cancellation reversal, or rescheduling.

## Internationalization and accessibility

Add paired `ad.*` strings only where the current dictionary lacks the required copy. Indonesian and English keys must retain recursive parity.

Every filter and form control has a visible label. Validation messages connect through `aria-describedby`, status feedback uses an appropriate live region, tables have descriptive headers, and mobile cards preserve the same information order. Status is communicated through text as well as color.

Interactive targets meet the existing 44 px touch target rule. Focus indicators remain visible. The layout supports keyboard navigation, narrow screens, zoom, and reduced motion. Confirmation does not depend on `window.confirm`; it uses an accessible in-page or dialog component with focus management.

## Security and privacy

- Laravel sessions and CSRF middleware protect administrator requests.
- Fortify remains the only credential authentication owner.
- All administrator access is checked on the server.
- Customer role assignment never crosses a public request boundary.
- Status values and transitions use enum allow-lists.
- Queries use Eloquent parameter binding and validated filters.
- Vue renders customer content through interpolation and never uses `v-html`.
- Administrator pages receive customer contact data because it is required for booking operations. They receive no auth secrets or idempotency fields.
- Status update errors do not disclose SQL, configuration, session data, or another user's credentials.
- Audit request metadata stays out of normal Inertia props.
- The application adds no local or session storage for auth, permissions, filters, or booking data.
- Dependency audits, secret scans, and forbidden-runtime scans remain release gates.

## Testing strategy

Implementation follows test-driven development. Each behavior starts with a focused failing test, then the minimum implementation, refactoring, and a fresh review.

### Laravel tests

- The role migration defaults existing and newly registered users to customer.
- The `User` cast returns the exact role enum.
- Registration and profile updates cannot assign or change a role.
- Grant normalizes email, requires a verified account, is idempotent, and never changes passwords.
- Revoke is idempotent and refuses the final administrator under a transaction lock.
- Guests enter the login flow, unverified accounts enter verification, and customers receive 403 for every admin route.
- Revoked administrators lose access on their next request.
- `BookingPolicy` allows only administrators to list, view, and update status.
- The index returns exact allow-listed props, four counts, validated filters, deterministic ordering, literal search behavior, and 20-row pagination.
- The detail response returns its exact allow-list and ordered audit history without request metadata or internal identifiers.
- Every allowed transition succeeds and every other transition fails.
- Stale current status fails without changing the booking or writing history.
- A simulated audit insertion failure rolls back the booking update.
- Two attempts against the same old state produce at most one transition.
- Status update throttles enforce both boundaries.
- Customer booking pages show the updated status without receiving administrator-only history.
- Audit rows cannot be mass-updated or deleted through an application route.

### Vue tests

- The layout renders the administrator identity, navigation, locale controls, view-site link, and secure logout form.
- Counts, filters, preserved query values, pagination, empty states, desktop table, and mobile cards render from safe props.
- Search and filters submit the exact GET query without browser storage.
- Detail renders contact, treatment, notes, and history fields.
- Only server-approved next statuses appear.
- Confirmation, reason, processing, error, and success states are accessible.
- Indonesian and English copy remain paired.
- Forbidden imports reject Vue Router, legacy auth stores, and local or session storage.

### Playwright tests

The administrator journey uses the existing dedicated testing database and real Laravel and Vite servers:

1. Create and verify a customer through supported testing helpers.
2. Grant that account administrator access through the console command in test setup.
3. Log in and follow the intended admin redirect.
4. Find a seeded pending booking through search and filters.
5. Open its detail page and confirm the safe customer and treatment snapshot.
6. Confirm the booking and observe one real PATCH request.
7. Verify the audit history and customer-facing booking status.
8. Verify a stale second update fails safely.
9. Verify a normal customer receives HTTP 403 and no administrator booking data.

## Migration and deployment safety

- Add the role column with a database default of `customer` so existing accounts do not gain access.
- Add role and audit indexes needed by the defined queries.
- Do not seed an administrator or commit credentials.
- Deploy migrations before granting the first administrator.
- Register and verify the client owner's account, then run `admin:grant` from the server console.
- Confirm `/admin/bookings` access before sharing the deployment.
- Keep the existing real `.env` unchanged in source control.
- Rollback removes audit records and the role column only when the deployment has also removed all Phase 2A code. Production rollback must account for lost audit history before running the down migration.

## Acceptance criteria

1. Existing customers retain customer access and receive no administrator role during migration.
2. A server operator can grant and revoke a verified user's administrator access without seeing or changing the password.
3. The system never permits revocation of the final administrator.
4. Guests, unverified users, customers, and revoked administrators cannot access admin booking data or status actions.
5. An administrator can view the four defined counts, search and filter bookings, paginate results, and inspect one booking.
6. Admin Inertia props expose only the approved operational fields.
7. An administrator can apply only the approved forward status transitions.
8. A stale or concurrent status request cannot overwrite a newer status.
9. Every successful status change produces one immutable audit row in the same transaction.
10. Customers see the updated status through their existing booking pages.
11. The admin interface works in Indonesian and English and meets the defined keyboard, labeling, focus, touch, and responsive requirements.
12. No booking deletion, editing, catalog CRUD, scheduling, notification, customer-management, or payment behavior enters Phase 2A.
13. Laravel, Vue, Playwright, TypeScript, production build, cache, formatting, dependency audit, secret, and forbidden-runtime checks pass. Coverage must reach at least 80 percent when a PHP coverage driver is available.
