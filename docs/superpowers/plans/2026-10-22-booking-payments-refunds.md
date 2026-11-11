# Booking Payments and Refunds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add trustworthy hosted online payment, full-refund execution, on-site receipts, and financial reporting to approved bookings.

**Architecture:** Persist financial facts independently from booking state. A provider adapter performs external calls outside transactions; a durable inbox and the shared booking mutation boundary reconcile results into the ledger, reservations, audit history, and outbox. Start with a disabled provisional Midtrans adapter and enable only merchant-verified channels.

**Tech Stack:** PHP ^8.3, Laravel ^13.0, PHPUnit ^11.5.3, Vue 3, TypeScript, Vitest, Playwright, Laravel HTTP client; customer payment credentials stay on hosted checkout and merchant API credentials stay server-side.

**Spec:** [Approved design](../specs/2026-10-22-booking-availability-payments-design.md), sections 2, 6–9, 11–16. Read the [master plan](2026-10-22-booking-implementation.md), [baseline plan](2026-10-22-booking-baseline.md), and [scheduling contracts](2026-10-22-booking-scheduling-pricing.md) first.

## Global Constraints

- Full online payment after staff approval, or payment at the spa.
- 60 minutes after approval, capped at appointment start. Display the exact deadline.
- A definitive payment failure cancels that booking. No automatic conversion to pay-at-spa.
- A browser error or interrupted connection is not proof of payment failure. Reconcile with the provider.
- Full refund of money actually collected for spa cancellations, customer cancellations, late cancellations, and recorded no-shows. No separate owner approval.
- Money received for a cancelled booking is refunded. It never reopens the booking.
- This release has no rescheduling and no cancellation cutoff for refund eligibility.
- Use integer IDR and UTC instants; display Asia/Jakarta. Preserve historical quotes and never infer payment from booking status.
- No provider calls while holding the schedule guard; no secrets, destination account details, or checkout tokens in Telegram/logs.
- New checkout is disabled by default. Disabling it must preserve existing callback, reconciliation, and refund processing.
- Accountless staff-entered bookings use pay-at-spa; website bookings retain both payment choices.

## Review Focus

1. Checkout creation succeeds but its response is lost: reconcile the stable merchant reference before any retry — D2 `test_lost_create_response_never_creates_second_session`.
2. A correctly signed callback has altered fields outside the signature: retrieve provider status and never apply unverified money facts — D3 `test_signed_notification_cannot_override_retrieved_status`.
3. Payment succeeds before the deadline but its callback arrives afterward: use verified collection time and current locked booking state — D4 `test_timely_collection_with_late_callback_confirms_only_live_booking`.
4. Staff tries a manual refund while an automated refund is uncertain: preserve the in-flight amount and reject a second payout — D5 `test_manual_refund_blocked_until_automated_outcome_resolved`.
5. Online checkout is disabled during an incident: existing money/refunds remain recoverable and pay-at-spa remains available — D6 `test_disabling_checkout_preserves_financial_recovery`.

## File and interface map

Migrations `2026_10_22_030000` onward belong to this plan. New files below are proposed; existing controller/page/presenter paths follow the current application. Keep changes confined to payment responsibilities in shared files.

Consumed contracts, implemented by prerequisites:

- `App\Support\Outbox\OutboxWriter::record(string $type, string $deduplicationKey, array $payload): OutboxMessage` inside the business transaction.
- `App\Contracts\OutboxHandler::handle(OutboxMessage $message): void`; `config/outbox.php` maps a type to a list of handlers. Each handler has its own persisted delivery/lease state.
- `App\Support\Bookings\BookingMutation::run(int $bookingId, Closure $operation): mixed` invokes the closure with a locked `Booking` under `ScheduleLock::run(Closure $operation): mixed`.
- `BookingStateWriter::transitionLocked(Booking $booking, BookingStatus $next, string $reason, ?User $actor, ActionContext $context): Booking` in `App\Support\Bookings`; trusted financial workers pass null actor and system context.
- `App\Support\Scheduling\RoomAllocator::releaseLocked(Booking $booking, string $reason, CarbonImmutable $releasedAt): void` requires guarded transaction and caller-established release eligibility.
- `App\Support\ActionContext` readonly constructor `(string $channel, ?string $ipAddress = null, ?string $userAgent = null, ?string $externalActorId = null)`.
- Cancellation/no-show actions persist `refund_eligible_at` and `refund_reason`. Do not duplicate their business policy or write booking status directly.

Extend the communication plan's existing `App\Support\Payments\BookingPaymentSummary`, implementing `App\Contracts\BookingPaymentSummary::forBooking(Booking $booking): array{state:string,deadline:?string,checkout_url:?string,refunds:array}`.
Consumers inject the contract.
Replace its conservative pre-ledger projection without introducing another binding.

> **C4c note (10 November 2026):** C4c built only the concrete `App\Support\Payments\BookingPaymentSummary`, with this exact array shape. It created no interface and no container binding: with one implementation the container resolves the concrete class itself, and its one consumer, `BookingStatusNotification`, calls `app(BookingPaymentSummary::class)`. Introduce `App\Contracts\BookingPaymentSummary` here only if a second implementation appears; otherwise extend the concrete class.
Its URL is allowlisted, HTTPS, and present only while checkout is eligible.
Its customer refund entries expose amount/state/update time, never payout destination or internal evidence.

Financial events: `payment.session.ready`, `payment.succeeded`, `payment.attention_required`, `refund.updated`. Each payload includes `booking_id`, `booking_version`, its entity ID, and `entity_version`; dedupe by type/entity/version. `booking.confirmed` is the customer paid-confirmation trigger; `payment.succeeded` must not send a duplicate email. Communications and financial handlers subscribe independently to the same events.

## Task D1: Financial ledger and provider boundary

**Files:**

- Create `database/migrations/2026_10_22_030000_create_booking_payment_ledger.php`.
- Create `app/Models/{PaymentSession,PaymentTransaction,RefundObligation,RefundAttempt,ProviderEvent}.php` and matching factories in `database/factories/`.
- Create `app/Contracts/PaymentGateway.php`.
- Create `app/Data/Payments/{CheckoutRequest,CheckoutResult,PaymentSnapshot,RefundRequest,RefundSnapshot}.php`.
- Create `app/Enums/{PaymentState,RefundState}.php` and `config/payments.php`.
- Modify `app/Models/Booking.php` and `app/Providers/AppServiceProvider.php`.
- Test `tests/Feature/Payments/PaymentLedgerTest.php` and `tests/Support/FakePaymentGateway.php`.

**Interfaces:** `PaymentGateway::{createCheckout(CheckoutRequest $request): CheckoutResult, retrieve(string $merchantReference): PaymentSnapshot, expire(string $merchantReference): PaymentSnapshot, refund(RefundRequest $request): RefundSnapshot, retrieveRefund(string $merchantReference, string $refundReference): RefundSnapshot, verifyNotification(array $payload): bool}`. These methods return normalized evidence or throw typed transport/protocol exceptions; they never change application models.

Readonly DTO constructor fields:

- `CheckoutRequest(string $merchantReference, int $amountIdr, CarbonImmutable $approvedAt, CarbonImmutable $deadline, array $allowedChannels, string $returnUrl)`; no client-selected price or arbitrary return URL.
- `CheckoutResult(string $merchantReference, string $checkoutUrl, CarbonImmutable $expiresAt)`.
- `PaymentSnapshot(string $merchantReference, PaymentState $state, array $collections, bool $definitivelyClosedUnpaid, CarbonImmutable $observedAt)`; collections have shape `list<array{transaction_reference:string,amount_idr:int,currency:string,collected_at:?CarbonImmutable,channel:string}>`. Unknown collection time prevents automatic confirmation.
- `RefundRequest(string $merchantReference, string $transactionReference, string $refundReference, int $amountIdr)`.
- `RefundSnapshot(string $refundReference, RefundState $state, int $returnedIdr, ?string $providerReference, CarbonImmutable $observedAt)`; returned amount is cumulative for this refund reference, not an increment to apply again on replay.

**Schema decisions:** `payment_sessions`: unique booking ID, unique merchant reference, provider, expected amount/currency, approval/deadline, normalized state, version, nullable encrypted checkout URL, next reconciliation time, attempt lease, last sanitized error.
One logical session survives creation retries. `payment_transactions`: booking ID, nullable session ID, source `gateway|on_site`, provider/reference, unique `(provider, provider_transaction_id)` for gateway collections, unique receipt submission key for on-site collections, collected amount/currency/time, channel/method, actor and private receipt reference.
Never overwrite collection amount to force a quote match.

`refund_obligations`: unique transaction ID, full eligible amount, reason, state/version. `refund_attempts`: obligation ID, unique request key, method `provider|manual`, amount, state, provider reference, actor, private evidence reference, requested/confirmed times, lease. `provider_events`: provider, unique event digest, trusted reference or quarantine reference, minimized payload, processing state/lease, observed time and sanitized error.
Use restrictive financial foreign keys; user IDs may become null but ledger/audit history must survive account deletion.

- [ ] **RED:** Add `test_unique_collection_and_obligation_survive_replay`, `test_account_deletion_keeps_financial_history`, and `test_legacy_booking_does_not_imply_collection`:
  ```php
  $this->assertDatabaseCount('payment_sessions', 1);
  $this->assertDatabaseCount('payment_transactions', 1);
  $this->assertDatabaseCount('refund_obligations', 1);
  $this->assertSame(0, $legacy->paymentTransactions()->count());
  $this->assertSame(265000, $transaction->fresh()->amount_idr);
  ```
- [ ] Run `php artisan test --filter=PaymentLedgerTest`; expect missing tables/relations to fail.
- [ ] Implement schema, casts, readonly DTOs, gateway interface and deterministic fake. Normalize payment values `not_started|creating|pending|succeeded|failed|expired|cancelled|unknown`; refund values `required|queued|processing|action_required|succeeded|failed`. Gateway success must represent collected funds, not authorization alone. Enforce positive bounded integer amounts and database uniqueness; do not use floats or cascade financial rows through customers.
- [ ] Run the same test; expect PASS. Add duplicate-key coverage against the actual database constraints, not just mocked repository calls.
- [ ] Review ledger retention, normalized types and immutable facts; if commits are permitted, stage only this task's reviewed changes and commit `feat: add booking payment ledger and provider contract`.

## Task D2: Durable hosted checkout creation

**Files:**

- Create `app/Payments/MidtransGateway.php`.
- Create `app/Exceptions/Payments/{GatewayTransportException,GatewayProtocolException}.php`.
- Create `app/Handlers/Outbox/CreateBookingCheckout.php` and `app/Actions/Payments/CreatePaymentSession.php`.
- Create `app/Support/Payments/CheckoutUrlPolicy.php`.
- Modify `config/{payments,outbox}.php`, `app/Providers/AppServiceProvider.php`, and `.env.example` after baseline restoration.
- Test `tests/Feature/Payments/CheckoutCreationTest.php` and `tests/Unit/Payments/MidtransGatewayTest.php`.

**Interfaces:** `CreatePaymentSession::__invoke(int $bookingId): PaymentSession`; `CreateBookingCheckout::handle(OutboxMessage $message): void` consumes `payment.session.requested`; `CheckoutUrlPolicy::accept(string $url): bool`. `MidtransGateway` implements the D1 interface using Laravel HTTP client and server-side credentials. Config selects provider/environment and a merchant-approved channel allowlist; default provider activation and live checkout remain disabled.

- [ ] **RED:** Test `test_checkout_requires_approved_online_booking`, `test_lost_create_response_never_creates_second_session`, `test_cancel_during_creation_does_not_publish_link`, `test_expiry_is_anchored_to_approval`, and unsafe URL rejection:
  ```php
  $this->assertSame(1, $fake->distinctMerchantReferences());
  $this->assertSame($booking->payment_deadline_at->timestamp, $request->deadline->timestamp);
  $this->assertSame(3600, $request->deadline->diffInSeconds($request->approvedAt, true));
  $this->assertNull($summary->forBooking($cancelled)['checkout_url']);
  Http::assertNothingSent(); // Unapproved booking branch.
  ```
- [ ] Run `php artisan test --filter="CheckoutCreationTest|MidtransGatewayTest"`; expect missing handler/adapter behavior to fail.
- [ ] Under `BookingMutation`, reload approval, payment preference, remaining window and existing session. Persist a stable merchant reference and creation lease before any network call. Outside the transaction, use lookup before retrying a lost response; an uncertain lookup/404 immediately after a timed-out create is not proof that nothing exists. Reuse the same reference; never mint another to bypass provider duplicate protection. On confirmed absence, retry the same idempotent request only when the adapter contract proves it safe; otherwise mark attention and reconcile.
- [ ] Implement provisional Snap hosted redirect with exact quote, configured channels, explicit expiry reproducing the stored approval-based deadline, and fixed application return URL. Snap's creation response contains token/redirect URL, not an expiry timestamp: `CheckoutResult.expiresAt` is the adapter's requested deadline, not invented provider-returned evidence. Validate the accepted request parameters and verify effective channel expiry in sandbox. For minute-granularity expiry, use a whole-minute duration and explicit start time that yield the exact deadline, including a seconds-bearing approval capped at appointment start; never round the promise later or silently shorten it. If a channel cannot honor that contract, leave it disabled. Validate URL host against exact environment checkout hosts; reject userinfo, non-HTTPS, arbitrary ports and deceptive suffixes. Do not extend deadlines for queue delay. Before persisting result, reacquire mutation lock; cancelled/expired/ineligible bookings expose no link and schedule expiry/reconciliation. References: [creation response](https://docs.midtrans.com/reference/sample-response), [explicit expiry](https://docs.midtrans.com/reference/expire-a-snap-session).
- [ ] Run the same tests with `Http::preventStrayRequests()` and fakes; expect PASS. Pin interrupted creation, lease recovery, deadline near appointment start, duplicate delivery and disabled-new-checkout cases. Emit `payment.session.ready` only after usable checkout state commits; outbox delivery failure cannot undo approval.
- [ ] Review network/transaction separation, credentials and URL secrecy; conditionally commit `feat: create durable hosted checkout after approval`.

## Task D3: Authenticated durable provider inbox

**Files:**

- Create `app/Http/Controllers/Webhooks/MidtransWebhookController.php` and `app/Http/Requests/Webhooks/MidtransNotificationRequest.php`.
- Create `app/Actions/Payments/ReceiveProviderEvent.php` and `app/Jobs/ProcessProviderEvent.php`.
- Create `app/Support/Payments/MidtransNotificationVerifier.php`.
- Modify `routes/web.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, and `config/payments.php`.
- Test `tests/Feature/Payments/ProviderWebhookTest.php`.

**Interfaces:** `ReceiveProviderEvent::__invoke(string $provider, array $validatedPayload): ProviderEvent`; `ProcessProviderEvent::handle(): void` receives event ID through its constructor and calls D4 reconciliation; `MidtransNotificationVerifier::verify(array $payload): bool`. Persist a minimally sufficient verified reference and digest; no callback-supplied URL is ever fetched.

- [ ] **RED:** Add `test_bad_signature_is_rejected`, `test_amount_string_is_not_reformatted_before_signature`, `test_signed_notification_cannot_override_retrieved_status`, `test_duplicate_event_is_accepted_once`, and `test_durable_inbox_failure_is_not_acknowledged`:
  ```php
  $this->postJson('/webhooks/payments/midtrans', $bad)->assertForbidden();
  $this->assertTrue($verifier->verify($signedWithGrossAmount265000Dot00));
  $this->assertFalse($verifier->verify($sameSignatureWithGrossAmount265000));
  $this->assertDatabaseCount('provider_events', 1);
  $this->assertSame(BookingStatus::AwaitingPayment, $booking->fresh()->status);
  ```
- [ ] Run `php artisan test --filter=ProviderWebhookTest`; expect route/verification/inbox cases to fail.
- [ ] Validate bounded scalar fields before hashing. Compare `SHA512(order_id + status_code + gross_amount + ServerKey)` with the supplied signature using constant-time comparison and the untouched string fields. Do not parse/reformat gross amount first. Reject missing/invalid signatures; separately reject wrong merchant/environment and unknown integration. The signature does not bind every notification field: always retrieve authoritative server status before applying collection or refund state. Follow [Midtrans notification verification](https://docs.midtrans.com/docs/https-notification-webhooks) and [Get transaction status](https://docs.midtrans.com/reference/get-transaction-status).
- [ ] Add the one public POST webhook route with a narrowly scoped CSRF exemption and bounded request size/rate. Persist authenticated, minimized events transactionally before returning 2xx. Use a deterministic digest of canonical retained event fields because a globally unique provider event ID is not guaranteed; changing status/evidence must produce a distinct event. Unknown references are durable quarantined events with staff attention, never guessed booking matches. Failed database writes return retryable non-2xx; identical durable replays return success. Processing leases and scheduled recovery survive worker restarts.
- [ ] Run the same tests; expect PASS. Add array/malformed payload, currency/amount/reference mismatch, provider timeout and stale conflicting notification tests. Fetch status by persisted merchant reference; apply no browser/callback assertion as proof of money.
- [ ] Review authenticity, PII minimization, replay handling and CSRF exemption scope; conditionally commit `feat: persist and verify payment provider notifications`.

## Task D4: Reconciliation, expiry and room/payment races

**Files:**

- Create `app/Actions/Payments/{ReconcilePayment,ApplyPaymentSnapshot}.php`.
- Create `app/Jobs/{ReconcileDuePayments,ExpireDuePayments,RecoverProviderEvents}.php`.
- Create `app/Support/Payments/ReservationReleasePolicy.php`.
- Modify `routes/console.php` and `config/outbox.php`.
- Extend the B6 harness in `tests/Concurrency/ConcurrencyTestCase.php` with financial race fixtures in `tests/Concurrency/fixtures/payment-operation.php`.
- Test `tests/Feature/Payments/PaymentReconciliationTest.php` and `tests/Concurrency/PaymentExpiryRaceTest.php`.

**Interfaces:** `ReconcilePayment::__invoke(int $sessionId): PaymentSession` retrieves outside lock; `ApplyPaymentSnapshot::__invoke(int $sessionId, PaymentSnapshot $snapshot): PaymentSession` applies under `BookingMutation`; `ReservationReleasePolicy::canRelease(Booking $booking, PaymentSession $session, CarbonImmutable $now): bool`. `ReconcileDuePayments::handle(): void`, `ExpireDuePayments::handle(): void`, `RecoverProviderEvents::handle(): void` process bounded batches with persisted leases and retry time.

- [ ] **RED:** Add `test_timely_collection_with_late_callback_confirms_only_live_booking`, `test_timer_does_not_prove_nonpayment`, `test_failure_cancels_without_offline_conversion`, `test_late_collection_requires_full_refund`, and mismatched/duplicate collection cases:
  ```php
  $this->assertSame(BookingStatus::Confirmed, $timely->fresh()->status);
  $this->assertSame(BookingStatus::Cancelled, $cancelledBeforeCallback->fresh()->status);
  $this->assertNull($unknown->roomReservation->fresh()->released_at);
  $this->assertSame('online', $failed->fresh()->payment_preference);
  $this->assertSame(265000, $lateCollection->refundObligation->amount_idr);
  ```
- [ ] Run `php artisan test --filter=PaymentReconciliationTest`; expect missing reconciliation behavior to fail. Add a separate-process expiry-versus-settlement barrier case to the B6 concurrency harness.
- [ ] Implement system lock order schedule guard → booking → reservation → session/collection/refund. Human financial actions additionally acquire `StaffAccessLock` first and recheck authority inside it, following the master plan. Verify exact reference, merchant/environment, IDR amount and collected timestamp. Persist each verified collection once; multiple collections remain distinct financial facts. A matching success at or before deadline confirms only an eligible awaiting-payment booking; terminal cancellation/no-show never reopens. Missing or contradictory collection time remains attention-required. Late success cancels if needed and produces one refund obligation per collection. An excess, mismatched-currency or unmapped collection remains quarantined; do not convert currencies or silently use it as payment.
- [ ] On definitive failure or verified closed-unpaid expiry, cancel through `BookingStateWriter`, stamp eligibility/reason, and release only when financially and physically safe. After deadline, hide checkout, request provider expiry outside locks, and reconcile underlying transaction; page expiry alone is insufficient. Unknown outcomes retain the original reservation until resolved or its original end; do not extend that interval. Staff cancellation remains terminal even while the physical/financial block stays. After-start occupancy/cleaning blocks from B4 remain respected. Repeated snapshots cannot regress collected funds to unpaid.
- [ ] Add every-minute expiry/inbox recovery and bounded reconciliation with increasing delays up to a configurable maximum; alert on exhausted attempts but retain recoverable work. Consume cancellation/no-show events to expire pending checkout and invoke refund obligation discovery; recheck persisted eligibility on every later collection. Emit attention and money events only for committed new facts. Do not read new-checkout feature flags as permission to abandon existing sessions.
- [ ] Run the feature test and `php vendor/phpunit/phpunit --configuration phpunit.concurrency.xml --filter=PaymentExpiryRaceTest`; expect PASS on the declared production engine. Test late callback versus staff cancellation, deadline equality, near-start delay, restart recovery, distinct duplicate collections and uncertainty at customer arrival. No real provider requests or production database access.
- [ ] Review allocation release evidence and terminal transitions; conditionally commit `feat: reconcile payment outcomes and booking expiry`.

## Task D5: Full refunds with safe manual fallback

**Files:**

- Create `app/Actions/Payments/{EnsureRefundObligations,RequestRefund,PrepareManualRefund,RecordManualRefund}.php`.
- Create `app/Handlers/Outbox/ProcessBookingRefunds.php` and `app/Jobs/ReconcileDueRefunds.php`.
- Create `app/Http/Controllers/Admin/AdminRefundController.php`.
- Create `app/Http/Requests/Admin/{PrepareManualRefundRequest,RecordManualRefundRequest}.php`.
- Modify `app/Policies/BookingPolicy.php`, `routes/{admin,console}.php`, and `config/outbox.php`.
- Test `tests/Feature/Payments/RefundExecutionTest.php` and `tests/Concurrency/RefundRaceTest.php`.

**Interfaces:** `EnsureRefundObligations::__invoke(int $bookingId): void`; `RequestRefund::__invoke(int $obligationId): RefundAttempt`; `PrepareManualRefund::__invoke(User $actor, int $obligationId, string $requestKey): RefundAttempt`; `RecordManualRefund::__invoke(User $actor, int $attemptId, string $evidenceReference, CarbonImmutable $paidAt): RefundAttempt`. `BookingPolicy::managePayments(User $user, Booking $booking): bool` uses the same verified owner/booking-staff capability.
No additional owner approval.
The record action confirms a previously reserved manual attempt; it does not initiate a bank transfer.

**Routes:** `POST /admin/bookings/{booking}/refunds/{obligation}/manual-attempts` prepares the payout; `POST /admin/bookings/{booking}/refund-attempts/{attempt}/record` records its completion. Scoped binding must prove each obligation/attempt belongs to the route booking. Request keys are UUIDs; evidence references are bounded private strings, not publicly served upload paths. Neither endpoint accepts a client refund amount: the locked obligation determines it.

- [ ] **RED:** Add `test_all_cancellation_and_no_show_collections_are_fully_refundable`, `test_manual_refund_blocked_until_automated_outcome_resolved`, `test_manual_double_click_records_one_return`, and `test_pending_refund_is_not_completed`:
  ```php
  $this->assertSame(265000, $obligation->amount_idr); // No fee deduction or time cutoff.
  $this->assertSame(265000, $obligation->attempts()->where('state', 'processing')->sum('amount_idr'));
  $this->assertSame('processing', $obligation->fresh()->state->value);
  $this->assertDatabaseCount('refund_attempts', 1);
  $this->assertSame(0, $unpaidOnsite->refundObligations()->count());
  ```
- [ ] Run `php artisan test --filter=RefundExecutionTest`; expect missing obligation/attempt rules to fail. Include before-start, late cancellation, spa cancellation, recorded no-show and late collection datasets; completed treatment alone grants no entitlement.
- [ ] Create obligations under the shared guarded mutation boundary using transaction uniqueness. Compute outstanding as collected minus confirmed returns; reserve each in-flight amount under lock before external work. Keep `returned + in_flight <= collected`. A retry uses the same stable refund reference and reconciles uncertain previous calls before attempting again. Provider acceptance means processing, not returned; authoritative settlement updates the completed amount and emits `refund.updated`.
- [ ] Map unsupported methods, expired refund windows and confirmed inability to execute to action required. A transport timeout or ordinary pending refund stays unresolved/processing, never becomes manual permission. Once prior attempts are definitively failed or known not executed, authorized staff may reserve the full outstanding manual amount through a CSRF-protected, throttled action. The UI shows a private payout checklist and reference input; account details stay in the approved private staff process, never Telegram. Evidence/actor/time are required to mark paid; record completion transactionally. If staff cannot prove whether a manual payout happened, leave its amount reserved for reconciliation.
- [ ] Register `ProcessBookingRefunds` on `booking.cancelled`, `booking.no_show`, and eligible late `payment.succeeded` independently from communications. Add refund follow-up scheduler and dashboard action-required state; emit versioned updates. Implement automatic refund result lookup in the Midtrans adapter only for verified merchant/channel support; lack of a reliable lookup leaves staff reconciliation, not blind retry. No automated transfer/payout product is introduced.
- [ ] Run the feature suite and `php vendor/phpunit/phpunit --configuration phpunit.concurrency.xml --filter=RefundRaceTest`; expect PASS. Race cancellation with collection, duplicate refund workers, two staff manual preparations, and provider completion with manual preparation. Replay evidence must return the original result; changed evidence under a used request key is rejected.
- [ ] Review money limits, revoked-staff checks inside actions and private evidence retention; conditionally commit `feat: execute full refunds with tracked staff fallback`.

## Task D6: On-site receipts, truthful presentation and release checks

**Files:**

- Create `app/Actions/Payments/RecordOnSitePayment.php` and `app/Queries/BookingFinancialSummary.php`.
- Create `app/Actions/Payments/CorrectOnSiteReceipt.php`, `app/Models/OnSiteReceiptCorrection.php`, `app/Http/Requests/Admin/CorrectOnSiteReceiptRequest.php`, and `database/migrations/2026_10_22_030001_create_receipt_corrections.php`.
- Create `app/Http/Controllers/Admin/AdminPaymentController.php` and `app/Http/Requests/Admin/RecordOnSitePaymentRequest.php`.
- Create `resources/js/components/booking/PaymentSummary.vue` and `resources/js/components/admin/BookingFinancialActions.vue`.
- Create `app/Console/Commands/CheckPaymentReadiness.php`.
- Modify the C-plan `app/Support/Payments/BookingPaymentSummary.php`; retain its array shape (C4c added no interface or binding; see the C4c note above).
- Modify `app/Support/{BookingPresenter,AdminBookingPresenter}.php` and `app/Http/Controllers/Admin/AdminDashboardController.php`.
- Modify `resources/js/pages/{bookings/Show,admin/bookings/Show,admin/Dashboard}.vue`.
- Modify `resources/js/types/index.ts`, `resources/js/lib/booking.ts`, `resources/js/data/translations.ts`, and `lang/{id,en}/admin.php`.
- Modify `routes/admin.php`, `README.md`, and `PRODUCT.md`.
- Test `tests/Feature/Payments/{OnSitePaymentTest,PaymentPresentationTest,PaymentReadinessTest}.php`, `tests/frontend/bookingPayments.test.ts`, and `tests/e2e/booking-payment.spec.ts`.

**Interfaces:** `RecordOnSitePayment::__invoke(User $actor, int $bookingId, int $amountIdr, string $method, string $receiptReference, string $requestKey, CarbonImmutable $receivedAt): PaymentTransaction`; `BookingPaymentSummary::forBooking(Booking $booking): array{state:string,deadline:?string,checkout_url:?string,refunds:array}`; `BookingFinancialSummary::totals(): array{booked_value_idr:int,collected_idr:int,refunded_idr:int,refund_due_idr:int,outstanding_on_site_idr:int,unverified_legacy_count:int}`. `payments:readiness` is read-only and exits nonzero for missing verified merchant/channel/expiry/refund configuration; it never activates checkout.

`CorrectOnSiteReceipt::__invoke(User $actor, int $transactionId, int $actualAmountIdr, string $reason, string $requestKey): OnSiteReceiptCorrection` records a mistaken receipt amount, not a customer payout. Corrections are append-only reductions with transaction ID, reduction amount, reason, actor, unique request key and timestamp. Effective on-site collection is original amount minus corrections; additional genuine receipts are new collection records. Block correction if any refund attempt is in flight or completed and require reconciliation instead. Recompute an unexecuted obligation under the same lock. Downward correction to zero is allowed only with an explicit staff attestation that no money was received.

**Route:** `POST /admin/bookings/{booking}/payments/on-site`, guarded by verified staff access, CSRF, scoped binding and payment-write throttle. Validate scalar IDR integer, method allowlist `cash|card_terminal|bank_transfer|other`, bounded receipt reference, UUID request key and nonfuture receipt time. This records staff-attested money received, not a gateway-verified transaction.

Receipt corrections use `POST /admin/bookings/{booking}/payments/on-site/{transaction}/corrections` with the same guards and scoped ownership; actual amount must be between zero and current effective collection. Request replay is idempotent and cannot silently change its reason or amount.

- [ ] **RED:** Test on-site receipt replay/authorization, no double charge for uncertain online payment, financial totals, private evidence exclusion, and `test_disabling_checkout_preserves_financial_recovery`:
  ```php
  $this->assertSame($first->id, $replayed->id);
  $this->assertSame(265000, $totals['collected_idr']);
  $this->assertSame(265000, $totals['refund_due_idr']);
  $this->assertSame(0, $totals['refunded_idr']); // Requested is not returned.
  $this->assertArrayNotHasKey('provider_payload', $customerProjection);
  $this->assertNull($summary->forBooking($cancelled)['checkout_url']);
  ```
- [ ] Run `php artisan test --filter="OnSitePaymentTest|PaymentPresentationTest|PaymentReadinessTest"` and `npm run test:frontend -- tests/frontend/bookingPayments.test.ts`; expect missing projections/actions and old revenue semantics to fail.
- [ ] Implement on-site receipts under staff-access/booking mutation locks and current staff authorization. Accept actual positive IDR collections for confirmed/completed at-spa bookings up to their remaining quote balance; support multiple partial receipts but require total balance and method/reference to be visible. No on-site collection is allowed for an online booking or terminal cancellation/no-show. A repeat request returns its original receipt; changed contents reject. Receipts do not mark service completed. Implement audited correction through the defined action; never send a refund merely to repair a data-entry error or invent money for legacy bookings. Add `test_receipt_correction_does_not_send_money` and `test_receipt_correction_cannot_race_a_refund`: assert original receipt retained, effective collected total reduced, zero external payout requests, and rejection when a refund amount is reserved.
- [ ] Implement separate dashboard totals: booked value is confirmed/completed quotes; collected is verified gateway collections plus staff-attested effective on-site receipts; refunded is confirmed returned amounts; refund due includes all outstanding obligations; on-site outstanding is positive known quote balance for confirmed/completed new-flow at-spa bookings. Refund eligibility for on-site receipts uses that same effective collection, including corrections. Exclude legacy unknown payment states from asserted outstanding amounts and show the legacy count. Label these precisely, replacing the current quote-derived revenue label. Show service-completed-but-unpaid discrepancies.
- [ ] Extend allowlisted presenters and localized ID/EN UI with exact deadline, link-ready/pending/unknown/failed/refund states, customer cancellation contact and new-booking path. A browser return only reloads server state. Staff sees unresolved payment warnings and cannot take a second payment; ordinary pending refund shows processing. Customer ownership remains required for payment links. Use the shared projection in communications; keep evidence/provider references private and checkout links out of logs and Telegram.
- [ ] Implement read-only readiness checks and operating instructions: merchant approval, narrow verified channels, actual fee/refund funding, server keys through secret management, real webhook reachability, queue/scheduler supervision, configured review/checkout minimums, and manual payout process. Add sandbox fixtures for successful collection, failure, uncertain result, late settlement, refund pending/success/failure and disabled-checkout recovery. These are fixtures until a separately authorized real merchant sandbox rehearsal proves channel behavior; no fabricated fees or eligibility.
- [ ] Run focused PHP/frontend tests, `npm run types:check`, `npm run build`, and `npm run test:e2e -- tests/e2e/booking-payment.spec.ts` against isolated fixtures; expect PASS. Run both new concurrency suites through the declared-engine harness and the master regression/coverage checks. If merchant onboarding or production-engine verification is unavailable, keep the online flag off and record that launch gate; local tests cannot certify it.
- [ ] Review financial copy, customer data exposure, backup/rollback procedure and provider outages; conditionally commit `feat: expose payment receipts refund progress and readiness`.

## Self-review and handoff

Spec coverage: approval-first checkout/deadline D2; provider identity/inbox D3; failure/uncertainty/late collection and resource races D4; full refunds/manual fallback/no owner approval D5; on-site receipts/reporting/customer status/release D6. D1 supplies durable facts and database uniqueness for every later task. Each Review Focus condition has a named owning test.

No live provider selection, credentials, merchant activation, payouts, or product tests were performed while writing this plan. Midtrans is a provisional adapter choice; actual supported channels and costs remain onboarding facts. Review this plan with its prerequisite contracts before implementation. Application code and tests are still to be written.
