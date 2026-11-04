# Telegram Booking Actions and Customer Updates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let owner-authorized staff approve or decline bookings in Telegram, with durable delivery and truthful customer status updates.

**Architecture:** Authenticate and durably receive Telegram updates, then call the same authorized booking actions used by the dashboard. Fan committed domain events into independent Telegram and email outbox deliveries. Payment presentation has a stable interface that works before the money module is enabled.

**Tech Stack:** PHP ^8.3, Laravel ^13.0, PHPUnit ^11.5.3, Vue 3, TypeScript, Vitest; existing Laravel HTTP client, notifications, and transactional outbox.

**Spec:** [Approved design](../specs/2026-10-22-booking-availability-payments-design.md), sections 2–3 and 9–16. Requires [baseline](2026-10-22-booking-baseline.md) and [scheduling/pricing](2026-10-22-booking-scheduling-pricing.md); read the [master plan](2026-10-22-booking-implementation.md).

## Global Constraints

- Authorized staff can Confirm, Decline, and View a booking. Decline requires a second confirmation.
- The owner controls which staff may act. Record the individual actor. Telegram group membership alone grants no authority.
- Staff approval is required for every booking. Online payment is not collected before approval.
- 60 minutes after approval, capped at appointment start. Display the exact deadline.
- No automatic conversion to pay-at-spa. The customer can submit a new request.
- This release has no rescheduling and no cancellation cutoff for refund eligibility.
- Telegram Confirm explicitly attests that staff checked therapist availability and suitability, including group and double-therapist demand. It remains one click; only Decline has a second confirmation.
- Keep dashboard access available. Telegram webhook identity never substitutes for staff authorization; Telegram's server IP is not the staff member's IP.
- Tests fake every Telegram/mail transport. No live webhook registration, messages, mail, or merchant calls during automated verification.
- Preserve unrelated working-tree changes; commits are conditional on repository permissions and authorization, with only the task's inspected changes staged.

## Review Focus

1. Permission is revoked after a button is rendered: the next callback must fail current authorization without changing booking or occupancy — C3 `test_revoked_staff_cannot_use_rendered_button`.
2. Two staff use the same Decline prompt: only its initiating actor can perform the second click — C3 `test_decline_confirmation_is_bound_to_initiating_actor`.
3. Telegram repeats an update after a database commit/queue outage: acknowledge the persisted update and recover exactly one business action — C2 `test_duplicate_update_recovers_after_dispatch_outage`.
4. An old approval notification runs after cancellation: it must not promise a live appointment or issue an obsolete checkout link — C4 `test_delayed_approval_notice_cannot_announce_cancelled_booking`.
5. Telegram accepts a message but its response is lost: do not claim exactly-once delivery; possible duplicates must carry safe, stale-checked actions and visible delivery uncertainty — C4 `test_unknown_send_outcome_cannot_duplicate_booking_action`.

## Shared contracts and ownership

Migration numbers `2026_10_22_020000` through `020002` belong to this plan. Paths labelled Create are proposals; modified paths exist or are produced by the prerequisite plans.

- Consume `App\Contracts\OutboxHandler::handle(OutboxMessage $message): void` and `App\Support\Outbox\OutboxWriter::record(string $type, string $deduplicationKey, array $payload): OutboxMessage`. Record inside the business transaction. `config/outbox.php` maps event type to a list of handlers with separately tracked/recoverable delivery.
- Consume `App\Support\ActionContext(string $channel, ?string $ipAddress = null, ?string $userAgent = null, ?string $externalActorId = null)`; callbacks use `telegram`, null human IP/UA, and the Telegram sender ID.
- Consume `App\Support\Staff\StaffAccessLock::run(Closure $operation): mixed`, then `App\Support\Bookings\BookingMutation::run(int $bookingId, Closure $operation): mixed`. Lock order is staff-access guard before schedule guard/booking. Never acquire them in reverse order.
- Consume `App\Actions\ApproveBooking::__invoke(User $actor, int $bookingId, BookingStatus $expected, ActionContext $context): Booking` and `CancelBooking::__invoke(User $actor, int $bookingId, BookingStatus $expected, string $reason, ActionContext $context): Booking`. Approval is the suitability attestation defined in B4; Telegram Decline only accepts Pending.
- Scheduling emits `booking.created`, `booking.approved`, `booking.confirmed`, `booking.cancelled`, `booking.completed`, `booking.no_show`; payload includes `booking_id:int, booking_version:int`. Creation is version 1. `booking.approved` covers both pay-at-spa confirmation and online awaiting-payment approval; `booking.confirmed` is the subsequent online payment confirmation.
- Produce `App\Contracts\BookingPaymentSummary::forBooking(Booking $booking): array{state:string,deadline:?string,checkout_url:?string,refunds:array}` and concrete `App\Support\Payments\BookingPaymentSummary`. C4 creates both; the payment plan extends that concrete implementation and retains the binding/shape.
- Produce `App\Support\Bookings\BookingCommunicationFanout implements OutboxHandler`, creating `telegram.booking.sync` and `customer.booking.notice` child outbox rows. Children have separate dedupe keys and retries; never couple successful email delivery to Telegram availability.

## Task C1: Owner-controlled Telegram identity enrollment

**Files:** Create `database/migrations/2026_10_22_020000_create_telegram_staff_links.php`, `app/Models/{TelegramStaffLink,TelegramEnrollment}.php`, `app/Actions/Telegram/{IssueStaffEnrollment,ClaimStaffEnrollment,ActivateStaffLink,RevokeStaffLink}.php`, `app/Http/Controllers/Admin/AdminTelegramStaffController.php`, `app/Http/Requests/Admin/IssueTelegramEnrollmentRequest.php`. Modify `routes/admin.php`, `resources/js/pages/admin/staff/Index.vue`, `config/services.php`, `.env.example`. Create `tests/Feature/Telegram/StaffLinkTest.php` and `tests/frontend/telegramStaffLinks.test.ts`.

**Interfaces:** `IssueStaffEnrollment::__invoke(User $owner, int $staffId): string` returns a one-use bot deep-link token; `ClaimStaffEnrollment::__invoke(string $token, string $telegramUserId): TelegramEnrollment`; `ActivateStaffLink::__invoke(User $owner, int $enrollmentId): TelegramStaffLink`; `RevokeStaffLink::__invoke(User $owner, int $staffId): void`. Claims arrive only through authenticated updates in C2. Owner activation is a staff-management operation, not an additional booking approval.

**Data:** Store token hash, selected staff ID, issuing owner, expiry, claimed sender ID, claim/activation timestamps; never match accounts by Telegram display name. Token lifetime is 15 minutes, an implementation default. Current links have unique app-user and Telegram-user IDs; preserve previous bindings/revocations in an audit table. An active ID cannot be reassigned without an explicit owner revocation followed by new enrollment.

- [ ] **RED:** Add tests for owner-only issue/activation/revoke, expired/replayed claims, concurrent claims, mismatched staff identity, ID collision, and missing booking capability. Assertions include:
  ```php
  $this->assertSame($staff->id, $link->user_id);
  $this->assertSame('1234567890123', $link->telegram_user_id);
  $this->assertDatabaseCount('telegram_staff_links', 1);
  $this->assertDatabaseMissing('telegram_enrollments', ['token_hash' => $plainToken]);
  $this->assertNull($pendingEnrollment->activated_at);
  ```
- [ ] Run `php artisan test --filter=StaffLinkTest` and `npm run test:frontend -- tests/frontend/telegramStaffLinks.test.ts`; expect new enrollment/schema/authorization assertions to fail.
- [ ] Implement the named actions under StaffAccessLock. Issue only for a verified Owner/Admin currently allowed to manage bookings; recheck those conditions at activation and callback use. A `/start` claim proves the Telegram sender controls that account; the owner reviews the selected website account and claimed numeric identity before activation. Render only a pending claim until that step succeeds. Treat IDs as decimal strings, validate length/type, reject bot senders, and never authorize using username, group title, or forwarded-message identity.
- [ ] Add owner routes `POST /admin/staff/{user}/telegram-enrollment`, `POST /admin/telegram-enrollments/{enrollment}/activate`, `DELETE /admin/staff/{user}/telegram-link`, with existing admin middleware, CSRF and staff-management throttling. Show enrollment expiry, masked identity, active/revoked state and audit actor in the existing staff page. Configure `services.telegram.webhook_secret`, `actions_enabled` default false and the public bot username; `.env.example` contains placeholders only.
- [ ] Run the same tests plus `npm run types:check`; expect PASS. Refactor immutable action inputs and sanitized audit output; verify invalid direct-action calls are covered.
- [ ] Review identity binding, role-change races and token leakage; conditionally commit `feat: add owner-managed Telegram staff enrollment`.

## Task C2: Authenticated webhook and durable update processing

**Files:** Create `database/migrations/2026_10_22_020001_create_telegram_updates.php`, `app/Models/TelegramUpdate.php`, `app/Http/Controllers/Webhooks/TelegramWebhookController.php`, `app/Http/Requests/Webhooks/TelegramUpdateRequest.php`, `app/Support/Telegram/{TelegramUpdateRecorder,TelegramUpdateHandler}.php`, `routes/webhooks.php`. Modify `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `config/outbox.php`, `config/services.php`. Create `tests/Feature/Telegram/{WebhookTest,UpdateRecoveryTest}.php`.

**Interfaces:** `TelegramUpdateRecorder::record(array $validatedUpdate): TelegramUpdate` inserts/deduplicates and writes `telegram.update.received` atomically. `TelegramUpdateHandler implements OutboxHandler` consumes `{telegram_update_id:int}`; it invokes enrollment claim for private `/start` messages or C3 callback processing. Ignore other supported Telegram update variants explicitly; record outcome `ignored`, not an application error.

**Data:** `telegram_updates` has unique bot identity/update ID, minimal normalized payload, processing outcome/timestamps and sanitized failure category. Store sender, callback ID/data, chat/message identity or private enrollment command only; omit unnecessary message text/contact data. Hash/scrub the enrollment token after claim/expiry; define seven-day processed-payload retention while preserving action audit and duplicate-update tombstones.

- [ ] **RED:** Add missing/wrong-secret, absent configuration, malformed scalar/nested payload, oversized body, duplicate ID, commit failure, recovery and unsupported-update tests. Exercise the transport response separately from business processing:
  ```php
  $this->postJson('/webhooks/telegram', $update)->assertForbidden();
  $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)
      ->postJson('/webhooks/telegram', $update)->assertOk();
  $this->assertDatabaseCount('telegram_updates', 1);
  $this->assertDatabaseCount('outbox_messages', 1);
  $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
  ```
- [ ] Run `php artisan test --filter="WebhookTest|UpdateRecoveryTest"`; expect absent webhook/inbox behavior to fail. `UpdateRecoveryTest::test_duplicate_update_recovers_after_dispatch_outage` must simulate queue publication failure after the durable commit, then replay publisher/handler.
- [ ] Register the webhook through a separate route file without browser auth/CSRF middleware; retain CSRF for all dashboard routes. Use constant-time comparison with the nonempty configured secret before parsing useful data. Validate a maximum 64 KiB JSON body and allowed normalized shapes. Fail closed with 503 when secret/configuration is missing; wrong/missing request secret is 403. A durable insert failure is 503 so Telegram can retry. Authenticated unsupported updates receive 200 without actions.
- [ ] Implement atomic update/outbox persistence and return 200 only after commit, including repeats already stored. Queue availability is not a requirement for this acknowledgement. Apply a dedicated limiter after secret authentication, with bounded rejected-input logging that never records tokenized bot URLs, secrets or enrollment tokens. Processing completion and business mutation must commit together; handler replay after worker loss cannot repeat the action. Do not call Telegram synchronously in the ingress controller.
- [ ] Run the same tests; expect PASS. Add interrupted processing/recovery assertions and confirm external transports remain faked. Document retention and replay boundaries in `README.md` without supplying a real webhook URL/secret.
- [ ] Review transport authentication separately from staff identity and application authorization; conditionally commit `feat: persist authenticated Telegram updates for recovery`.

## Task C3: Confirm, two-step Decline, and safe stale callbacks

**Files:** Create `database/migrations/2026_10_22_020002_create_telegram_booking_actions.php`, `app/Models/{TelegramBookingMessage,TelegramActionToken}.php`, `app/Support/Telegram/{BookingActionKeyboard,ProcessBookingCallback}.php`; modify `app/Support/Telegram/TelegramUpdateHandler.php`, `app/Support/TelegramBookingMessageFormatter.php`, `config/outbox.php`. Create `tests/Feature/Telegram/{BookingCallbacksTest,CallbackRaceTest}.php`; extend `tests/Feature/Support/TelegramBookingMessageFormatterTest.php`.

**Interfaces:** `BookingActionKeyboard::forBooking(Booking $booking, TelegramBookingMessage $message): array` returns an inline keyboard; `ProcessBookingCallback::__invoke(TelegramUpdate $update): void` performs the validated action and records durable acknowledgement/sync work. Tokens represent `approve|decline_prompt|decline_confirm`; Decline confirmation additionally binds initiating website user ID and Telegram sender ID, and expires after five minutes. `View booking` is an ordinary authenticated dashboard URL, never a bearer-access customer URL.

**Data:** Message records persist booking ID, chat ID, optional thread ID, message ID, last synchronized booking version and delivery state. Token records bind opaque nonce/hash, message identity, booking ID/version, action, optional initiating actor, expiry and consumed result. Initial action validity ends at the earlier of appointment start or a booking-version change. Nonces are lookup values, not permission; fit callback data within 64 bytes. Keep renderable nonce material encrypted if persistence is needed for retries.

- [ ] **RED:** Add tests for pending approval, room conflict, wrong chat/message/thread, forwarded context, unlinked/revoked actor, stale booking version, replay with new update ID, actor-bound decline and concurrent approval. Pin assertions:
  ```php
  $this->assertSame(BookingStatus::Confirmed, $onsite->fresh()->status);
  $this->assertDatabaseCount('room_reservations', 1);
  $this->assertSame('telegram', $change->channel);
  $this->assertSame($staff->id, $change->admin_user_id);
  $this->assertNull($change->ip_address);
  $this->assertSame(BookingStatus::Pending, $afterFirstDecline->status);
  $this->assertSame(BookingStatus::Cancelled, $afterSecondDecline->status);
  ```
- [ ] Run `php artisan test --filter="BookingCallbacksTest|CallbackRaceTest|TelegramBookingMessageFormatterTest"`; expect new callbacks to fail. `test_decline_confirmation_is_bound_to_initiating_actor` must verify a different authorized staff member still cannot use the first actor's confirmation token.
- [ ] Implement callback validation against normalized authenticated update data, actual `callback_query.from.id`, configured chat/thread, persisted message and unexpired token. In one StaffAccessLock→BookingMutation transaction reload staff/link/booking, recheck `services.telegram.actions_enabled`, current policy and exact version, invoke the shared action, consume the token and record update result. A disabled action records a denied result without changing the booking; dashboard access remains available. Never use a user or actor ID supplied inside callback data as authority. A same-state/newer-version booking is stale even when its enum still matches. Preserve the existing `admin_user_id` audit column for human actors and leave it null for system actors.
- [ ] Make Confirm invoke ApproveBooking with expected Pending and the stated therapist/suitability attestation. First Decline only creates a private-to-actor confirmation token in the shared message; another actor can restart their own prompt but cannot consume that token. Second Decline invokes CancelBooking with expected Pending and reason `staff_declined`. Disable or reject booking buttons on nonpending states. Show safe conflict/stale/denied outcomes and refresh the message; do not expose another customer's data through callback errors.
- [ ] Persist `telegram.callback.answer` work with `{callback_query_id,text,show_alert}` and stable update-based dedupe; use a short configured queue for responsiveness. Expired Telegram acknowledgement windows are terminal delivery outcomes, not failed booking actions. Never retry a committed approval to fix an acknowledgement failure. Room conflicts leave booking/token action uncommitted; a fresh render lets staff retry after resolving the schedule.
- [ ] Run the same tests; expect PASS. Extend the scheduling separate-process harness with Telegram-versus-dashboard approval for the last suitable room and revoked capability versus callback serialization; assert persisted invariants, not a preferred race winner.
- [ ] Review direct action authorization, lock ordering, decline binding and message provenance; conditionally commit `feat: add authorized Telegram booking decisions`.

## Task C4: Recoverable message sync and truthful customer notices

**Files:** Create `app/Contracts/BookingPaymentSummary.php`, `app/Support/Payments/BookingPaymentSummary.php`, `app/Support/Bookings/BookingCommunicationFanout.php`, `app/Support/Telegram/{SyncBookingMessage,AnswerCallback}.php`, `app/Support/Bookings/SendCustomerBookingNotice.php`, `app/Notifications/BookingStatusNotification.php`, `resources/views/mail/booking-status.blade.php`. Modify `app/Support/{TelegramClient,TelegramBookingMessageFormatter,BookingPresenter,AdminBookingPresenter}.php`, `app/Providers/AppServiceProvider.php`, `config/outbox.php`, `resources/js/pages/{bookings/Show,admin/bookings/Show}.vue`, `lang/{en,id}/admin.php`, `README.md`. Tests: create `tests/Feature/Telegram/MessageSyncTest.php`, `tests/Feature/BookingCommunicationTest.php`; extend `tests/Feature/Support/TelegramClientTest.php`, `tests/frontend/bookingPages.test.ts`.

**Interfaces:** `SyncBookingMessage`, `AnswerCallback`, `SendCustomerBookingNotice` implement OutboxHandler. Extend compatible `TelegramClient::sendMessage(string $message, string $chatId, ?int $messageThreadId = null, ?array $replyMarkup = null): int`; add `editMessage(string $chatId, int $messageId, string $message, array $replyMarkup): void` and `answerCallbackQuery(string $callbackQueryId, string $text, bool $showAlert = false): void`. Add `BookingStatusNotification::__construct(int $bookingId, string $noticeKind)`; render latest authorized booking/payment summary at delivery. No transport exception may expose the tokenized endpoint.

**Projection boundary:** Bind the new BookingPaymentSummary contract to the concrete class above. At stage C, derive only `at_spa_unrecorded|unknown` from booking preference, ISO deadline from booking fields, `checkout_url=null`, and `refunds=[]`. Never infer collected money from Confirmed or returned money from Cancelled. Stage D extends this concrete projection using payment/refund records; C neither queries nonexistent payment tables nor claims online checkout exists. Online request creation stays disabled until D is complete.

- [ ] **RED:** Test independent channel recovery, latest-state rendering, absent customer email, no access-link leakage, translated labels, sanitized Telegram errors, delivery ambiguity and event dedupe. Include:
  ```php
  $this->assertSame('at_spa_unrecorded', $summary->forBooking($onsite)['state']);
  $this->assertNull($summary->forBooking($onsite)['checkout_url']);
  $this->assertSame(1, $paidNotices->count());
  $this->assertStringNotContainsString('Payment received', $onsiteMail);
  $this->assertStringNotContainsString($oldCheckoutUrl, $cancelledMail);
  $this->assertDatabaseCount('room_reservations', 1);
  ```
- [ ] Run `php artisan test --filter="MessageSyncTest|BookingCommunicationTest|TelegramClientTest"` and `npm run test:frontend -- tests/frontend/bookingPages.test.ts`; expect new projection/fanout/delivery assertions to fail.
- [ ] Implement fanout for scheduling events and later `payment.session.ready`, `payment.attention_required`, `refund.updated`. Record child deliveries in a transaction, deduped by source outbox ID/channel. Telegram sync reloads the latest booking every time and serializes per-message delivery so old work cannot overwrite a newer status. A delivered initial message persists chat/message identity before later edits. Treat “message is not modified” as success; a deleted message allows a tracked replacement with fresh action bindings.
- [ ] Map customer notices precisely: `booking.created` has no new acceptance email; `booking.approved` announces accepted pay-at-spa or online approval with fixed deadline; `payment.session.ready` offers a link only while current state is awaiting payment and deadline remains; `booking.confirmed` alone announces verified online payment confirmation. Do not add another paid email for `payment.succeeded`. Cancellation/no-show, payment attention and `refund.updated` describe the actual current state. Completed events sync Telegram and the website without an unnecessary new email. Skip superseded notices with a persisted reason, leaving the newer lifecycle notice deliverable.
- [ ] Make refund copy distinguish required/processing/action-required/completed, never “refunded” before confirmed evidence. Accountless staff bookings with no email record a skipped customer-mail outcome and show staff the contact task. Use the existing authenticated customer booking URL only when there is a linked customer; never send an admin URL to a customer. Add mail/Telegram delivery failures and retry status to the authorized admin page; keep public pages free of internal failure detail and tokens.
- [ ] Retry transport failures through the existing durable handler tracking; a successful Telegram edit cannot mark a failed email delivered. On ambiguous initial send/email acknowledgement, record uncertainty and bounded recovery; the remote APIs do not promise exactly-once delivery. Repeated notifications must remain safe because actions reauthorize and check booking versions. Register fanout once and replace the baseline booking-created notification consumer to avoid two initial Telegram messages; retain compatibility handling only for already queued legacy jobs.
- [ ] Run the same tests, `npm run types:check`, and the master-plan regression checks; expect PASS with external services faked. Readiness requires configured real mail transport and a separately authorized delivery rehearsal; log mailer is not proof of delivery. Document webhook setup/recovery without registering anything live.
- [ ] Review every customer claim against the projection, event registrations against duplicate delivery, and provider-error logging against secrets; conditionally commit `feat: deliver recoverable booking messages and customer updates`.

## Self-review and handoff

Coverage: owner identity/revocation C1/C3; authenticated durable receipt C2; Confirm/Decline/View and shared allocation C3; delivery/customer status C4. All five Review Focus conditions have assigned tests. Stage C has no undefined payment dependency; stage D extends the declared projection and emits the declared events. Automated tests cannot certify live webhook/mail delivery or the production database race behavior.

This plan defines future work; none of its test or implementation checkboxes has been executed. Review alongside the master and dependent plans. Keep online payments disabled until their full implementation and release checks are complete.
