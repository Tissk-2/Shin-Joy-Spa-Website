# Telegram Booking Notifications — Design Contract

- **Date:** 2026-09-19
- **Feature phase:** Phase 6 queued job completed
- **Scope:** Staff-facing Telegram notification when a verified customer creates a new booking
- **Status:** awaiting orchestrator approval for Phase 7
- **Revision:** v2 (2026-09-19) — dispatch gated on the enabled flag (no no-op jobs), extension
  option/code field naming corrected to the persisted schema, snapshot semantics clarified
  (snapshots vs. stable values vs. delivery-time status), test boundaries separated
  (dispatch / formatter / client / job / failed-job), logging restricted to sanitized fields,
  HTTP failure classification refined (including `429` `retry_after`), throttle terminology
  corrected.
- **Revision:** v3 (2026-09-20) — Phase 2.1 correction pass: final retry exhaustion
  made explicit, positive sanitized failure logging required at the queued-job boundary,
  cached test log-channel reset required, and stale Phase 1 metadata corrected.
- **Revision:** v4 (2026-09-20) — Phase 3 configuration pass: added the disabled-by-default
  Telegram service configuration and empty deployment placeholders; no delivery, job, or
  booking-flow integration was introduced.
- **Revision:** v5 (2026-09-21) — Phase 4 formatter pass: booking message formatter
  implemented (plain text, Unicode-safe, dependency-free); timezone fixture corrected to a
  UTC instant; Created always rendered in Asia/Jakarta WIB regardless of the persisted
  appointment timezone; service-name sanitization and English-first package-name fallback;
  Unicode-character (mb_*) truncation; explicit irreducible over-limit failure contract
  (sanitized LogicException, permanent `formatting` category reserved for the job phase).
- **Revision:** v6 (2026-09-21) — Phase 5 client pass: TelegramClient and
  TelegramDeliveryException implemented. sendMessage posts chat_id, text, and
  link_preview_options {is_disabled: true} with a conditional integer message_thread_id;
  success requires strict ok === true and an integer result.message_id; malformed 2xx
  bodies, connection failures, 408/429/5xx and unclassified statuses are retryable;
  400/401/403/404 are permanent; positive integer parameters.retry_after is surfaced via
  retryAfterSeconds() and invalid values fall back to the job's ladder; the exception is a
  sanitized immutable carrier exposing permanent(), retryAfterSeconds(), httpStatus(), and
  telegramErrorCode(); no tokenized URL, response content, description, or raw connection
  exception is ever retained or logged. The queued job and booking dispatch integration
  remain intentionally unimplemented (Phase 6).
- **Revision:** v7 (2026-09-21) — Phase 5.1 correction pass: the delivery
  exception now has the fixed sanitized `Telegram delivery failed.` message,
  caller-controlled messages were removed, and no previous exception is
  retained; complete invalid `retry_after` coverage and exception-integrity
  assertions were added; the Phase 6 RED job handoff now correctly identifies
  only the queued job as unimplemented and strengthens the formatting-failure
  logging/no-HTTP contract.
- **Revision:** v8 (2026-09-21) — Phase 6 queued job pass:
  `SendTelegramBookingNotification` implemented as the queued delivery
  boundary with constructor-only booking ID storage, dependency-injected
  formatter/client handling, after-commit queue metadata, execution-time flag
  re-check, booking reload, sanitized configuration/formatting/missing-booking
  permanent failures, retryable Telegram/network release behavior, final-attempt
  failure, `retry_after` override handling, and success/failure logging limited
  to the approved structured fields. `CreateBooking` dispatch integration
  remains intentionally unimplemented and reserved for Phase 7.
- **Baseline:** Verified against working tree at commit `13b9ec4`, branch `codex/final-verification`, on 2026-09-19 (see Appendix A)

---

## 1. Purpose

Notify authorized staff in a private Telegram group when a verified customer
successfully creates a new booking, so staff can react faster than they would by
polling the admin console.

Telegram is a **secondary staff notification channel**. It is not the source of
truth. The Laravel database remains authoritative: the booking exists and is
actionable in the admin console whether or not the Telegram message was
delivered. A failed, delayed, or duplicated Telegram message never changes
booking state.

## 2. Trigger contract

A notification is eligible only when **all** of the following are true:

1. The customer is authenticated (`auth` middleware passed).
2. The customer's email address is verified (`verified` middleware passed).
3. Booking validation succeeds (`StoreBookingRequest` rules and normalization).
4. The server calculates and accepts the authoritative quote
   (`BookingQuoteResolver::resolve()` succeeds).
5. A brand-new `Booking` row is created (`Booking::forceCreate` in
   `CreateBooking::__invoke`).
6. The surrounding database transaction **commits successfully**.
7. Telegram notifications are enabled
   (`TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED=true`).

A notification is **never** sent when any of the following occurs:

- Authentication fails (guest request → redirected to login).
- Email verification fails (unverified customer → redirected to verification).
- Validation fails (form errors → redirect back with errors).
- Quote resolution fails (unknown service, variant, or extension).
- The database transaction rolls back (including the unique-constraint recovery
  path where no committed matching row exists).
- An **idempotent replay** returns an existing booking (same customer +
  `submission_key`, matching fingerprint — including the concurrent
  unique-constraint recovery path that returns the committed row).
- A booking is only viewed (`GET /booking`, `GET /bookings`, `GET
  /bookings/{reference}` never dispatch).
- Telegram notifications are **disabled** (`TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED=false`):
  `CreateBooking` skips the dispatch entirely, so no job is created (section 7).

The throttle middleware can reject `POST /bookings` with HTTP 429 before
booking creation. Because no `Booking` row is created, no Telegram job is
dispatched. (Throttle rejection is not a validation failure — it never reaches
the validation boundary — but it is listed here because it also produces no
job.)

## 3. Delivery architecture

```
customer request (POST /bookings)
  → StoreBookingRequest (validation + normalization)
  → BookingController::store
  → CreateBooking::__invoke
      → DB::transaction
          → idempotency check (return existing → no dispatch)
          → BookingQuoteResolver (authoritative quote)
          → Booking::forceCreate          ← the only branch that creates a row
          → if notifications are enabled: dispatch job (deferred until commit)
      → transaction commits               ← only now does the job exist in the queue
  → 303 redirect to /bookings/{reference}
      ... worker picks the job up later ...
  → job loads Booking by id
  → message formatter (plain text, persisted values only)
  → Telegram HTTP client (sendMessage)
  → private staff group
```

Hard requirements:

- Use the existing **Laravel database queue** (`QUEUE_CONNECTION=database`,
  `jobs` table). No new queue infrastructure.
- **Never** send Telegram requests inside the booking transaction.
- **Never** send synchronously from `BookingController`.
- Telegram latency or failure must not delay, fail, or roll back the booking.
  The only code added to the booking path is the enabled-flag read and one
  dispatch statement whose queue push is deferred until after commit.
- Dispatch **only** from the code path that creates a new `Booking` row, and
  **only when notifications are enabled**. An idempotent replay must not
  dispatch another job; a disabled feature must not dispatch at all.
- The queued job receives **only the booking identifier**, never request data
  or a formatted message.

### Why `CreateBooking` is the correct integration boundary

1. **Single creation branch.** `CreateBooking::__invoke` contains exactly one
   `Booking::forceCreate` call. Both idempotent paths — the pre-transaction
   `existingBooking()` lookup and the `QueryException` unique-constraint
   recovery — return an existing row *before* that branch, so a dispatch placed
   immediately after `forceCreate` inside the transaction fires only for
   genuinely new bookings.
2. **Commit coupling is free.** The dispatch sits inside `DB::transaction` and
   is deferred with Laravel's after-commit semantics: if the transaction rolls
   back, the job is never pushed to the queue. This satisfies "notify only
   after commit" without adding transaction-awareness to the controller.
3. **All eligibility gates have already passed.** Validation, verification,
   and quote resolution all run earlier in the same
   method, so no eligibility condition needs re-checking at the boundary.
4. **Persisted-value availability.** By the time the row exists, every message
   value is already persisted on the `bookings` row — creation snapshots for
   customer identity, appointment, package, mode, and pricing; stable values
   for `reference` and `created_at`; `status` is mutable and read at delivery
   time (section 4). The job never needs request data or the live catalogue.
5. **Controller stays thin.** `BookingController::store` remains a pure
   HTTP boundary (validate → act → redirect 303). Notification concerns do not
   leak into the HTTP layer, and future callers of `CreateBooking` (e.g. admin
   tooling) inherit the same trigger semantics automatically.

## 4. Approved message fields

Values come from the **persisted `Booking` row**, never from untrusted request
data and never re-resolved from the current mutable catalogue. Three classes of
values exist:

- **Creation snapshots** — written once by `BookingQuoteResolver` when the
  booking is created and never updated afterwards: customer identity,
  appointment selection, package, treatment mode, durations, and prices.
- **Stable persisted values** — `reference` and `created_at`.
- **Delivery-time values** — `status` is mutable and is read from the
  `Booking` row when the queued job runs; the message labels its **current
  persisted value at delivery** (typically `Pending`, but not guaranteed).
- The administrator URL is **not** a booking value at all: it is built from
  trusted application configuration (section 6), never from a booking snapshot
  and never from the incoming request's `Host` header.

| Message field | Source |
| --- | --- |
| Booking reference | `reference` (stable) |
| Booking status | `status` — mutable; the row's current persisted value at delivery time |
| Customer name | `customer_name` (creation snapshot) |
| Customer email | `customer_email` (creation snapshot, from the verified account) |
| Customer phone/WhatsApp | `customer_phone` (creation snapshot; may be empty → render `—`) |
| Package/service name | `package_name_en` or `package_name_id` (creation snapshot) |
| VIP state (when applicable) | `package_is_vip` (creation snapshot) |
| Treatment mode | `treatment_mode` (creation snapshot; `Regular` / `Double therapist`) |
| Appointment date | `appointment_date` (creation snapshot) |
| Appointment time | `appointment_time` (creation snapshot) |
| Timezone label | `appointment_timezone` (creation snapshot; `Asia/Jakarta` → `WIB`) |
| Number of guests | `guests` (creation snapshot) |
| Base treatment duration | `base_duration_minutes` (creation snapshot) |
| Base treatment price | `base_price_idr` (creation snapshot) |
| Extension option/code (when selected) | `extension_choice_code` (creation snapshot) |
| Extension duration and price (when selected) | `extension_duration_minutes`, `extension_price_idr` (creation snapshots) |
| Total duration | `total_duration_minutes` (creation snapshot) |
| Total price | `total_price_idr` (creation snapshot) |
| Customer notes (when provided) | `notes` (creation snapshot; truncated per section 6) |
| Booking creation time | `created_at` (stable), rendered in `Asia/Jakarta` |
| Administrator booking URL | built from `config('app.url')` + `/admin/bookings/{reference}` (application configuration, not a booking value) |

The `bookings` schema has **no human-readable extension-name column** — the
persisted extension fields are `extension_choice_code`,
`extension_duration_minutes`, and `extension_price_idr`. No migration and no
new snapshot column may be introduced in this version; the message renders the
persisted choice code (section 6). A human-readable extension-name snapshot is
deferred scope (section 12).

## 5. Forbidden message fields

Never include any of the following in the Telegram message (or in its
formatter inputs):

- `user_id`
- `service_id`
- `service_variant_id`
- `service_extension_id`
- `submission_key`
- `request_fingerprint`
- Session identifiers or cookies
- Passwords
- Authentication tokens
- The bot token
- Any application secret
- Database connection details

Internal identifiers are meaningless to staff, `submission_key` and
`request_fingerprint` are anti-abuse internals, and credentials must never
leave the server process.

## 6. Message format

Plain text only. **No Telegram `parse_mode` is used**, so no markup escaping is
required — customer-supplied characters (`<`, `>`, `*`, `_`, `&`, …) are literal
text. Structure comes exclusively from the formatter's own fixed labels and
line breaks.

### Sanitization rules

- Every customer-supplied value (name, email, phone, notes) has all Unicode
  control and format characters — including CR, LF, tab, U+202E (right-to-left
  override), U+200B (zero width space), and every other invisible character —
  removed before insertion; runs of whitespace collapse to a single space.
  Customer data therefore cannot change the message structure, inject fake
  labels, forge sections, or alter the visual order of the text.
- The message is assembled by the formatter only; line breaks in the output
  come exclusively from the formatter's template.

### Size and truncation

- Notes are capped at **500 characters**; longer notes are cut and end with a
  visible `[truncated]` marker.
- The fully assembled message is guarded to **at most 4,000 characters**
  (buffer below Telegram's 4,096 limit). Telegram counts Unicode characters,
  not UTF-8 bytes, so the guard measures characters (mb_strlen semantics);
  in the degenerate case the notes section shrinks first. Realistic messages
  are ~800–1,200 characters.
- **Irreducible over-limit failure contract:** valid schema-bounded booking
  data with a normal configured application URL always remains below 4,000
  characters (verified by a maximum-valid-field formatter test). Notes are
  the only field the formatter may reduce. If the non-note message still
  exceeds 4,000 characters — an invalid or misconfigured state — the
  formatter throws a sanitized `LogicException` instead of producing an
  invalid Telegram payload. The exception message identifies the 4,000-
  character invariant and contains no booking, customer, configuration, or
  message values.
- **Forward requirement for the queued-job phase:** this formatter invariant
  failure is permanent and must not burn the retry ladder; the job must
  eventually log it using a fixed sanitized category (`formatting`); and no
  formatted message, APP_URL, booking data, or exception context containing
  sensitive values may be logged. A corresponding RED job test is added
  before that behavior is implemented in the queued-job phase.

### Example message

Values mirror the seeded Nezuko menu (base 90 min = Rp 265.000, extension
60 min = Rp 75.000) so the example is verifiable against the catalogue seed:

```
NEW BOOKING — SHIN JOY SPA

Reference: SJ-01K4DR9M7JQ2ABCD3EFGHJKL01
Status: Pending

CUSTOMER
Name: Ayu Lestari
Email: ayu@example.com
Phone/WhatsApp: +62 812-3456-7890

APPOINTMENT
Service: Nezuko
Mode: Regular
Date: 21 September 2026
Time: 10:30 WIB
Guests: 2

DETAILS
Base treatment: 90 minutes — Rp 265.000
Extension: nezuko-regular-extra-60 — 60 minutes — Rp 75.000
Total duration: 150 minutes
Total price: Rp 340.000

Notes:
Focus on shoulders.

Created: 19 September 2026, 14:30 WIB
Admin: https://trusted-app.example/admin/bookings/SJ-01K4DR9M7JQ2ABCD3EFGHJKL01
```

Formatting requirements:

- Staff-facing labels are fixed English regardless of the customer's locale
  (`id`/`en` only affects customer-facing pages, not this message).
- The `Status:` line renders the booking's current persisted status at
  delivery time (typically `Pending`).
- When an extension exists, the line reads
  `Extension: {extension_choice_code} — {duration} minutes — {formatted price}`.
  There is no human-readable extension name in the schema; the persisted
  choice code is the rendered value.
- When no extension was selected, the `Extension:` line is omitted entirely.
- When no notes were provided, the `Notes:` block is omitted entirely.
- When the customer provided no phone number, render `Phone/WhatsApp: —`.
- The admin URL is built from the trusted configured application URL
  (`config('app.url')`), never from the incoming request's `Host` header.
- Currency renders as `Rp` + Indonesian thousands separators
  (e.g. `Rp 340.000`), matching the existing frontend convention.
- The service line shows VIP handling via the `package_is_vip` snapshot: when
  it is true, the service line reads `Service: <name> (VIP)`.

## 7. Configuration contract

Environment variables:

```
TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED=false
TELEGRAM_BOT_TOKEN=
TELEGRAM_STAFF_CHAT_ID=
TELEGRAM_MESSAGE_THREAD_ID=
```

Rules:

- Notifications are **disabled by default** (`ENABLED=false`).
- The bot token and staff chat ID are secrets/deployment inputs. They are
  never committed, never logged, and never appear in `.env.example` (which
  keeps empty placeholders only).
- `TELEGRAM_MESSAGE_THREAD_ID` is optional; it targets a Telegram group
  topic. It is sent on the API request only when it is set to a non-empty
  value.
- Configuration is read from the conventional Laravel service-configuration
  home (`config/services.php`, e.g. a `telegram` block mapping these env
  variables). Exact key naming is a Phase 2 implementation detail; the env
  names above are fixed.

Three configuration states:

- **Disabled** (`TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED=false`):
  `CreateBooking` does **not** dispatch a Telegram job. No job enters the
  queue, and the booking path performs no notification work beyond one boolean
  configuration read. The booking succeeds exactly as it would without the
  feature.
- **Enabled with complete configuration** (bot token and chat ID present):
  the job is dispatched **exactly once** from the new-row branch of
  `CreateBooking`, using after-commit semantics (section 3).
- **Enabled with missing bot token or chat ID:** the job **is** dispatched —
  the dispatch gate reads only the enabled flag, not configuration
  completeness — and detects the invalid configuration at execution time. It
  then fails **permanently** with a sanitized `configuration` failure category
  (straight to failed-job storage, no retry ladder burned on an error no retry
  can fix). The booking is untouched. Fixing the environment and re-running
  the failed job is the operator's recovery path. This observable failure is
  deliberate: an enabled-but-misconfigured deployment must be visible, not
  silently silent.

**Why skip dispatch while disabled instead of queueing no-op jobs:** a
dispatch-anyway/no-op-at-execution design would insert one dead job into the
`jobs` table for every booking made while the feature is off — unbounded
queue-table growth with zero value, plus worker churn draining them. Worse, if
the feature were enabled later, a drained backlog of those no-op jobs would
send notifications about long-stale historical bookings. Gating the dispatch on
the flag prevents both: nothing accumulates while disabled, and nothing stale
is ever sent. (The only disabled-execution path left is the race where the flag
is switched off after a job was already dispatched — handled in section 8.)

## 8. Queue and retry contract

- The job receives **only the booking identifier** (an `int`) in its
  constructor. Everything else is reloaded inside `handle()`.
- The job **reloads the persisted booking** at execution time
  (`Booking::findOrFail`). Creation snapshots and stable values come from the
  reloaded row; because `status` is mutable, the message reports the row's
  current status at execution time, which may differ from `Pending` if the
  booking changed between creation and delivery (section 4). If the row no
  longer exists, the job logs category `booking_missing` and fails permanently
  (nothing to notify about; no retries). The booking identifier remains
  visible in the queue/failed-job payload for diagnosis.
- The job **re-reads the enabled flag at execution time** as a safety net: if
  the flag was switched off after a job was already dispatched, the job exits
  as a clean no-op without any network call. The dispatch-time gate in
  `CreateBooking` is the authoritative control (section 7); this only covers
  the dispatch→execution race.
- The job runs **only after the creating transaction commits** (after-commit
  dispatch semantics from section 3). A rolled-back transaction therefore
  never produces a queued job.
- This will be the project's **first queued job**; it establishes the
  `app/Jobs` convention. There are no existing job, event, listener, or HTTP
  client patterns to inherit (verified in Appendix A); Laravel's standard
  job class + `Illuminate\Support\Facades\Http` client is the pattern to
  follow.
- **Retry policy:** one initial delivery attempt plus **three retries** — four
  executions maximum — with normal backoff delays of **60, 300, and 900
  seconds** (1, 5, and 15 minutes) before each retry. In Laravel terms:
  `$tries = 4` with `$backoff = [60, 300, 900]`. After the final retry fails,
  the job moves to Laravel **failed-job storage** (`failed_jobs` table, already
  present).
- Attempts 1, 2, and 3 may release retryable failures for 60, 300, and 900
  seconds respectively. On attempt 4, another retryable failure must fail the
  job permanently, must not release the job again, must leave the booking row
  unchanged, and must produce sanitized observable logging.
- **Failure classification:**
  - **Permanent** (no retries, straight to failed-job storage): incomplete
    enabled configuration, a missing booking row, and Telegram HTTP `400`,
    `401`, `403`, and `404` (malformed request, bad token, forbidden chat,
    unknown chat). Retrying cannot fix these.
  - **Retryable** (follow the retry ladder): connection failures, timeouts,
    Telegram HTTP `408`, `429`, and all `5xx` responses.
  - **HTTP 429 special case:** when Telegram's response contains a numeric
    `parameters.retry_after`, the job uses that delay (in seconds) **instead
    of** the normal ladder delay for that retry. If `retry_after` is missing
    or invalid, the normal configured backoff applies. The complete response
    body is never logged (section 9).
- **Success-shape validation:** an HTTP 200 response counts as success only
  when its JSON body has `ok === true` and an **integer** `result.message_id`.
  Any other 200 body — `ok=false`, missing `result`, missing or non-integer
  `message_id`, or a non-JSON body — is a sanitized **retryable** failure: it
  never returns a message ID and never leaks response content into logs.
- **sendMessage payload contract:** the client posts the message text, the
  chat ID, the optional integer `message_thread_id`, and
  `link_preview_options: {is_disabled: true}`. It never sends `parse_mode`
  (plain text) and never sends the deprecated `disable_web_page_preview`
  field.
- **Exception carrier:** delivery failures travel on
  `App\Support\TelegramDeliveryException`, exposing `permanent(): bool` and
  `retryAfterSeconds(): ?int`, and storing only sanitized numeric metadata
  (HTTP status, Telegram numeric `error_code`) — never the bot token,
  tokenized URL, chat ID, message body, customer data, or Telegram
  description.
- **Retrying the customer request must not create another job.** Job creation
  is tied to the single `forceCreate` branch; an idempotent replay returns an
  existing booking and never reaches it (section 3).
- **Delivery is at-least-once.** A rare duplicate message can still occur —
  for example when a worker crashes (or `retry_after`, default 90 s, expires)
  after Telegram accepted the message but before the job result was recorded.
  The operator-facing documentation must state this honestly.
- **No outbox table in the first version.** A delivery ledger / outbox table
  is documented as a **future enhancement only**, to be considered if the
  client requires stronger delivery auditing (exact-once guarantees,
  delivery-history UI). It is deliberately out of scope now.
- Queue deployment must be documented (see section 11): a long-running worker
  (`php artisan queue:work` under a process manager) is required for
  delivery; without a worker, jobs accumulate in the `jobs` table and are
  delivered when a worker starts. Hosting details are finalized during
  deployment planning (Appendix B).

## 9. Logging and privacy contract

Logs **may** contain:

- Booking reference.
- Job attempt number.
- Internal sanitized failure category (e.g. `configuration`, `telegram_http`,
  `network`, `booking_missing`).
- HTTP status.
- Telegram's numeric `error_code` where the API provides one.
- The Telegram response `message_id` after a successful send.

Logs **must not** contain:

- Customer name.
- Email address.
- Phone number.
- Notes.
- The full Telegram message body.
- The bot token.
- The Telegram API URL (it contains the token).
- The staff chat ID.
- Telegram's human-readable `description` string.
- Any response body from Telegram.

Additional rules:

- The queued job is the approved failure-observability boundary. It logs one
  sanitized event for each representative failure category before failing or
  releasing the job. Structured context uses fixed keys such as `category`,
  `attempt`, `booking_reference`, `http_status`, and `error_code`; the Telegram
  client is not required to duplicate those logs.
- **Third-party response content is untrusted.** Telegram's `description`
  text and response bodies are not under our control and are never written to
  logs; failures are characterized by HTTP status, numeric `error_code`, and
  the sanitized category only.
- The HTTP client must be configured so that no exception, log context, or
  debug dump can emit the tokenized request URL; the URL itself is never
  logged.
- The Telegram group must be **private**, contain **only authorized staff**,
  have controlled membership (reviewed on staff changes), and be treated as a
  **system containing customer personal data** — it must be covered by the
  business privacy and retention policy (staff must not forward messages
  outside the group; membership revocation on offboarding).

## 10. Test contract for Phase 2

Phase 2 must create automated tests proving each of the following. They belong
in the existing feature-test conventions (`tests/Feature`, `RefreshDatabase`,
in-memory SQLite per `phpunit.xml`). The test environment forces
`QUEUE_CONNECTION=sync`; `Queue::fake()` intercepts dispatches so that sync
execution never occurs during dispatch assertions. No real Telegram traffic is
ever made.

The contract is separated by **test boundary**, because `Queue::fake()`
executes nothing and persists no failures, while the formatter, client, and
job are each unit-testable in isolation:

### A. Booking dispatch tests — `Queue::fake()`

1. A new booking while **enabled** dispatches **exactly one** notification job.
2. An identical idempotent replay (same customer + `submission_key` + payload)
   dispatches **no additional** job.
3. **Disabled state (required test):** a successful new booking while
   notifications are disabled creates the booking (303 redirect, row
   persisted) and dispatches **zero** Telegram jobs.
4. Validation failure dispatches nothing.
5. Authentication failure (guest) dispatches nothing.
6. Verification failure (unverified customer) dispatches nothing.
7. Two overlapping booking requests each persist and dispatch one notification;
   staff confirm availability afterward.
8. A **failed creation before dispatch** (forced unique-constraint failure
   with no committed matching row, reusing the existing rollback-recovery
   test technique in `BookingSubmissionTest`) commits no booking and queues
   no job. The exception occurs inside `Booking::forceCreate`, before any
   dispatch statement; this test does not re-prove Laravel's after-commit
   discard behavior, which is covered by the job's `afterCommit` property
   contract, the dispatch placement exclusively after `forceCreate`, and
   Laravel's own transaction semantics.
9. Enabled with incomplete configuration still dispatches the job
   (configuration completeness is the job's responsibility).

### B. Formatter tests — formatter invoked directly

10. Includes **all** approved fields (section 4) with the example-layout
    values, including the extension line
    `Extension: {extension_choice_code} — {duration} minutes — {formatted price}`.
11. Excludes **every** forbidden field (section 5) — asserted by scanning the
    rendered message for internal identifiers, keys, and fingerprints.
12. Handles a missing extension (line omitted), missing notes (block omitted),
    and an empty phone (`—`).
13. Long notes are truncated below the Telegram limit, visibly (`[truncated]`);
    the limit counts Unicode characters (mb_strlen semantics), not UTF-8
    bytes.
14. Control **and format** characters embedded in customer data (CR/LF/tab,
    U+202E right-to-left override, U+200B zero width space, and other
    invisible characters) cannot corrupt the message structure or alter its
    visual order.
15. The `Status:` line renders the booking's **current persisted value at
    delivery time** (e.g. `Confirmed`) — never a hardcoded `Pending`.

### C. Telegram client tests — `Http::fake()` + `Http::preventStrayRequests()`

16. A successful request posts to the configured endpoint with the
    **configured chat ID**, the supplied text, and
    `link_preview_options: {is_disabled: true}`; `disable_web_page_preview`
    (deprecated) and `parse_mode` are absent; the integer `message_id` is
    returned.
17. The thread/topic ID is included **only** when
    `TELEGRAM_MESSAGE_THREAD_ID` is set to a non-empty value.
18. Malformed HTTP 200 bodies — `ok=false`, missing `result`, missing or
    non-integer `message_id`, non-JSON body — are sanitized **retryable**
    failures: no message ID is returned and no response content reaches the
    logs.
19. `Http::preventStrayRequests()` is armed for the whole suite, and no bot
    token, request URL, chat ID, or message body appears in the logs.

### D. Job tests — job/client boundary invoked deliberately

The job's `handle()` receives its collaborators through dependency injection
(`handle(TelegramBookingMessageFormatter $formatter, TelegramClient $client)`);
tests invoke it via the container (`$this->app->call([$job, 'handle'])`) so the
implementation is never forced to construct or service-locate its own
dependencies. The constructor still receives only `(int $bookingId)`.

20. Queue contract metadata: `ShouldQueue`, `Queueable` +
    `InteractsWithQueue`, `$tries = 4` (four executions maximum: initial
    execution plus three retries), `$backoff = [60, 300, 900]`, constructor
    stores the booking identifier.
21. Successful delivery through container-resolved formatter and client; the
    sanitized success log contains the booking reference, a recognizable
    `message_id` key/category, and the message ID itself — and none of the
    section 9 forbidden values.
22. Disabled-at-execution behavior is verified **only** for the race case: a
    job already queued before the flag was switched off exits as a clean
    no-op. There is no disabled-at-dispatch job to exercise — the dispatch
    gate prevents it (covered by test A.3).
23. Missing configuration (enabled, incomplete) fails **permanently** at the
    job's `fail()` boundary with sanitized observable logging: category
    `configuration`, attempt number, and the booking reference when available;
    the booking remains persisted.
24. A missing booking row fails **permanently** with sanitized observable
    logging: category `booking_missing` and attempt number, without inventing
    or logging customer data, and without contacting Telegram.
25. Response classification: `400`/`401`/`403`/`404` are permanent (no retry
    release); connection failure, timeout, `408`, `429`, and `5xx` are
    retryable.
26. Telegram HTTP failures log category `telegram_http`, attempt number,
    booking reference, HTTP status, and numeric Telegram `error_code` where
    available. Network/connection failures log category `network`, attempt
    number, and booking reference. These logs never include Telegram
    description text, a response body, bot token, tokenized URL, chat ID,
    formatted message, or customer name, email, phone, or notes.
27. The retry ladder is verified **per attempt**: attempt 1 releases after
    60 seconds, attempt 2 after 300 seconds, attempt 3 after 900 seconds
    (using the framework `FakeJob` attempts property; no reflection and no
    production-only test hooks).
28. On attempt 4, a representative retryable failure such as HTTP 500 fails
    the job permanently, does not release again, leaves the booking row
    persisted and unchanged, and produces sanitized category/reference/attempt
    logging.
29. A `429` carrying a numeric `parameters.retry_after` overrides the ladder
    for that release; invalid or missing `retry_after` falls back to the
    normal delay for the current attempt.
30. After every failure mode, the booking row remains persisted and unchanged,
    and log output stays within the section 9 allowlist.

### E. Failed-job integration

31. Either **(a)** a focused database-queue worker integration test — real
    database queue connection, job pushed and executed to permanent failure —
    proves the job reaches the `failed_jobs` table, or **(b)** the job's
    `fail()` behavior is explicitly verified at the job boundary (test D.23).
    `Queue::fake()` must **not** be presented as covering failed-job
    persistence: it executes nothing and stores nothing.

## 11. Acceptance criteria

The feature may be declared complete only when **all** of the following hold:

1. When enabled with complete configuration, one new booking produces exactly
   one queued notification.
2. An idempotent booking replay produces no additional notification.
3. When disabled, a new booking produces **zero** Telegram jobs — no no-op
   jobs accumulate in the queue.
4. Telegram availability (or total absence) never controls booking success.
5. All approved fields — including customer email and phone — are included.
6. All forbidden fields and secrets are absent from messages and logs.
7. Failures are retryable per section 8 and observable in failed-job storage
   and logs.
8. Queue deployment (worker command + process supervision) is documented for
   operators, including the at-least-once caveat.
9. Staff-group privacy requirements (private group, authorized membership,
   personal-data handling, retention policy) are documented.
10. Focused tests (section 10) and the full regression suite pass
    (backend `php artisan test`, frontend `npm run test:frontend`,
    `npm run types:check`, `npm run lint`, and the Playwright E2E suite).
11. An independent code-quality and security review of the implementation is
    clean.
12. The client approves the final Telegram message layout (section 6).

## 12. Deferred scope

Explicitly excluded from the first version:

- Notifications for booking status changes (confirm/cancel/reschedule).
- Customer-facing Telegram messages.
- WhatsApp integration.
- Two-way Telegram commands or replies.
- Creating or editing bookings from Telegram.
- Telegram inline buttons that perform admin actions.
- Payment notifications.
- A custom delivery/outbox database table (future enhancement only, section 8).
- Notification analytics dashboards.
- A human-readable extension-name snapshot column (would require a migration;
  the persisted `extension_choice_code` is rendered instead, section 4).

---

## Appendix A — Verified baseline (Phase 0)

Verified 2026-09-19 against working tree at `13b9ec4` (branch
`codex/final-verification`). The working tree carries extensive user-owned
uncommitted changes; none were altered during Phase 0/Phase 1.

**Booking flow (verified against current files):**

- Route: `POST /bookings` → `BookingController::store`, middleware
  `['auth', 'verified', 'throttle:booking-store']`
  (`routes/web.php:33`). Limiter: 6/min per user, 20/min per IP
  (`app/Providers/AppServiceProvider.php:38`).
- Controller: `store(StoreBookingRequest $request, CreateBooking
  $createBooking)` → `$createBooking($request->user(),
  $request->validatedBookingData())` → `redirect('/bookings/'.$booking->reference, 303)`
  (`app/Http/Controllers/BookingController.php:45`).
- Validation/normalization boundary: `StoreBookingRequest` — rules plus
  `validatedBookingDataFrom()` normalization (name/phone trimming via
  `CustomerIdentity`, blank-note/extension → null, `submission_key` must be
  UUID, appointment times 09:00–20:00 WIB on the half hour, and an appointment
  instant in the future in `Asia/Jakarta`).
- Quote boundary: `BookingQuoteResolver::resolve()` — service/variant/extension
  looked up from the published catalogue, totals computed server-side,
  customer email taken from the authenticated user (never the request).
- Transaction boundary: `DB::transaction` inside `CreateBooking::__invoke`
  (`app/Actions/CreateBooking.php:30`).
- New-row branch: `Booking::query()->forceCreate([...])`
  (`app/Actions/CreateBooking.php:45`) — the only branch that creates a row.
- Idempotent branches: pre-transaction `existingBooking()` +
  `matchingBooking()` fingerprint check (`:31-35, 69-86`), and the
  `QueryException` unique-constraint recovery path (`:54-66`) — both return
  an existing booking and never reach `forceCreate`.
- Booking requests may overlap; staff confirm availability after submission.
- Redirect: `303` to `/bookings/{reference}` (verified by
  `BookingSubmissionTest`).

**Queue readiness (verified):**

- `QUEUE_CONNECTION` defaults to `database` (`config/queue.php:16`); the
  local `.env` uses `database`. Tests force `sync` (`phpunit.xml`).
- Database queue tables exist and are migrated: `jobs`, `job_batches`,
  `failed_jobs` (migration `0001_01_01_000002_create_jobs_table.php`;
  confirmed present in the local SQLite schema).
- Failed-job storage exists (same migration).
- After-commit dispatch is available via standard Laravel job semantics;
  no project code uses jobs yet (`app/Jobs` does not exist — this feature
  establishes the convention). No `Http::` facade usage exists in `app/`.
- No queue-worker deployment instructions exist yet in `README.md` or
  `docs/` — Phase 2 must add them (section 11, criterion 8).
- `config/services.php` contains **no** Telegram configuration today.
- `.env.example` contains **no** `TELEGRAM_*` variables today (and must gain
  only empty placeholders in Phase 2).

**Files likely to be involved in later phases:**

- `app/Actions/CreateBooking.php` — the enabled-flag read and one dispatch
  statement (after commit), placed only in the new-row branch.
- `app/Jobs/SendTelegramBookingNotification.php` — new queued job (flag
  re-check, booking reload, classification, retry wiring).
- `app/Support/TelegramBookingMessageFormatter.php` (or equivalent) — formatter.
- `app/Support/TelegramClient.php` — Telegram HTTP client (sendMessage).
- `app/Support/TelegramDeliveryException.php` — sanitized delivery-failure
  exception (`permanent(): bool`, `retryAfterSeconds(): ?int`).
- Telegram HTTP client (in the job or a small dedicated support class).
- `config/services.php` — Telegram configuration block (Phase 2).
- `.env.example` — empty `TELEGRAM_*` placeholders (Phase 2).
- `README.md` — queue worker + notification deployment notes (Phase 2).
- `tests/Feature/` — new feature tests per section 10
  (e.g. `TelegramBookingNotificationTest.php`).
- Explicitly **not** touched: `BookingController`,
  `StoreBookingRequest`, `BookingQuoteResolver`, `Booking` model, routes,
  migrations, frontend code.

## Appendix B — Open items requiring human input

1. **Bot token and staff chat ID** — deployment inputs only the client can
   provide (create the bot via @BotFather, add it to the private staff group,
   obtain the group chat ID). Never sent to the development agent.
2. **Staff group membership and privacy/retention policy** — the client must
   own group creation, membership review, and its inclusion in the business
   privacy/retention policy (section 9).
3. **Message layout approval** — the example in section 6 requires explicit
   client sign-off before Phase 2 (acceptance criterion 12). In particular:
   fixed English labels, the `—` placeholder for a missing phone, the
   `[truncated]` notes marker, the extension line rendering the persisted
   choice code, and the `(VIP)` suffix convention.
4. **Queue worker hosting** — where the worker will run (process manager,
   restart policy). Finalized during deployment planning, not in this design
   (section 8).
5. **Retry ladder confirmation** — four executions maximum (initial attempt
   plus three retries) with normal backoff of 60/300/900 seconds, plus the
   `429` `parameters.retry_after` override, is the proposed default; confirm
   or adjust.
6. **Optional Telegram topic (`TELEGRAM_MESSAGE_THREAD_ID`)** — only relevant
   if the staff group uses topics; confirm whether it is needed at launch.
