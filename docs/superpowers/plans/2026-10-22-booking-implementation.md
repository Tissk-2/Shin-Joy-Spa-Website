# Booking Availability, Telegram and Payments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the approved booking flow with reliable room allocation, staff approval from Telegram, optional online payment, and tracked full refunds.

**Architecture:** Four dependent plans share the existing Laravel application, database transactions, a durable outbox, and explicit booking actions. Scheduling establishes the source of truth; Telegram is another authorized interface; financial facts remain separate from appointment status. Integrate and rehearse them before enabling real money.

**Tech Stack:** PHP ^8.3, Laravel ^13.0, Fortify ^1.38, Inertia ^3.0, Vue 3, TypeScript, PHPUnit ^11.5.3, Vitest, Playwright; current database configuration defaults to SQLite, deployed engine unverified.

**Spec:** [Approved booking design](../specs/2026-10-22-booking-availability-payments-design.md). Business and written-design approval, including the manual-refund fallback, was received on 22 October 2026. Plan completed 23 October 2026; filenames retain their original planning date.

## Global Constraints

- Full online payment after staff approval, or payment at the spa.
- Pending requests do not reserve rooms.
- Keep the entire group in one suitable room. No automatic split across rooms.
- 60 minutes after approval, capped at appointment start. Display the exact deadline.
- A definitive payment failure cancels that booking. No automatic conversion to pay-at-spa.
- A browser error or interrupted connection is not proof of payment failure. Reconcile with the provider.
- Full refund of money actually collected for spa cancellations, customer cancellations, late cancellations, and recorded no-shows. No separate owner approval.
- Money received for a cancelled booking is refunded. It never reopens the booking.
- This release has no rescheduling and no cancellation cutoff for refund eligibility.
- Preserve existing snapshots, all unrelated edits, and historical financial uncertainty. No guessed room inventory, price basis, merchant eligibility, or collected money.

## Review Focus

1. Deployment disables new checkout while callbacks/refunds are still arriving: finish recovery without reopening appointments — E1 `test_checkout_disabled_recovery` plus payment D6.
2. Old jobs from before a deploy run after handlers change: keep compatibility aliases and do not lose or duplicate business intent — E1 `test_old_delivery_alias_drains_after_upgrade` plus baseline A4/Telegram C4.
3. A customer is cancelled while email and provider work are delayed: later work cannot send a live appointment promise or refund twice — E1 `test_cancel_during_delivery_and_settlement` plus Telegram C4/payment D4–D5.
4. Account deletion or staff revocation races with an action: retained audit/financial facts and fresh authority remain correct — E1 `test_revoked_actor_cannot_complete_delayed_action` plus scheduling B1/Telegram C3.
5. Existing future appointments overlap or lack verified room/pricing data: readiness fails without mutating historical quotes — scheduling B6, asserted again in E1 `test_unreconciled_legacy_bookings_block_activation`.

## Delivery order and ownership

| Order | Plan | Testable result | Dependency |
| --- | --- | --- | --- |
| A | [Baseline fixes](2026-10-22-booking-baseline.md) | Recovery/session protection, safe content/catalog writes, durable delivery, deterministic test setup. | Current audited checkout. |
| B | [Scheduling and pricing](2026-10-22-booking-scheduling-pricing.md) | Owner/staff authority, verified prices, room inventory, atomic approval, manual booking entry and customer availability. | A's outbox and fixed auth/input boundaries. |
| C | [Telegram actions and customer updates](2026-10-22-booking-telegram-actions.md) | Linked authorized staff can Confirm/Decline/View; customers receive truthful updates with recoverable delivery. | A and B. |
| D | [Payments and refunds](2026-10-22-booking-payments-refunds.md) | Hosted payment, verified events, expiry/reconciliation, full refunds with manual fallback, receipts and financial reporting. | A/B contracts and C's payment-summary interface. |
| E | Integrated acceptance below | Cross-module races, outage recovery, migrations, operating procedures and release evidence. | All applicable stages above. |

The pay-at-spa path can be verified after B/C without enabling online checkout. A production release of that path still requires its readiness checks and customer communications. Stage D remains disabled until its merchant/channel and money-recovery checks pass.

Use one task owner at a time for shared files such as routes, service providers, booking presenters, user/booking models and configuration. Parallelize independent reading/testing or explicitly nonoverlapping work; do not have separate agents concurrently rewrite those shared files. Suggested migration ranges: A `000000`–`000002`, B `010000`–`010008` (including room guards, attestation, terminal maintenance history and operating settings), C `020000` onward, D `030000` onward, prefixed `2026_10_22_` except B3g migration `2026_10_25_010007_add_room_block_terminations.php` and B3h migration `2026_10_26_010008_create_booking_settings.php`.

Recommended execution is a fresh implementing agent and a fresh reviewer per task, consistent with the supplied Agent-First instructions. Finish each task's RED/GREEN cycle and review before dependent tasks start. Follow with one integrated code/security review and release rehearsal. This costs more review work than a single uninterrupted implementation, but the changes connect account security, bookings, and money.

## Decisions fixed by this plan

These are implementation choices within the approved design, stated here so a builder need not guess:

- Use one database schedule guard for the initial single-spa system. Test against the deployed database engine using separate connections/processes; ordinary SQLite memory tests are insufficient evidence.
- Preserve the existing authenticated/verified customer booking page. Accountless phone/WhatsApp/walk-in entries initially use pay-at-spa. A customer wanting online payment uses their verified website account; this release does not add guest checkout or infer account ownership from an email match.
- Keep current half-hour appointment starts and four-guest maximum until verified business data supports changing them. All guests receive the same treatment/extension simultaneously; room capacity alone does not guarantee therapist availability.
- Keep the current password hasher and enforce bcrypt's byte boundary when applicable; align password creation/change/reset rules. Existing login remains compatible.
- Use Midtrans as the provisional hosted-checkout adapter target, behind a provider port and disabled flags. This does not select a paid merchant contract. Channel support, fees, expiry and refund capabilities must be verified for the actual account; the design contains official source links.
- Record one refund obligation per actual collection, with in-flight amounts reserved before execution. Manual fallback is approved; a provider timeout is not proof that it is safe to pay twice.

## Shared contracts and lock order

Read the full signatures in each plan before implementing a consumer. The spelling below is authoritative across the documents:

| Contract | Owner | Consumers |
| --- | --- | --- |
| `App\Support\Outbox\OutboxWriter::record(type, deduplicationKey, payload): OutboxMessage` | A4 | Booking, Telegram, payments and refund actions inside existing transactions. |
| `App\Contracts\OutboxHandler::handle(OutboxMessage): void` | A4 | Each handler has independent delivery state; config maps event type to handler list. |
| `App\Support\ActionContext` | B4 | Dashboard, Telegram, CLI and trusted system audit records. |
| `StaffAccessLock::run(Closure): mixed` in `App\Support\Staff` | B1 | Human-initiated authority-sensitive mutations and account/permission changes. |
| `ScheduleLock::run(Closure): mixed` in `App\Support\Scheduling` | B3 | All room/reservation/settings writers. |
| `BookingMutation::run(int bookingId, Closure): mixed` in `App\Support\Bookings` | B4 | Locks guard/booking and supplies the fresh booking to actions. |
| `BookingStateWriter::transitionLocked(...)` in `App\Support\Bookings` | B4 | Explicit staff actions and trusted financial/expiry workers. |
| `RoomAllocator::{allocateLocked,releaseLocked}(...)` in `App\Support\Scheduling` | B3 | Approval and evidence-based release under the same transaction. |
| `App\Contracts\BookingPaymentSummary::forBooking(Booking): array` | C4 | Website/email presentation; concrete `App\Support\Payments\BookingPaymentSummary` is extended by D6. |
| `App\Contracts\PaymentGateway` | D1 | Payment/refund workers; external I/O occurs outside business locks. |

Global ordering for human mutations: staff-access guard → schedule guard → booking → reservation → session/collection/refund. Trusted system workers start at the schedule guard and never acquire the staff guard later. Inventory-only operations stop at the needed resource. Locks use the same database connection/outer transaction; no external call is allowed under them. Permission revocation, financial manual actions, Telegram actions, and staff booking creation must follow this ordering. Recheck permission inside the guarded action; controller middleware alone is insufficient.

Subscription configuration is a stable type-to-handler-list registry. A message records its intended handler deliveries at creation. When C replaces A's original Telegram delivery, drain/alias already recorded handler keys deliberately; don't backfill all historical events or register two initial-message senders. Payment and communications handlers independently consume cancellations/no-shows, so a notification failure cannot prevent financial recovery.

## Client and deployment inputs

| Input | What can proceed now | What must wait for it |
| --- | --- | --- |
| Actual rooms, capacities, treatment suitability and cleaning times | Schema/actions/UI using clearly synthetic fixtures. | Public availability activation. |
| Per-person/per-booking prices, guest coverage, extension rules | Pricing engine and validation tests. | Verified catalog configuration and new-flow activation. |
| Review hours/target, booking horizon, minimum lead/checkout window | Validated configuration and boundary tests. | Customer availability/deadline promises. |
| Owner identity and staff list | Authorization/linking implementation and fixtures. | Real account grants/linking. |
| Production database and worker/scheduler hosting | Unit/integration development and isolated local tests. | Production-engine concurrency evidence and recovery readiness. |
| Sending domain and mail transport | Fake-delivery tests, retry behavior, templates. | Real delivery verification. |
| Merchant account, channels, actual fees, refund balance/process | Provider port, deterministic fakes, provisional sandbox adapter. | Merchant-specific sandbox evidence and real checkout activation. |

No credentials should be pasted into the plan or Telegram. No paid account creation, live notification, payout, deployment, push, or third-party configuration change is performed by preparing these documents.

## Stage D deferral and stage E split (11 November 2026)

**Stage D (payments and refunds) is deferred** (user decision). Online payments stay off: `config/booking.php` fixes `online_payments_enabled` to `false`, and both booking creation paths store `payment_preference = 'at_spa'`. Stage E therefore covers the pay-at-spa release only. Its payment cases (Review Focus 1, the payment half of Review Focus 3, and the online, late-payment and refund browser journeys) wait for stage D.

**Stage E is split into four parts** (user decision). Each is prepared against the code as it is when that part starts:

| Part | Scope |
|---|---|
| E1a | `tests/Feature/BookingReleaseAcceptanceTest.php`, five tests through the real outbox: a cancellation while notices wait (Review Focus 3, pay-at-spa half); revocation and account deletion while a press waits, including the two-step Decline (Review Focus 4); deliveries recorded during a deploy reaching an older worker; the whole pay-at-spa chain through a Telegram outage and a dead worker; and an unanswered request expiring. Review Focus 2's other direction (older deliveries reaching new code) is covered by `BookingOutboxEndToEndTest`, `BookingMessageSyncTest` and `OutboxWriterTest`; Review Focus 5 by `BookingMigrationReadinessTest`, `RoomAssignmentAuditTest` and `RoomReconciliationApplyTest`. The test names differ from the ones listed under "Review Focus" above. |
| E1b | Customer-facing text: the FAQ's rescheduling promise, `PRODUCT.md`'s scope line, and the booking form's line that the WhatsApp number is used to confirm the booking. Bookings with no recorded payment choice (made before the payment field existed) keep their silence about payment in the email and on the booking page, as stage C decided. |
| E1c | The staff operations guide (`docs/superpowers/plans/2026-10-22-booking-operations.md`), and one browser test for the whole pay-at-spa journey (`tests/e2e/booking-release.spec.ts`), which Qoder writes and Claude runs in the review. `bookings:readiness` only reports, so the guide must make "ready" an explicit step before room scheduling is switched on. `BOOKING_SCHEDULING_ENABLED` and `TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED` are read without a boolean filter, and Laravel converts only words like `true`/`false`, so `off` or `no` stays a string that counts as on. The code also reads the scheduling switch three ways: truthy at approval, cancellation, completion and no-show; `=== true` on the customer booking form, the new-request room check, the staff booking form, the schedule page and `bookings:readiness`; and `(bool)` on the admin rooms page and the admin booking page. So `1` enforces rooms at approval while the customer booking form, the new-request check and readiness say scheduling is off. E1c fixes both switches as C4e fixed `TELEGRAM_ACTIONS_ENABLED`. With scheduling on, a customer's new request for a time that overlaps their own confirmed booking can be refused when no other suitable room is free, so the guide tells staff to cancel the old booking first in that case. |
| E1d | The full regression run (every command below, with measured results) and the stage E close-out. |

## Stage E close-out: the pay-at-spa release (12 November 2026)

Stage E is complete as code for the pay-at-spa release: every booking is paid at the spa. Online payment, refunds and their Review Focus cases wait for stage D, and two of Task E1's steps are still open (items 8 and 9 below). This section records what stage E delivered, what was measured, and what is still open. Every claim rests on a run or on the code as it stands.

**What stage E delivered:**

| Part | Commit | Delivered |
|---|---|---|
| E1a | `e458869` | `BookingReleaseAcceptanceTest`: five pay-at-spa acceptance tests through the real outbox |
| E1b | `fd70cd2` | Customer text: the FAQ no longer promises a reschedule, `PRODUCT.md` states the release's scope, and the booking form no longer says the WhatsApp number confirms the booking |
| E1c | `7e116a3`, `0715c3b` | The scheduling and Telegram notification switches read as real booleans; the browser journey `tests/e2e/booking-release.spec.ts`; the [staff operations guide](2026-10-22-booking-operations.md) and its README pointer. `7e116a3` came from outside the recorded handoffs; it was reviewed in E1c and the user kept it |
| E1d | `09f2e50` | The regression run below and this close-out |
| E1e | the commit recording it here | The browser suite as one run: each spec file starts from the seeded database, no spec uses the Vite dev server, and six specs pick times from the calendar's time buttons that `e664842` introduced (open item 1) |

**The regression run.** On `0715c3b` with a clean tree, 12 November 2026. Qoder ran every command that can run here except #7, one at a time; Claude ran #7. Windows 10, PHP 8.5.3, Node 24.15.0, Laravel 13.40.0, PHPUnit 11.5.56, Vitest 4.1.12, Playwright 1.62.1, SQLite 3.49.2. Telegram and mail are faked, and online payment is off. The live database's hash was the same before and after Qoder's run, around #9, and after each browser run.

| # | Command | Run as | Measured result |
|---|---|---|---|
| 1 | `php artisan test` | the same | 1146 passed (11908 assertions) |
| 2 | `composer test:coverage` | `php artisan test --coverage --min=80` | **not measured**: exit 1, "Code coverage driver not available" (no Xdebug or PCOV) |
| 3 | `npm run test:legacy` | the same | 60 tests: 58 pass, **2 fail** (open item 7) |
| 4 | `npm run test:frontend` | the same | 301 passed in 30 files |
| 5 | `npm run types:check` | the same | clean |
| 6 | `npm run build` | the same | exit 0 |
| 7 | `npm run test:e2e` | as one run; then the 5 room specs that need `E2E_BOOKING_SCHEDULING_ENABLED=true`. The whole suite doesn't run again with it: five fixtures (`booking-settings`, `package-pricing`, `room-inventory`, `room-management`, `staff-permissions`) refuse to run with scheduling on | As one run: 34 passed, **5 failed**, 5 skipped (the room specs that need the opt-in). Each failing test passes when its spec file runs alone (open item 1). With the opt-in: all 5 room specs pass, 3 of them only when run alone; `booking-release` skips itself, as designed. **After E1e** (on `e664842` with the change): as one run, 39 passed, 0 failed, 5 skipped, in 7.9 min; with the opt-in, the 5 room specs pass together |
| 8 | `php vendor/phpunit/phpunit --configuration phpunit.concurrency.xml` | `php vendor/bin/phpunit -c phpunit.concurrency.xml` (the listed path is wrong) | 16 tests, 1 skipped: `test_the_mysql_session_runs_in_utc`, which needs MySQL |
| 9 | `composer verify` | its script: #1, then `route:cache` and `route:clear` against a throwaway database | both succeed; no route cache left behind |
| 10 | `composer audit` | — | **not run**: Composer is not installed |
| 11 | `npm audit` | `npm audit`, and `npm audit --omit=dev` | 11 moderate, 0 high, 0 critical, all in development tooling; production dependencies: 0 |

The logs are `storage/framework/testing/e1d-*`. Qoder's report and Claude's review are `task-E1d-report.md` and `task-E1d-review.md` in `.superpowers/sdd/2026-10-22-booking-scheduling-pricing/`. Both places are git-ignored.

**Open items.** E1e closed item 1. None of the others is done:

1. **Closed by E1e: the browser suite passes as one run.**
   - **The fix:**
     - global setup keeps a copy of the freshly seeded database, and every spec file restores it in its first `beforeAll`;
     - no spec writes the Vite hot file any more, and the unused Vite test server is gone;
     - a source test in `npm run test:legacy` keeps both rules.
   - **Six specs still picked the time from the old list,** which `e664842` had replaced with time buttons, so E1e updated them too.
   - **Measured on `e664842` with the change:** see row #7.
   - **Still not reset:** files outside the database, such as screenshots and uploaded catalogue images.

   What E1d found before the fix, kept as measured: each failing test passed when its spec file ran alone. They failed because all specs in a run shared one database and one pair of servers:
   - `package-pricing` leaves the Nezuko package at 2–4 guests with a new price. Run right after it, three `service-catalog` tests fail, because they expect the seeded one-guest totals;
   - from the first spec that writes the test Vite hot file (`booking-settings`, at its own start) to the end of the run, pages are compiled on demand by the Vite dev server instead of coming from `public/build`. The dev server is slower late in a run. In a run of every spec up to `booking-settings`, single files took up to 21 s (`Profile.vue`) and `booking-settings` ran out of time; alone, it passes in under a minute. `catalog-presentation`'s second test uses built assets when run alone. Right after `booking-settings` it used 1.8 of its 2 minutes on the dev server, and in the full run it ran out of time. Why the dev server slows is not established;
   - the room specs assume a database no other room spec has used. With the opt-in in one run, two room fixtures stop with an error and one test counts another spec's reservation; only `room-approval` and `room-block-termination` pass.

   None of these failures pointed to a product defect, and production serves built assets.
2. **The production database engine.** The concurrency suite has only run on SQLite: room allocation and outbox races are unproven on the engine the spa will deploy, and its MySQL session check is skipped. Stage C's close-out lists what a MySQL run must cover ([Telegram plan](2026-10-22-booking-telegram-actions.md), "Not verified, and required before production"): this suite with `CONCURRENCY_DB_*` pointed at a disposable database, then the MySQL-only checks in `task-C3c-review.md` and the C4b lock checks.
3. **Live delivery.** Telegram and customer email were tested only with fakes. Their switches stay off until each is proven on a test account, following the README's "Before switching actions on" and "Before switching emails on" checklists.
4. **Coverage.** Unmeasured, so the project's 80% requirement is unverified. It needs Xdebug or PCOV.
5. **`composer audit`.** Not run, so the PHP dependencies have had no advisory check. It needs Composer.
6. **11 moderate npm advisories,** all in development tooling: the `typescript-eslint` packages, and `@vue/test-utils` with `glob`, `minimatch`, `js-beautify` and `editorconfig`. None ships to production. The `@vue/test-utils` fix is a downgrade from 2.5.1 to 2.4.11, outside the `^2.5.0` range in `package.json`, so npm marks it as breaking and it needs its own check.
7. **The 2 legacy-suite failures.** Two scope rules in `tests/frontend/translation-parity.test.mjs` reject the "Awaiting payment" / "Menunggu pembayaran" status labels. Stage D decides whether the labels or the rules change.
8. **A deployment rehearsal.** Task E1 asks for one with production-shaped synthetic data, including overlapping legacy appointments, missing pricing verification and a queue or scheduler outage (its disabled-checkout case waits for stage D). The operations guide is checked against the code only.
9. **A final review of the whole release.** Each part had an independent review; no code and security review has covered the release as one (Task E1's last step).
10. **Unused leftovers.** The translation keys `b.sub`, `b.successs`, `b.note` and `booking.historyWarning` are used by nothing, and `src/Data/faq.js` belongs to the legacy `src/` app, which is not built or served. They are harmless now, but they still say WhatsApp confirms the booking and promise a reschedule, so nothing should reuse them.
11. **Stage D:** payments and refunds, Review Focus 1, the payment half of Review Focus 3, and the online, late-payment and refund browser journeys.

**Verdict: not production-ready.** The pay-at-spa release is complete as code. On SQLite with fake delivery, every check this machine can run passes except the legacy suite's 2 failures (item 7, left for stage D). The browser suite passes as one run since E1e (item 1). Before it is deployed:
- the MySQL checks in item 2 pass on the production engine;
- `composer audit` reports no high or critical advisory (item 5);
- coverage is measured and reaches 80% (item 4);
- a deployment rehearsal with production-shaped synthetic data follows the operations guide from backup to "ready" (item 8);
- a final code and security review of the release finds no critical or high issue (item 9).

After deployment, Telegram notifications, Telegram actions and customer emails each stay off until proven on a test account (item 3), and room scheduling stays off until `bookings:readiness` prints `Booking readiness: ready.` (guide §3).

## Task E1: Integrated acceptance, recovery and handover

**Files:** Create `tests/Feature/BookingReleaseAcceptanceTest.php`, `tests/e2e/booking-release.spec.ts`, `docs/superpowers/plans/2026-10-22-booking-operations.md` during implementation. Extend the isolated fixture helpers in `tests/e2e/helpers/`, the scheduling `tests/Concurrency/` harness, `README.md`, `PRODUCT.md`, and `resources/js/data/faq.ts` as needed. The operations document is an execution deliverable, not a claimed existing runbook.

**Interfaces:** Consume the named actions/contracts above. Use fake gateway, Telegram HTTP and Laravel notifications; invoke the real outbox dispatcher/handler and scheduled actions rather than bypassing them through direct model updates. Readiness commands `bookings:readiness` and `payments:readiness` remain read-only and return nonzero for unresolved prerequisites.

- [ ] **RED:** Add the five named Review Focus cases and browser journeys for customer at-spa booking, online approval/payment, staff Telegram decline, late payment/refund, and manual refund record. Pin business facts across crashes:
  ```php
  $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
  $this->assertSame(1, $booking->paymentTransactions()->count());
  $this->assertSame(1, $booking->refundObligations()->count());
  $this->assertSame(265000, $returnedIdr);
  $this->assertSame(0, $duplicateReturns);
  ```
- [ ] Run `php artisan test --filter=BookingReleaseAcceptanceTest` and `npm run test:e2e -- tests/e2e/booking-release.spec.ts`; each new case must fail for a demonstrated missing behavior before its integration fix, rather than fail merely because a browser server is misconfigured.
- [ ] Repair only evidenced integration gaps: commit order/event consumers, handler upgrade compatibility, fresh actor authorization, stale notices, reservation release or refund dedupe. Keep all approved business rules and the shared interfaces aligned. Reproduce worker interruption after external success but before local acknowledgement; recover using persistent references rather than creating a second charge/refund.
- [ ] Run the full regression commands below once changes settle and the actual-engine concurrency harness. Record versions, engine, pass/fail counts, measured coverage and any unresolved failures in the implementation report. Do not reuse the 15 October audit results as proof of these new features.
- [ ] Rehearse deployment with production-shaped synthetic data, including overlapping legacy appointments, missing pricing verification, disabled-new-checkout recovery and queue/scheduler outage. Keep recovery workers/callbacks/refunds active when new checkout is disabled. A schema rollback must not erase financial events; never restore an old database snapshot over newly accepted money.
- [ ] Write the operations runbook: entering outside bookings, therapist check on approval, review alerts, unknown payments at arrival, expiry recovery, no-show attestation, manual refund reservation/evidence, unresolved payout investigation, staff revocation, delivery retry, and restore/rollback procedure. State which screens/commands staff uses and what evidence proves resolution. Remove outdated rescheduling/refund-cutoff/revenue wording.
- [ ] Obtain actual merchant/channel sandbox and real mail/Telegram delivery evidence through authorized test accounts when available. Missing credentials or business facts keep the corresponding feature off; they do not justify inventing an “all checks passed” result. Conduct a final independent code/security review and address critical/high findings before launch is recommended. Stage only inspected task diffs if committing is permitted.

## Regression commands at execution time

Run from the workspace root with the test environment and fake external delivery. These commands are planned, not already executed:

```text
php artisan test
composer test:coverage
npm run test:legacy
npm run test:frontend
npm run types:check
npm run build
npm run test:e2e
php vendor/phpunit/phpunit --configuration phpunit.concurrency.xml
composer verify
composer audit
npm audit
```

The project requires at least 80% coverage. Report measured results and any missing coverage driver honestly. Validate modified PHP with Pint's check mode and modified frontend files with ESLint without `--fix`; do not run the repository's mutating lint/format scripts indiscriminately over unrelated changes. Whole-suite failures must be classified by evidence; never delete tests to get green output.

## Planning self-review

Spec coverage maps to A (audit fixes/outbox), B (rooms/pricing/roles/lifecycle/history), C (Telegram/customer communication), D (money/refunds/reporting), and E (cross-module acceptance/operations). Each plan has named failure cases, explicit files/interfaces, and RED/GREEN verification steps. Shared signatures, namespaces, event names, migration ranges and lock ordering were checked across plans. Provider and client facts remain clearly identified launch inputs.

The plans are complete as a build proposal. On 23 October 2026 the user requested rooms first, one part at a time. B3a room inventory/availability, B3b guarded allocation/pre-start release, B3c staff approval/cancellation integration, and B3d real read-only staff inventory are implemented, tested, and independently reviewed; see the [scheduling plan's execution split](2026-10-22-booking-scheduling-pricing.md#task-b3-rooms-availability-and-one-allocation-boundary). B3c's regression passed 139 PHP tests, 20 frontend tests and one browser scenario. B3d's final scheduling/admin-access/room regression passed 75 PHP tests (455 assertions), 15 room/layout frontend tests, type/style checks and one desktop/mobile browser scenario. Coverage is unmeasured; production-engine concurrency remains unverified. B1a owner authority foundation is now implemented and independently reviewed: explicit verified-account provisioning, shared staff locking, last-owner recovery protections, safe role upgrade and bounded profile deletion attempts. Its focused regression passed 113 PHP tests; the reviewed limiter fix passed 32 covering tests, and the room-approval browser scenario passed. Two unrelated full-suite checks still fail because .env.example is absent. B3e owner-only room creation/editing is now implemented and independently reviewed, with version conflicts, protected reservations, real persistence, EN/ID forms, mobile/browser proof and five two-process race cases. Its final review fix passed24 covering PHP tests/358 assertions; earlier related/frontend/browser results and limits are recorded in the scheduling plan. B1b capability management, customer availability, settings, the expanded lifecycle, and the other unchecked tasks remain unfinished. B3g cancellation/immediate reopening is completed below. Scheduling stays disabled by default; no live migration or activation occurred. Preserve the existing checkout and its unrelated work, and continue one requested part at a time.

B3f maintenance create/edit completed on25 October2026 and passed independent specification/security review. Freshly verified owners or explicitly capable staff can save one-room periods or atomically block all current rooms, with reservation/cleaning protection, stale versions and preserved elapsed occupancy. Full paginated future-period management and EN/ID forms passed backend/frontend, five real process races and desktop/mobile browser checks. Domain/list14tests, related regression68tests and race5tests overlap; frontend12 and final browser1 also passed. See the scheduling plan and task-B3f report for evidence and limits. No live data or activation changed. B3g below adds future cancellation and immediate reopening; capability-management UI remains unfinished. Continue one requested part at a time.
B3g future maintenance cancellation and immediate reopening completed on 26 October 2026, with independent specification and quality/security review passing without actionable findings. Each action requires fresh staff authority, exact version, explicit intent and a separate reason. Immutable terminal history preserves original periods and actor snapshots; all five room readers agree on effective occupancy, while reservations and cleaning remain protected. Changed-consumer regression passed93 PHP tests/880 assertions; final domain and ten process-race checks passed23 tests/326 assertions; counts overlap. Frontend16 tests, one isolated desktop/mobile browser scenario, type and scoped style checks passed. The final details-label change has targeted frontend proof after the browser run. All30 reviewed files were unchanged at completion. See the scheduling plan for schema rollout and the mandatory compatible-reader rollback constraint after terminal use. Coverage and production-engine concurrency remain unverified. No live migration, activation or grants occurred. Customer availability, settings, B1b and the broader lifecycle remain open; stop after this one requested part.

B3h owner booking settings completed on26 October2026 with independent specification and quality/security approval after a JSON object-order regression fix. Owners can explicitly configure operating limits/review hours through an EN/ID page, using guarded versioned singleton persistence. Pure approval/review deadline helpers are available. No business values are seeded and saving settings does not yet enforce customer booking limits or activate scheduling. Final covering PHP22tests385assertions includes five real SQLite process races; parent final fix proof2tests37assertions, earlier shared regression76tests772assertions and frontend190tests/browser1scenario passed. Counts overlap. Type/scoped style checks passed; all26 final hashes match reviewed versions and prior room files remain intact. See scheduling plan and task-B3h report/reviews for evidence. Coverage and production-engine acceptance remain unverified. Customer availability, integration of these settings, B1b and expanded lifecycle remain unfinished. No live migration, configuration, activation, grants or commits; stop after this one part.

B3i authenticated read-only availability API completed on 28 October 2026, with independent specification and quality/security approval after two exact-match collation fixes. GET `/booking/availability` validates current catalog selections, consumes saved horizon/lead settings, batches room occupancy and returns safe private/no-store observations under verified access and per-user/IP throttles. Real SQLite NOCASE regressions cover strict service/room-type matching while preserving the original interval helper. Final covering run passed 42 PHP tests / 566 assertions; parent final fix proof passed 2 tests / 61 assertions. The earlier broad run passed 146 tests / 1,528 assertions before the isolated fixes; counts overlap. Scoped Pint/whitespace passed, all 12 final hashes match reviewed versions and 49 prior source/test paths outside scope remain unchanged. No new migration or frontend changes; scheduling remains disabled by default. No live database, activation, grants, commits or external writes occurred. Coverage and production-engine runtime/concurrency remain unverified. Customer UI and submission/settings integration, B1b and the broader lifecycle/readiness work remain unfinished. See the scheduling plan and task-B3i report/reviews for details. Stop after this one part.

B3j customer booking-page availability integration completed on 29 October 2026 with independent specification and quality/security approval and no findings. Behind strict scheduling gates, customers choose available times and view room observations with localized recovery, explicit refresh and Jakarta times; request identity/cancellation prevents stale responses authorizing review/confirmation. Existing booking payload and default-off flow remain intact. Specific room choice is not persisted and staff approval reserves a suitable room. Full frontend215tests, final focused47tests, PHP62tests/972assertions and final real-API desktop/mobile browser1scenario passed; counts overlap. Types/ESLint/Pint/new-file Prettier passed; four baseline Booking.vue formatter deviations preserved. All10reviewed hashes and61prior paths verified unchanged; screenshots and isolated browser cleanup checked. No activation, migration, live configuration/data, commits or external writes. Submission-time availability/settings enforcement is the next separate part; B1b, lifecycle, readiness, measured coverage and production-engine verification remain outstanding. See scheduling plan and task-B3j report/review. Stop after this one part.

B3k submission-time availability/settings enforcement completed on 30 October 2026 with independent specification and quality/security approval after a localized retry-guidance correction. Under strict scheduling activation, CreateBooking checks current selection/window/room availability after exact replay and before pricing/write. Matching retries, immutable pricing/fingerprints, notifications and default-off legacy behavior are preserved; pending requests create no hold. Related146PHPtests1354assertions, finalcovering16tests144assertions, parentreadiness1test9assertions and real-browser stale rejection/recovery-to-pending proof passed; counts overlap. Scoped style/types passed. All7finalreviewhashes and68protectedprior paths verified; request/controller unchanged, originalhot restored and isolated browser resources cleaned. No activation, live database/configuration, migration, grants, commits or external writes. Submission remains an observation and staff approval allocates authoritatively. B1b, broader lifecycle/staff workflows, measured coverage and production-engine/readiness acceptance remain open. See scheduling plan and task-B3k report/reviews. Stop after this one part.

B1b owner-managed staff booking permissions completed on 1 November 2026 with independent specification and quality/security approval after correcting the action's unlocked return reread. Owners now grant/revoke audited booking and maintenance authority through an EN/ID screen; current verification/role/capability is enforced for existing sessions and direct actions, while verified Admins retain read access and owners retain inherent authority. The additive audit migration preserves the staged capability values without another backfill. Actual role changes clear dormant Admin grants with honest CLI audits; recovery access and narrowly supported pre-audit provisioning remain protected. Final covering PHP66tests/572assertions, full Vue224tests, focused UI28tests and browser3scenarios passed; counts overlap. Four SQLite process orderings passed, with scoped style/types clean. All34finalreviewed hashes and506protectedprior source/test files match; hot/isolated browser cleanup checked. FullPHP recorded744passes/3failures: one obsolete expectation was fixed and retested; two known missing.env.example failures remain. Additional legacyNode46passes/2failures concern unchanged standalone/typography surfaces. Coverage and production-engine acceptance remain unverified. No live migration, activation, grants, configuration changes, commits or external writes occurred. Scheduling stays default-off. Broader pricing/lifecycle/staff workflows and release readiness remain separate. See scheduling plan and task-B1b report plus initial/fix reviews. Stop after this one part.

B2 verified package pricing completed on 2 November 2026. Codex reviewed Zcode's actual diff, fixed unsaved-price attestation, misleading group totals and nullable-contact handling, and completed public pricing-scope copy. Backend and frontend re-review approved with no open findings. Pricing bases, owner attestation/invalidation, integer arithmetic, coverage, immutable snapshots, legacy default-off behavior and exact replay are implemented. Fresh final quote12PHP/92assertions and owner5/97, initial focused47/438 and public/availability/catalog42/866 passed; counts overlap. Final Vue244tests and new owner/customer browser1scenario passed; types/build/scoped style passed. Final41B2hashes and565unaffected review-baseline files were verified, with unchanged live database/.env/HEAD and isolated-test cleanup. FullPHP was not rerun by Codex; Zcode's historical770passes/two known missing.env.example failures remain documented. Coverage and production-engine acceptance are unverified. No live migration, real pricing attestation, activation, commit or external write. Broader lifecycle, staff workflows and release readiness remain separate. See scheduling plan and task-B2-codex-review.md. Stop after B2.
