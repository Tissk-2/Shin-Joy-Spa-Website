# Booking Persistence Design

## Summary

Turn the existing verified-customer booking review into a persistent Laravel booking flow. A customer selects one active official package variant, optionally adds one compatible extension, enters appointment and contact details, reviews the selection, and confirms it. Laravel resolves the catalog choice again, creates an immutable booking snapshot, and redirects to a customer-only booking detail page. Customers can also browse their own upcoming and past bookings.

## Scope

- Persist bookings only for authenticated customers with verified email addresses.
- Keep the existing Nezuko and Nezuko VIP configuration journey.
- Create each booking with status `pending`; reserve `confirmed`, `completed`, and `cancelled` for later operational work.
- Give every booking a non-sequential, customer-visible reference.
- Protect duplicate form submission with a server-issued UUID and a canonical request fingerprint.
- Provide bilingual My Bookings index and detail pages.
- Keep all booking data in Laravel and the database; never use browser-local authentication or booking storage.

## Non-goals

- Availability, therapist, room, capacity, or overlap rules.
- Admin access or status-management endpoints.
- Customer cancellation or rescheduling.
- Email, WhatsApp, or other notifications.
- Payment, deposits, refunds, or gateway integration.
- Multiple treatments or different treatment choices per guest.

## Booking Record

Phase 1 uses a single `bookings` table because the current product permits exactly one package variant and one optional extension per booking. The record contains:

- Nullable `user_id`, with `nullOnDelete`, so a customer's account deletion does not erase the spa's booking record.
- Nullable catalog foreign keys, also with `nullOnDelete`, plus immutable customer-facing snapshots.
- A unique `reference` formatted as `SJ-` followed by an uppercase ULID.
- A server-issued UUID `submission_key`, unique per user, and a SHA-256 `request_fingerprint`.
- A backed status value: `pending`, `confirmed`, `completed`, or `cancelled`.
- Customer name, account email, and normalized phone snapshots.
- Appointment date, local start time, `Asia/Jakarta` timezone, guests, and optional notes.
- Package slug, localized package names, VIP flag, mode, base choice code/duration/price, optional extension code/duration/price, and calculated totals.

The snapshots remain the display source after creation. Later catalog edits must not rewrite historical bookings.

## Server Trust Boundary

The browser submits contact fields, catalog identifiers, appointment fields, guest count, notes, and the server-issued submission key. It never owns `user_id`, email, reference, status, prices, totals, package names, or durations beyond the identifiers needed to resolve a choice.

Laravel must:

1. Validate scalar shapes, length boundaries, dates, the existing half-hour time slots from 09:00 through 20:00, guests 1 through 4, and the UUID submission key.
2. Normalize the customer name and phone with the existing customer identity rules and take email from the authenticated user.
3. Re-query the active publicly visible service, active variant, and active compatible extension.
4. Calculate all snapshot durations and prices from the database.
5. Create the booking in a database transaction.
6. Redirect with HTTP 303 to the booking detail page.

Invalid or stale catalog identifiers produce normal field validation errors. Extra client fields such as price, status, reference, email, or user ID are ignored.

## Duplicate Submission

The booking page receives a new UUID `submissionKey` from Laravel. The canonical normalized payload, excluding the key, is hashed before persistence.

- Reusing a key with the same fingerprint returns the existing booking.
- Reusing a key with a different fingerprint returns a validation error.
- The unique user/key database constraint is the final concurrency guard.
- Different users may safely receive or submit the same UUID without sharing records.

## HTTP And Inertia Contract

- `GET /booking` renders the form and server-issued submission key.
- `POST /bookings` creates or replays one booking.
- `GET /bookings` renders the authenticated customer's upcoming and past bookings.
- `GET /bookings/{reference}` renders one owned booking; another customer's reference returns 404.

All four routes require `auth` and `verified`; the POST route is rate-limited. Inertia props expose allow-listed snapshot data only, never internal database or foreign-key IDs.

## User Experience

The first submit performs the current client-side checks and opens the review panel. The review panel explicitly says the booking has not yet been submitted and provides Edit and Confirm actions. Confirm posts to Laravel, disables while processing, displays server validation errors, and redirects to the persisted detail page. The detail page shows the reference and pending status. The account menu links to My Bookings.

The My Bookings index separates appointments at or after the current Jakarta time from past appointments. Upcoming bookings are ordered soonest first; past bookings are ordered newest first. Cancelled bookings remain in their chronological group in Phase 1.

## Product Rulings For Phase 1

- The selected treatment subtotal is stored once and is not multiplied by guest count. This preserves the current quoted UI until the client confirms whether pricing is per person.
- The current 09:00-20:00 half-hour selection is validation, not an availability promise.
- Deleting a customer account anonymizes ownership by nulling `user_id` while retaining the immutable booking record.
- `/bookings` is separate from `/profile`; profile remains account settings while My Bookings becomes the operational history destination.

## Acceptance Criteria

1. A verified customer can confirm a valid booking and receives one persistent pending record.
2. Guests and unverified customers cannot create or view bookings.
3. Laravel owns booking user, email, reference, status, names, durations, and prices.
4. Stale, inactive, incompatible, malformed, and array-shaped selection inputs fail safely.
5. Identical duplicate submissions resolve to one booking; conflicting reuse is rejected.
6. A customer's index and detail pages reveal only that customer's safe booking snapshots.
7. Catalog edits do not change an existing booking's displayed snapshot.
8. The form, index, detail, statuses, errors, and navigation are bilingual and accessible.
9. No availability, admin, notification, or payment behavior is introduced.

