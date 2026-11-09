# Booking Backend Baseline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Correct the audited security, data-integrity, delivery, and test-isolation defects before adding scheduling or real payments.

**Architecture:** Fix active Fortify and Laravel routes in place. Add explicit session revocation, shared input constraints, transactional content revisions, and an outbox whose per-handler deliveries survive queue outages. Preserve existing booking snapshots and unrelated work.

**Tech Stack:** PHP ^8.3, Laravel ^13.0, Fortify ^1.38, PHPUnit ^11.5.3, Vue 3, Vitest, Playwright.

**Spec:** [Approved design](../specs/2026-10-22-booking-availability-payments-design.md), sections 1, 11, 14–15; [backend audit](../../../outputs/backend-flow-audit-2026-10-15.md); [master plan](2026-10-22-booking-implementation.md).

## Global Constraints

- Fix confirmed backend security/reliability defects before real customers/payments.
- Committed bookings remain successful even when queue delivery is down.
- No provider or notification calls inside business transactions.
- Use immutable inputs/DTOs and explicit updates; never silently rewrite historical prices or customer identity snapshots.
- Automated tests use isolated databases, fake external delivery, and no production credentials.
- Existing working-tree changes are not owned by this task. Commit only inspected task changes when local permissions permit; no push/deploy is implied.

## Review Focus

1. A stolen cookie session survives a password reset with a non-database session driver — A1 `test_reset_rejects_preexisting_cookie_and_file_sessions`.
2. An old reset token targets a replacement account that reused an email — A1 `test_deleted_identity_token_cannot_reset_replacement`.
3. A multibyte password exceeds bcrypt's byte limit despite a short character count — A2 `test_bcrypt_limit_counts_bytes`.
4. Staff submits a sparse content change while another editor saves — A3 `test_sparse_stale_content_save_never_deletes_omitted_values`.
5. One notification handler succeeds while another fails and the queue crashes — A4 `test_handler_recovery_preserves_independent_completion`.

## File and interface map

Migrations `2026_10_22_000000`–`000002` belong here. Scheduling owns `010000` onward, Telegram `020000`, and payments `030000`. Planned new files below are not claims that these features already exist.

- `App\Support\Outbox\OutboxWriter::record(string $type, string $deduplicationKey, array $payload): App\Models\OutboxMessage` requires an existing database transaction; same key/type/payload returns the original row, conflicting content raises `LogicException`.
- `App\Support\Outbox\OutboxDispatcher::dispatchBatch(int $limit = 100): int` queues eligible delivery IDs under recoverable leases and returns accepted job count; it never marks external delivery complete.
- `App\Contracts\OutboxHandler::handle(OutboxMessage $message): void` returns only for completed or idempotently skipped work. Typed `OutboxRetryableException`/`OutboxPermanentException` classify sanitized failures.
- `config/outbox.php` maps each event type to a list of stable handler keys/classes. Each `(message_id, handler_key)` has an independent durable delivery row and lease. Adding a subscriber does not silently replay the whole historical outbox.
- `booking.created` payload is `{booking_id:int, booking_version:1}`. The later scheduling writer increments versions for lifecycle events. Dedupe is by business event, not queue job UUID.
- `App\Support\TelegramBookingSender::send(Booking $booking): int` returns Telegram message ID and throws on delivery failure. Do not call the existing queued job's `handle()` synchronously and interpret its return as successful delivery.

## Task A1: Session revocation and password-broker identity cleanup

**Files:** Create `database/migrations/2026_10_22_000000_add_auth_version_to_users.php`, `app/Http/Middleware/EnsureCurrentAuthVersion.php`, `app/Listeners/StampAuthenticatedSession.php`, `app/Support/Auth/PasswordBrokerIdentity.php`. Modify `app/Models/User.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Http/Controllers/Settings/{PasswordController,ProfileController}.php`, `app/Providers/{AppServiceProvider,FortifyServiceProvider}.php`, `bootstrap/app.php`, `routes/settings.php`. Tests: create `tests/Feature/Auth/SessionRevocationTest.php`; extend `tests/Feature/Auth/PasswordResetTest.php`, `tests/Feature/Settings/{ProfileUpdateTest,PasswordUpdateTest}.php`, `tests/e2e/auth.spec.ts`.

**Interfaces:** User `auth_version` unsigned integer default 1. `EnsureCurrentAuthVersion::handle(Request $request, Closure $next): Response`; `StampAuthenticatedSession::handle(Login $event): void`; `PasswordBrokerIdentity::forget(string $email): void` deletes the configured broker token using that identity, without guessing a hardcoded token table. Middleware compares a fresh user version with the version stamped when credentials were authenticated.

- [ ] **RED:** Establish two independent authenticated sessions, reset a password, then exercise both customer and admin routes. Add deletion/recreation, old-email token cleanup, password-change other-session revocation, remembered-login, and reset/login-race tests:
  ```php
  $this->assertGuest(); // Request using the old cookie after recovery.
  $this->assertSame($beforeVersion + 1, $user->fresh()->auth_version);
  $this->assertFalse(Password::broker()->tokenExists($replacementUser, $oldToken));
  $this->assertSame($newEmail, $user->fresh()->email);
  ```
- [ ] Run `php artisan test --filter="SessionRevocationTest|PasswordResetTest|ProfileUpdateTest|PasswordUpdateTest"`; expect the new revocation/token-reuse assertions to fail against the audited behavior.
- [ ] Implement password/hash/remember-token/version changes atomically. Stamp the credential-verified user object's version at login, not a later reload that could bless a password validated just before a concurrent reset. Append session-version validation to the web stack after session initialization; anonymous/webhook requests pass through. Missing legacy stamps require reauthentication. Reset invalidates all old sessions; an authenticated password change stamps the newly committed version only into its requesting session, invalidating others. Rotate remember tokens and clear relevant cached password-confirmation state.
- [ ] Wrap email change/deletion with broker-token cleanup in the same identity transaction. Invalidate old-email tokens before changing identity; preserve existing final-admin protection, booking history and neutral forgot-password responses. Scheduling B1 will extend the final-admin logic to owners. Do not edit dormant auth controllers or assume deleting database session rows covers file/cookie sessions.
- [ ] Run the same tests and focused browser multi-session test; expect PASS. Explicitly stamp legitimate test logins through a test helper instead of weakening middleware to trust any missing stamp. Review account replacement, race handling and token secrecy; conditionally commit `fix: revoke recovered sessions and invalidate stale reset tokens`.

**A1 complete (4 November 2026, uncommitted):** Laravel's native `AuthenticateSession` is now in the `web` group, so a password reset or a change made from another session revokes old sessions and remember-me cookies, while the changing session stays signed in. An email change or account deletion deletes the old address's reset token inside the locked transaction. This uses native password-hash stamping instead of the custom `auth_version` described above; a session with no stamp is stamped on first use. Independently security-reviewed; full PHP suite 888 passed. Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A1-review.md`.

## Task A2: Input types and one password policy

**Files:** Create `app/Rules/PasswordByteLimit.php` and `app/Support/Auth/PasswordRules.php`. Modify `app/Actions/Fortify/{CreateNewUser,PasswordValidationRules,ResetUserPassword}.php`, `app/Http/Controllers/Settings/PasswordController.php`, `app/Http/Requests/Settings/ProfileUpdateRequest.php`, `app/Providers/{FortifyServiceProvider,AppServiceProvider}.php`, `routes/settings.php`, `lang/{en,id}/validation.php`, and relevant auth/settings password hint text. Tests: extend `tests/Feature/Auth/{RegistrationTest,PasswordResetTest,PasswordConfirmationTest}.php`, `tests/Feature/Settings/PasswordUpdateTest.php`; create `tests/Unit/PasswordByteLimitTest.php`.

**Interfaces:** `PasswordRules::creation(): array` supplies required/string/minimum 8/maximum 255/letter/digit/confirmed plus a hasher-aware byte rule. `PasswordByteLimit::validate(string $attribute, mixed $value, Closure $fail): void` rejects passwords over 72 bytes only when the configured hasher is bcrypt. Existing accepted passwords remain usable for login; do not introduce a new-login restriction that locks out existing accounts.

- [ ] **RED:** Add malformed-array registration/profile cases, bcrypt byte boundary and identical-suffix regressions, consistent reset/settings rules, and brute-force throttling assertions:
  ```php
  $this->post('/register', ['name' => [], 'email' => [], 'phone' => []])->assertSessionHasErrors(['name', 'email', 'phone']);
  $this->assertSame(72, strlen(str_repeat('a', 71).'1'));
  $this->assertGreaterThan(72, strlen(str_repeat('é', 36).'1'));
  $this->put('/settings/password', $weakPassword)->assertSessionHasErrors('password');
  ```
- [ ] Run `php artisan test --filter="RegistrationTest|PasswordResetTest|PasswordUpdateTest|PasswordByteLimitTest|PasswordConfirmationTest"`; expect the new shape/byte/policy cases to fail.
- [ ] Validate scalar types before normalization/casts; arrays produce normal field errors, not 500s. Centralize creation/change/reset password rules; preserve the existing intended redirect and email normalization behavior. Keep the current hasher instead of silently migrating hashes. Use `strlen` on validated UTF-8 strings for bcrypt's byte limit; translate the user-facing reason. Add named throttling to current-password checks/settings writes: five attempts per minute per authenticated user/IP plus an IP ceiling; record numeric keys rather than logging passwords.
- [ ] Run the same tests; expect PASS, including ID/EN error display. Review active Fortify registration/authentication paths and the service-provider limiter ordering. Conditionally commit `fix: validate account inputs and unify password rules`.

**A2 complete (4 November 2026, uncommitted):** one password policy (the `PasswordValidationRules` trait) now covers registration, reset and the settings change. New passwords over bcrypt's 72-byte limit are rejected, while login is unchanged. `PUT settings/password` is throttled. Array input on registration, login and password reset returns field errors instead of 500s, through `RejectArrayIdentityInput` and type-safe limiter keys. Independently security-reviewed. Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A2-review.md`.

## Task A3: Atomic content edits and bookable catalog bounds

**Files:** Create `database/migrations/2026_10_22_000001_add_content_revision.php`, `app/Actions/Content/SavePageContent.php`, `app/Http/Requests/Admin/SavePageContentRequest.php`, `app/Support/BookingSelectionLimits.php`. Modify `app/Http/Controllers/Admin/AdminContentController.php`, `resources/js/pages/admin/Content.vue`, `app/Http/Requests/Admin/UpdateCatalogPackageRequest.php`, `app/Actions/Catalog/{SaveCatalogPackage,ChangePackageLifecycle}.php`, `app/Http/Requests/StoreBookingRequest.php`, `routes/admin.php`, `lang/{en,id}/admin.php`. Tests: extend `tests/Feature/Admin/{AdminContentTest,AdminCatalogPackageTest,AdminCatalogLifecycleTest,AdminCatalogThrottleTest}.php`; add `tests/frontend/adminContent.test.ts`.

**Interfaces:** `SavePageContent::__invoke(User $actor, int $expectedRevision, array $changes): int` returns the committed revision. A change is `{key, id?:?string, en?:?string}` with allowlisted key and at least one locale; omitted locales/keys are unchanged, explicit empty string/null clears that override. `reset(User $actor, int $expectedRevision): int` is the only clear-all operation. `BookingSelectionLimits::CODE_LENGTH = 80`, `BASE_DURATION_MINUTES = 480`; extension/total duration validation must remain compatible with the existing 09:00–21:00 service window.

- [ ] **RED:** Add empty-request rejection, sparse update preservation, stale revision rejection, whole-transaction rollback, literal string `0`, and publish-time invalid-code/duration tests:
  ```php
  $this->assertSame('0', PageContent::where('key', 'hero.eyebrow')->value('value_id'));
  $this->assertSame($untouched, PageContent::where('key', 'about.body')->value('value_en'));
  $this->assertSame($previousRevision, $revisionAfterRejectedSave);
  $this->assertNull($draftPackage->fresh()->published_at);
  ```
- [ ] Run `php artisan test --filter="AdminContentTest|AdminCatalogPackageTest|AdminCatalogLifecycleTest|AdminCatalogThrottleTest"` and `npm run test:frontend -- tests/frontend/adminContent.test.ts`; expect new revision/omission/publish compatibility assertions to fail.
- [ ] Implement a singleton revision guard and transaction for edits/reset. Require nonempty explicit changes and expected revision; stale requests return field-level conflict feedback without overwriting current data. Frontend sends only edited fields and offers intentional reset. Preserve string `0` by comparing with empty string instead of truthiness. Apply current admin authorization and a content-write throttle to store and reset.
- [ ] Use shared identifier/duration limits at catalog write and publication boundaries. Existing invalid drafts can be repaired; invalid published choices are reported for correction rather than silently truncated. Check publish compatibility for variant and extension codes/modes/durations; don't widen booking validation just to accept an unusable catalog entry. Preserve transaction/version behavior already present in catalog actions.
- [ ] Run the same tests; expect PASS. Refactor the controller into thin request/action handling, then review lost-update/partial-write behavior. Conditionally commit `fix: preserve content edits and enforce bookable catalog choices`.

**A3a complete (4 November 2026, uncommitted; content half of A3):** homepage content saves are now sparse, so omitted keys and locales are untouched, and they keep the string "0". Each save or reset runs in one transaction behind a compare-and-set revision (`content_revisions`), so stale saves are rejected without writing. Save is throttled. The editor posts only the changed fields, keeps the admin's typing after a rejected save, and shows stale-reset errors. Independently reviewed; PHP 919 and Vitest 250 passed. Deploy needs `php artisan migrate`. Still open: **A3b**, the catalog code and duration limits. Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A3a-review.md`.

**A3b complete; A3 and baseline stage A complete (4 November 2026, uncommitted):** the catalog now enforces the booking form's limits through `BookingSelectionLimits`: codes and slugs at most 80 characters, a variant at most 480 minutes, and a same-mode variant plus extension at most 720 minutes (09:00–21:00). On save, the length check applies only to new or changed identifiers. Publish, restore and saving a live package check active choices only, because inactive published choices can be neither edited nor deleted, so limiting them would block restore permanently. Over-long active identifiers are reported, never truncated. Independently reviewed; full PHP suite 936 passed. Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A3b-review.md`.

## Task A4: Durable outbox with independent handler recovery

**Files:** Create `database/migrations/2026_10_22_000002_create_outbox.php`, `app/Models/{OutboxMessage,OutboxDelivery}.php`, `app/Contracts/OutboxHandler.php`, `app/Support/Outbox/{OutboxWriter,OutboxDispatcher}.php`, `app/Exceptions/Outbox/{OutboxRetryableException,OutboxPermanentException}.php`, `app/Jobs/DeliverOutboxMessage.php`, `app/Console/Commands/DispatchOutbox.php`, `app/Support/TelegramBookingSender.php`, `app/Support/Outbox/Handlers/TelegramBookingNotificationHandler.php`, `config/outbox.php`. Modify `app/Actions/CreateBooking.php`, `app/Jobs/SendTelegramBookingNotification.php`, `routes/console.php`, `app/Providers/AppServiceProvider.php`. Tests: create `tests/Feature/Outbox/{OutboxWriterTest,OutboxRecoveryTest}.php`; extend `tests/Feature/{TelegramBookingDispatchTest,Jobs/SendTelegramBookingNotificationTest}.php`.

**Data:** Message fields `type`, unique `deduplication_key`, immutable payload/schema version, created time. Snapshot the event's handler subscriptions into unique `(message_id,handler_key)` deliveries with state, attempts, `available_at`, lease token/expiry, completed/skipped time and sanitized error. States `pending|leased|retry|completed|skipped|failed`; leases are compare-and-set claimed and recoverable after process death. Stable handler aliases remain routable across deployments until their old deliveries drain.

- [ ] **RED:** Test transaction rollback, same-key replay, mismatched payload rejection, queue push failure after booking commit, independent handler recovery, stale lease fencing and permanent failure visibility:
  ```php
  $this->assertDatabaseCount('bookings', 1);
  $this->assertDatabaseCount('outbox_messages', 1);
  $this->assertSame('completed', $telegramDelivery->fresh()->state);
  $this->assertSame('retry', $emailDelivery->fresh()->state);
  $this->assertSame($originalId, $writerReplay->id);
  ```
- [ ] Run `php artisan test --filter="OutboxWriterTest|OutboxRecoveryTest|TelegramBookingDispatchTest|SendTelegramBookingNotificationTest"`; expect missing persistence and failed-queue recovery cases to fail.
- [ ] Implement the fixed contracts above. `record` rejects use outside a transaction and materializes known handler deliveries inside it. Canonicalize payloads without mutating input arrays. Unknown event types/handler aliases produce visible configuration failures, never silent success. `dispatchBatch` claims bounded work then pushes jobs outside transactions; a failed push restores retry eligibility or lets the lease expire. `DeliverOutboxMessage::__construct(int $deliveryId, string $leaseToken)` and `handle(): void` fence stale workers, invoke only that delivery's handler, and record completion after successful work; retryable exceptions use bounded backoff, permanent/exhausted failures stay visible.
- [ ] Replace direct dispatch in `CreateBooking` with `booking.created` outbox persistence after insertion, keeping the current enabled flag for initial Telegram subscription. A matching request replay returns the same booking and intent. Extract the existing client/formatter delivery into `TelegramBookingSender`; its return is a message ID and failures must throw. Adapt already queued legacy jobs to record the same deduplicated intent rather than directly sending through a second path. Do not claim exactly-once remote delivery when an acknowledgement is lost; record uncertainty, retry safely, and keep later actions idempotent.
- [ ] Schedule `outbox:dispatch {--limit=100}` once per minute and make it drain eligible deliveries through the dispatcher. The Telegram plan replaces the initial handler with durable communication fanout, maintaining stable compatibility aliases for queued records. Each fanout's child intent is committed before the parent completes. Add recovery for expired leases even if no new booking arrives.
- [ ] Run the same tests; expect PASS. Assert no external requests during business transactions, queue acceptance is not completion, one subscriber failure never blocks another, and only the current lease owner can update delivery state. Review sanitized logs and process-crash recovery; conditionally commit `fix: make booking notifications durable across queue outages`.

**A4a complete (3 November 2026, uncommitted; persistence only):** Migration, models, `OutboxHandler` contract, `OutboxWriter::record` and `config/outbox.php` are implemented and independently reviewed after Codex fixes: bounded canonicalization, list/map shape preservation, collation-independent SHA-256 identities, and case-exact fixture paths. Focused outbox suite: 27 tests / 283 assertions, SQLite only. The remaining A4 checkboxes stay open (dispatcher, delivery job, scheduling/recovery, Telegram extraction, `CreateBooking` integration). Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A4a-codex-review.md`.

**A4b complete (3 November 2026, uncommitted; delivery execution only):** `OutboxDispatcher::dispatchBatch` (compare-and-set claims, push after commit, rejected inside a caller transaction, expired-lease recovery and exhaustion), `DeliverOutboxMessage` (token fencing, attempts counted at job start, bounded backoff, sanitized errors) and the typed exceptions are implemented and independently reviewed. Outbox suite: 63 tests / 803 assertions, SQLite only. Still open: A4c (`outbox:dispatch` command and schedule) and A4d (Telegram handler, `CreateBooking` integration, legacy job). Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A4b-codex-review.md`.

**A4c complete (3 November 2026, uncommitted):** `outbox:dispatch {--limit=100}` runs one bounded dispatcher batch and is scheduled `everyMinute()->withoutOverlapping(5)` in `routes/console.php`, so expired leases recover without new bookings. Independently reviewed; outbox suite 72 tests / 877 assertions, SQLite only; no real cron or worker run. Still open: A4d (Telegram sender/handler, `CreateBooking` integration, legacy job). Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A4c-codex-review.md`.

**A4d complete; A4 code complete (3 November 2026, uncommitted):** with the existing flag on, `CreateBooking` records a deduplicated `booking.created` intent inside its transaction. `TelegramBookingNotificationHandler` and `TelegramBookingSender` deliver it through the outbox dispatcher. Legacy queued `SendTelegramBookingNotification` jobs convert into the same intent. Independently reviewed. Full PHP suite: 858 passed, 2 known `.env.example` failures. Before enabling the flag in production: run the outbox migration and run both `schedule:run` cron and a queue worker. Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A4d-codex-review.md`.

## Task A5: Safe environment template and deterministic verification

**Files:** Restore `.env.example` from inspected configuration requirements with placeholders only; modify `tests/TestCase.php`, `tests/e2e/{global-setup,global-teardown,testing-environment}.ts`, `tests/e2e/server-process.mjs`, `playwright.config.ts`, `vite.config.ts`, `app/Providers/AppServiceProvider.php`, `README.md`; extend `tests/Feature/{TestEnvironmentIsolationTest,SecurityConfigurationTest,TelegramConfigurationTest}.php` and add `tests/frontend/e2eRuntimeIsolation.test.mjs`.

**Interfaces:** Test environment remains SQLite memory for ordinary PHP tests and a dedicated disposable SQLite file for browser tests; real-engine concurrency uses the separate scheduling harness. External credentials are cleared and mail/Telegram/payment calls faked. Use a distinct E2E Vite hot-file path, supplied consistently to Laravel and the Vite plugin; test setup never deletes the normal developer hot file.

- [ ] **RED:** Pin absent-example, secret-free defaults, isolated database/session/cache/mail/queue, and developer-hot-file preservation:
  ```php
  $this->assertFileExists(base_path('.env.example'));
  $this->assertSame(':memory:', config('database.connections.sqlite.database'));
  $this->assertSame('array', config('mail.default'));
  $this->assertFalse(config('services.telegram.booking_notifications_enabled'));
  ```
  ```js
  assert.equal(readFileSync(developerHotFile, 'utf8'), originalHotContents);
  assert.notEqual(e2eHotFile, developerHotFile);
  ```
- [ ] Run `php artisan test --filter="TestEnvironmentIsolationTest|SecurityConfigurationTest|TelegramConfigurationTest"` and `node --test tests/frontend/e2eRuntimeIsolation.test.mjs`; expect template/asset isolation failures until repaired.
- [ ] Restore documented safe environment keys without reading/copying secret values from `.env`. Set delivery/payment flags false in the example. Make browser assets deterministic using the dedicated hot file and clean up only verified test-owned paths. Add restart/teardown assertions. Update README's queue/scheduler and fake-delivery instructions, preserving unrelated documentation edits.
- [ ] Run focused checks, `php artisan test`, `npm run test:legacy`, `npm run test:frontend`, `npm run types:check`, `npm run build`, and the auth/booking browser regressions. Expect all scoped regressions PASS; investigate baseline failures rather than deleting tests. Run `composer test:coverage` with an installed coverage driver and report measured coverage against the 80% requirement. If the driver is absent, report coverage unmeasured and document the prerequisite instead of claiming success.
- [ ] Review generated artifacts/working-tree diff and run `composer audit` plus `npm audit` before any commit; no automatic dependency upgrades. Conditionally commit `test: isolate booking verification and restore setup defaults`.

**A5a complete (3 November 2026, uncommitted; environment template only):** `.env.example` restored from the committed stock template plus a project section (scheduling and Telegram flags `false`, credentials empty), pinned by `EnvironmentTemplateTest`. Independently reviewed. Full PHP suite: 867 passed, 0 failed. Still open: A5b (E2E/Vite hot-file isolation) and A5c (README operations docs, coverage, audits). Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A5a-codex-review.md`.

**A5b complete (3 November 2026, uncommitted):** browser tests use the dedicated hot file `storage/framework/testing/playwright-vite.hot` through `LARAVEL_VITE_HOT_FILE`, on both the Vite plugin and Laravel sides (testing-only). E2E setup and teardown can delete only test-owned paths, so the developer's `public/hot` is never touched. Independently reviewed. Full PHP suite: 869 passed. Follow-up: E2E serves `public/build` because Playwright starts its web servers before global setup. Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A5b-review.md`.

**A5c complete; A5 complete (4 November 2026, uncommitted):** README updated for outbox-based Telegram delivery (fact-checked against the code), testing notes and commands. Coverage is unmeasured because no driver is installed. Audits were recorded only: 9 composer advisories, including a critical `laravel/framework` TokenGuard bypass that is not reachable with the session-only guard, and 29 npm advisories, all in dev, build or SSR packages. Dependency upgrades are a separate task. Evidence: `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/task-A5c-review.md`.

## Self-review and handoff

Audit coverage: session and token defects A1; malformed registration/password mismatch/truncation/throttles A2; sparse/concurrent content saves, literal zero and catalog bounds A3; enqueue-after-commit failure A4; missing environment template and stale browser assets A5. All Review Focus cases have owning regressions. New booking/payment behavior consumes these boundaries rather than duplicating them.

This document is a plan, completed on 23 October 2026 from the 22 October approved design. No product code or test execution is represented by these unchecked steps. Before committing during execution, inspect the task's diff; never stage the whole dirty checkout.
