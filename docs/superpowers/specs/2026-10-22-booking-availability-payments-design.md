# Booking availability, payments, and Telegram approval

Date: 22 October 2026. Business brief and written design approved, including the manual-refund fallback.

This document describes a future release of the existing Laravel/Inertia/Vue spa application. It is not a statement that these features exist or that the site is ready to accept payments. The business decisions below came from the booking interview. Technical choices and operational defaults are labelled as recommendations. Actual room data, menu pricing, merchant eligibility, and deployment configuration still need verification.

The approved scope supersedes the payment/scheduling exclusions in earlier project documents and the earlier proposal for a 24-hour cancellation cutoff. This release has no rescheduling and no cancellation cutoff for refund eligibility.

## 1. Assessment and release objective

Payment is one missing feature, but adding a checkout button would leave the spa exposed to overlapping bookings, uncertain payment outcomes, and refunds that staff cannot track. The existing application already has useful foundations: authenticated booking submission, server-calculated quotes, historical snapshots, submission deduplication, audited status changes, a staff console, and outbound Telegram notifications.

The next release should connect those foundations into one operating flow: show room availability, let staff approve a request, reserve the room, collect payment when selected, and account for every cancellation and refund. Booking status, room occupancy, money collected, and money returned must remain distinct.

Before accepting real customers or money, address the security and reliability issues in [the backend audit](../../../outputs/backend-flow-audit-2026-10-15.md). Source inspection on 22 October still found the password-recovery/session and booking-notification delivery patterns described there. This document does not claim that those defects were fixed or that the earlier test results were rerun.

## 2. Approved business decisions

| Area | Agreed rule |
| --- | --- |
| Payment choice | Full online payment after staff approval, or payment at the spa. |
| Approval | Staff approval is required for every booking. Online payment is not collected before approval. |
| Online deadline | 60 minutes after approval, capped at appointment start. Display the exact deadline. |
| Failed payment | A definitive payment failure cancels that booking. No automatic conversion to pay-at-spa. The customer can submit a new request. |
| Uncertain payment | A browser error or interrupted connection is not proof of payment failure. Reconcile with the provider. |
| Room allocation | Reserve a suitable room at approval, including while an approved online booking waits for payment. |
| Shared schedule | Website, WhatsApp, phone, walk-in bookings, maintenance, and closures must use the same schedule. Staff enter bookings received outside the website. |
| Groups | Keep the entire group in one suitable room. No automatic split across rooms. |
| Pricing | Declare whether each package is priced per person or per booking, including the guest coverage. Show the full total before payment. |
| Telegram | Authorized staff can Confirm, Decline, and View a booking. Decline requires a second confirmation. The dashboard remains available. |
| Staff authority | The owner controls which staff may act. Record the individual actor. Telegram group membership alone grants no authority. |
| Customer updates | Show booking status on the website and email acceptance or rejection. |
| Cancellations | Customers contact the spa from the booking page; staff records the cancellation. |
| Rescheduling | Excluded from the first release. Cancel the original and make a new request with fresh availability, approval, pricing, and payment. |
| Refund entitlement | Full refund of money actually collected for spa cancellations, customer cancellations, late cancellations, and recorded no-shows. No separate owner approval. |
| Late payment | Money received for a cancelled booking is refunded. It never reopens the booking. |
| Refund visibility | Track progress until the money is returned. Eligibility or submission is not completion. |
| Manual refund fallback | Authorized staff completes and records a full refund when the provider cannot return it, without another owner approval. Resolve ambiguous automated attempts before paying manually. |

This refund policy means online payment offers convenience, not financial protection against no-shows. An unpaid pay-at-spa booking has no money to refund. A completed treatment is not automatically eligible for a cancellation refund.

## 3. Proposed customer and staff flow

1. The customer selects a treatment, any extension, guest count, date, and time. The website shows suitable rooms as available or unavailable for that selection, without identifying other customers. Availability is a current observation, not a reservation.
2. The server checks the selection and calculates the total. The customer chooses online payment or pay-at-spa and submits a pending request. Pending requests do not reserve rooms. Explain that the appointment is awaiting staff approval.
3. Staff receives a Telegram notification and sees the same request in the dashboard. Staff checks operational requirements that the software does not yet schedule, particularly therapist availability and treatments requiring two therapists.
4. On approval, one database transaction rechecks availability, allocates a room, records the actor and pricing snapshots, and creates durable work for notifications and any payment session. A conflict leaves the request unapproved and explains why; staff must not approve first and try to find a room later.
5. For pay-at-spa, the booking becomes confirmed. For online payment, it becomes awaiting payment. A hosted checkout link appears on the booking page when ready and is emailed to the customer. Its deadline is anchored to approval time, not email delivery or page opening.
6. A verified successful payment received within the allowed window confirms the online booking. Verified failure or expiry without payment cancels it and releases the reservation. Uncertain outcomes follow the reconciliation rules below.
7. Staff records arrival/payment at the spa, completion, cancellation, or no-show through the dashboard. Eligible refunds are created by policy without a second owner-approval step.

Requests received outside the website follow the same quote and allocation rules. Staff can create and approve one in the same operation after checking suitability. Store the source channel and staff creator separately from the customer. A customer account is optional for staff-entered bookings; never substitute the staff account or attach an existing account merely because an entered email matches. For a customer without email, staff provides confirmation through the contact method already in use. Automated WhatsApp integration is outside this release.

## 4. Room availability and allocation

### Inventory and intervals

Each room needs a name, active status, capacity, treatment compatibility, and cleaning time. Room type is useful only when the real rooms differ in ways relevant to customers. The owner supplies these facts; do not invent inventory from photographs or existing marketing copy.

For the initial release, all guests on a booking receive the same selected treatment and extension simultaneously in one room. Different treatments per guest and sequential group appointments are outside scope. Keep the existing guest limit of four unless verified room and treatment data justify changing it.

The reserved interval is `[appointment start, treatment end + cleaning time)`. Two intervals conflict when `existing.start < candidate.end && existing.end > candidate.start`. Adjacent reservations are allowed only after cleaning has finished. Use UTC instants for comparisons and Asia/Jakarta for booking input and display. Snapshot duration and cleaning time so later catalog or room edits do not move existing appointments.

Recommended allocation: select the smallest compatible active room that fits the group; break ties by stable room ID. Customers choose an available time and, where meaningful, a room type. Staff sees the assigned room. A displayed room is not guaranteed until approval commits.

Maintenance and closures block the same allocation system. Room edits, deactivation, or new blocks must not silently invalidate existing reservations. Show conflicts and reject the conflicting change until staff resolves the affected bookings through the explicit cancellation/refund process. Cancellation after service has started does not immediately make the physical room available: staff records the remaining occupancy/cleaning block.

### Concurrency contract

Locking the booking being approved does not prevent a different booking from taking the same room. Every schedule mutation must use one shared allocation boundary: approvals from either UI, staff-entered appointments, releases, room blocks, and inventory changes.

Recommended implementation for this single-spa application: a database schedule-guard row. At the beginning of each schedule-changing transaction, perform an actual update on that row before reading conflicts. This serializes writers across web workers and background workers. Use a consistent lock order: schedule guard, booking, reservation, then related payment/refund records. Keep the transaction short, perform no provider or notification calls under the lock, and retry transient lock/deadlock failures within a bounded policy.

The implementation plan must verify this strategy against the actual production database and isolation level. SQLite in-memory tests do not establish concurrent correctness. The repository defaults to SQLite but the deployed database was not inspected. If the chosen database needs a different lock primitive, preserve the same atomic contract and test it using separate real database connections.

Therapist allocation is a documented limitation: room availability alone does not promise that the spa can perform the service. Staff approval remains the check for therapist count and skills, including group bookings and the existing double-therapist treatment.

## 5. Price contract

Current quotes add the base price and extension once; guest count does not multiply that total. Existing tests explicitly preserve this behavior. Do not switch every existing package to per-person pricing by inference.

Recommended first-release model:

- `per_person`: `(base price + extension price) × guest count`.
- `per_booking`: `base price + extension price`, with an explicit minimum/maximum guest count covered by that price.
- An extension follows the package's price basis. Mixed charging bases within one package are outside this release.
- Store amounts as integer IDR. Snapshot the basis, unit amounts, guest coverage, total, treatment duration, and catalog identifiers on submission.
- A submitted quote is the amount staff approves and the provider receives. Later menu edits do not silently reprice it. A material change requires cancellation and a new request.

The server remains authoritative. A client-supplied expected total is a stale-quote check, not a trusted amount. Extend the existing submission fingerprint so payment preference and other new business inputs participate in idempotency. Same key and same request return the original result; same key with changed inputs is rejected.

Before enabling the new flow for a package, the owner must verify its pricing basis and guest coverage. Historical booking totals stay unchanged; neither guest multiplication nor a paid status may be inferred during migration.

## 6. Booking, payment, and refund states

### Booking state

| State | Meaning and permitted next steps |
| --- | --- |
| `pending` | Submitted, no room reserved. Approval produces `confirmed` for pay-at-spa or `awaiting_payment` for online. Decline/cancellation produces `cancelled`. |
| `awaiting_payment` | Approved online booking with a room reservation and payment deadline. Verified timely payment produces `confirmed`; failure, expiry, or staff cancellation produces `cancelled`, with financial follow-up where needed. |
| `confirmed` | Approved pay-at-spa booking or paid online booking. May become `completed`, `cancelled`, or `no_show`. |
| `completed` | Staff records that the treatment was delivered. Terminal in this release. |
| `cancelled` | No appointment promise remains. Terminal even if money arrives later. Store a structured reason and actor. |
| `no_show` | Staff explicitly records that the customer did not attend and no service was delivered. Terminal; collected money becomes fully refundable. |

Recommended no-show rule: permit the action after the scheduled treatment end, require staff confirmation that no treatment was delivered, and never infer a no-show solely from the clock. Completion and no-show must be mutually exclusive under concurrent requests.

Keep payment state separate: not started, creating, pending, succeeded, failed, expired/cancelled, or outcome unknown. Keep refund state separate: required, queued, processing, action required, succeeded, or failed. Provider-specific raw states belong in the adapter, alongside normalized facts and references. A booking can be cancelled while its refund is processing or its payment is still under investigation.

### Deadlines and operational settings

The online deadline is `min(approved_at + 60 minutes, appointment_start)`. A staff approval must leave enough time for the enabled provider/channel to create and complete checkout. Do not create a session with a deadline the provider cannot honor.

The owner must configure the booking horizon, minimum lead time, review hours, review response target, and minimum checkout window before public launch. These are launch inputs, not invented facts about the spa. Recommended behavior: show when staff is expected to review; stop offering online requests that cannot leave the minimum checkout window; alert staff when the review target is missed. A response target alone does not cancel a request. Automatically cancel still-pending online requests when the minimum checkout window can no longer be met, and other still-pending requests at appointment start, with an explicit expiry reason and customer update.

These settings do not shorten the approved 60-minute maximum after approval, except for the agreed cap at appointment start. The acceptance tests must cover the configured boundaries and provider minimums.

## 7. Payment processing and uncertain outcomes

Use hosted checkout so card/payment credentials do not pass through the application. Payment session creation is durable asynchronous work recorded with approval. Use a stable merchant reference and provider idempotency key where supported. A lost response must trigger lookup/reconciliation of that reference before creating another charge opportunity. Allow one active payment session per booking; separate provider transaction records can exist within it for reconciliation.

The customer redirect is navigation, not evidence of payment. Authenticate provider callbacks, validate merchant identity, booking/session reference, currency and exact amount, and deduplicate provider events. Confirm status with the provider when required by its contract or when events conflict. Never log secrets, complete payment credentials, or unnecessarily retain raw personal data.

Key outcomes:

| Event | Required behavior |
| --- | --- |
| Customer closes checkout, loses connection, or sees a generic error | Keep the known provider state; reconcile. Do not label it failed or release a room based on browser behavior. |
| A verified definitive payment failure | Cancel the booking, prevent further checkout use where possible, and release the room. A new offline booking needs fresh availability and approval. |
| Timely successful payment, webhook arrives late | Use verified payment time. Confirm only if the booking is still eligible; a terminal cancellation never reopens. |
| Successful payment after the deadline | Cancel if not already cancelled and create a full-refund obligation. Do not quietly extend the deadline. |
| Deadline reached while outcome is unknown | Disable further payment initiation, request expiry/cancellation as supported, and reconcile the underlying transaction. Mark staff attention; do not claim it is safely unpaid. |
| Money arrives after cancellation or no-show | Record collection and create the full-refund obligation. Do not restore the appointment. |
| Unexpected amount, currency, duplicate collection, or unmapped reference | Quarantine for reconciliation and staff attention. Never confirm a booking using inconsistent funds. |

While an outcome is unknown, retain its room reservation until the provider establishes a releasable outcome, or the reserved interval ends. If staff cancels the appointment during this period, the booking is cancelled but the reservation can remain temporarily blocked for financial reconciliation. This deliberately favors avoiding conflicting promises over selling the slot again immediately. The block must not extend indefinitely beyond its original interval.

If a payment is still uncertain when the customer arrives, staff sees an explicit warning. They must not take a second payment or silently change this booking to pay-at-spa. They can resolve it with the provider or cancel it and follow the refund/reconciliation procedure. Any new offline request is a separate booking and cannot bypass an unresolved room block.

The scheduled expiry worker and webhook handler must use the same locked transition path. A timer firing alone does not prove nonpayment. Persist deadline, verified payment time, provider references, and reconciliation history so races can be explained afterward.

## 8. Refund policy and execution

The policy grants the customer the full amount actually collected, less amounts already returned for the same payment. The spa absorbs any provider fees that are not returned. Cancellation/refund eligibility is recorded atomically with the booking action. The monetary refund runs afterward as durable work. Staff does not need another owner approval to execute an eligible refund.

Use one refund obligation per collected payment, with unique constraints and idempotent requests. Cancellation, late settlement, and repeated provider events attach evidence to that same obligation rather than creating competing refunds. Multiple execution attempts must share the outstanding refundable balance; returned plus in-flight amounts must never exceed the collection. Duplicate callbacks, repeated staff clicks, and worker retries must not return money twice. A refund's progress must be visible independently of booking status, with actor, reason, amount, provider reference or staff payout evidence, and last attempted/confirmed times.

Approved manual fallback: when the original payment channel cannot accept a refund, its refund window has closed, or the provider requires intervention, staff completes and records a full refund through an agreed manual method. This keeps the customer entitlement intact without requiring the owner to approve each case. Staff must first resolve any ambiguous automated refund attempt before sending money manually. Do not introduce an automated bank-transfer payout product as an unapproved substitute.

Record manual refund destination details only through an appropriate private staff process. Do not collect account details in the Telegram group. Limit access and retention, record evidence, and reconcile the payout. A failure to automate a refund changes its status to action required, not refused or completed. An ordinary pending provider refund should remain processing, rather than trigger a second manual payout.

For pay-at-spa, record any money actually received, payment method, amount, actor, and receipt/reference. Refund that recorded collection through the corresponding staff process when eligible. Do not create a fictional gateway refund for an unpaid booking. Completion of service and settlement of money are separate facts; the dashboard must expose discrepancies.

## 9. Payment provider recommendation and costs

Recommend evaluating Midtrans Snap first, with Xendit Payment Sessions as an alternative. This is a shortlist, not a final provider selection or a claim of merchant approval. Enable only channels whose expiry, status, refund, and reconciliation behavior have been verified for the actual merchant account. Start with a narrow set of supported methods.

| Provider | Relevant verified constraint | Design consequence |
| --- | --- | --- |
| Midtrans | Its refund table lists bank transfers without gateway refund support and different time limits across other methods. GoPay QRIS off-us is listed with a seven-day refund window. The documentation describes manual merchant refunds for unsupported/expired cases. | Full-refund entitlement needs a staff fallback, or channel and advance-booking restrictions that the owner accepts. [Refund support](https://docs.midtrans.com/docs/what-payment-method-that-have-refund-feature) |
| Midtrans Snap | Checkout expiry and the underlying payment transaction are not interchangeable. Setting the approval-based start time matters; page expiry alone does not invalidate previously created payment instructions. | Configure the deadline explicitly and reconcile the underlying transaction before releasing a room as unpaid. [Snap expiry](https://docs.midtrans.com/reference/expire-a-snap-session) |
| Xendit | QRIS documentation lists a seven-day refund validity and issuer restrictions; BCA virtual account documentation lists no refund support. | It does not eliminate the need for the same fallback. [QRIS](https://docs.xendit.co/docs/qris), [BCA virtual account](https://docs.xendit.co/docs/bca-virtual-account) |
| Xendit | The published policy effective 1 October 2026 lists refund-attempt fees, retained original payment/processing fees, and a USD 50-equivalent monthly minimum in the specified low-use/dormant cases, subject to contract terms. | Obtain the merchant's actual fee schedule before selection. Do not describe this as a free integration or quote a universal per-payment cost. [Pricing policy](https://help.xendit.co/hc/en-us/articles/59516240127129-Xendit-Pricing-Policy) |

Use current hosted checkout APIs: [Midtrans Snap](https://docs.midtrans.com/docs/snap-snap-integration-guide) or [Xendit Payment Sessions](https://docs.xendit.co/docs/migrate-to-payment-session). Provider refund endpoints and account balances must also support the chosen process: [Midtrans refund API](https://docs.midtrans.com/reference/refund-transaction), [Xendit refund API](https://docs.xendit.co/apidocs/refund-payment-request). A refund entitlement is not proof that sufficient merchant balance or instant payout is available.

Sources were checked during design research on 22 October 2026. Published support tables and the actual merchant agreement can differ; validate enabled channels, fees, refund windows, balance requirements, and checkout expiry in sandbox and merchant onboarding before implementation is declared ready.

Email also needs a real delivery service and a verified sending domain. The repository defaults to Laravel's log mailer; that does not send customer email. Keep Laravel's mail abstraction and select the transport after verifying volume and current pricing. No paid email or payment account has been created by this design work.

## 10. Telegram authorization and interaction

Telegram is a staff interface to the same application actions used by the dashboard. It must not have separate approval, pricing, or allocation logic.

Recommended role model: introduce an owner role while retaining existing admins as staff. Provision the first owner explicitly through an operator-controlled procedure; do not promote every current admin. Protect the last owner and preserve recovery access. Owners grant/revoke staff action permissions and Telegram links.

Link a Telegram numeric user ID to one verified staff account using a short-lived, one-use linking challenge initiated from an authenticated staff session. Persist the linkage and its audit history. Every callback must recheck the linked account, current role/permissions, permitted chat, booking state, and action token. Revoked staff cannot act through old messages. Telegram usernames and group membership are not authentication.

Persist chat and message IDs for status updates. Use short opaque callback tokens bound to booking, action, and expected state, within Telegram's documented payload limit. First Decline opens a second confirmation bound to the acting staff member; the final action rechecks all conditions. Confirm rechecks availability atomically. Duplicate or stale actions receive a harmless response showing the current outcome.

Authenticate the webhook using Telegram's supported secret header and deduplicate updates. Transport authentication does not replace staff authorization. Acknowledge callbacks promptly, then update the message from committed state. Treat Telegram's network address as delivery metadata, never the human staff member's IP. Store actor identity and channel in the existing audit trail.

The first release exposes Confirm, Decline, and View booking only. Cancellation after confirmation, no-show, financial records, and refund handling stay in the dashboard. View links still require normal application authorization. Telegram delivery failure leaves dashboard operations available.

API references: [callback queries](https://core.telegram.org/bots/api#callbackquery) and [webhook secrets](https://core.telegram.org/bots/api#setwebhook).

## 11. Durable work and customer communication

The existing booking action dispatches a Telegram job inside its transaction with after-commit behavior. This avoids sending for a rolled-back booking, but a queue insertion failure after commit can still make the request appear failed after the booking was saved. Replaying the submission does not necessarily recover missing delivery.

Recommended replacement: write an outbox entry in the same transaction as the business change. A publisher/worker delivers it with bounded retries and recorded outcomes. Committed bookings remain successful even when queue delivery is down. A recovery job picks up undelivered entries. Notification delivery is at least once; financial and booking actions must be idempotent even if a message is duplicated.

Use an authenticated provider-event inbox with a uniqueness key, processing status, and retry path. Verify and durably store the event before acknowledging it as accepted. Sanitize or minimize retained payloads. Payment creation, reconciliation, refunds, notification delivery, and Telegram message edits each need durable intent and visible failures.

Customer updates cover request received, approval/payment link ready, confirmation, decline/cancellation, payment expiry/failure, and refund progress. Never send a paid/confirmed email before the corresponding verified state is committed. An unavailable mail service does not hide website status or extend a payment deadline. Staff needs an actionable list of overdue reviews, unknown payments, failed deliveries, and refunds requiring attention.

Schedule expiry, pending-request expiry, reconciliation, outbox recovery, and refund follow-up jobs. Deploy and monitor both the queue worker and scheduler. Provider and notification outages must be recoverable after process restart, without relying on one web request remaining alive.

## 12. Backend boundaries and data additions

These are responsibilities for the implementation plan, not a mandatory one-file-per-row layout.

| Existing boundary | Planned responsibility |
| --- | --- |
| `CreateBooking`, `StoreBookingRequest`, `BookingQuoteResolver`, `ResolvedBookingQuote` | Keep trusted quotes, validation, immutable snapshots, and submission replay; add payment preference, verified pricing basis, availability observation, and outbox writes. |
| `TransitionBookingStatus`, `BookingStatus`, `BookingStatusChange` | Refactor into explicit approval/cancellation/completion/no-show actions sharing authorization, atomic transitions, and audit records. A generic status patch must not bypass room or money rules. |
| `BookingPolicy`, admin middleware, user roles | Add owner/staff capabilities and enforce them inside reusable action boundaries as well as HTTP routes. |
| Room/schedule module | Rooms, treatment compatibility, blocks, reservations, allocation, schedule guard, and public availability queries. |
| Payment module | Hosted-checkout adapter, payment sessions/transactions, verified provider-event inbox, expiry, reconciliation, and refund obligations/attempts. |
| `TelegramClient`, formatter, notification job | Add callback handling, linked staff identity, stored message IDs, action tokens, and durable delivery. |
| Booking presenters and customer/admin pages | Expose allowlisted booking, availability, payment, and refund fields with truthful customer labels and staff actions. |
| Staff booking entry | Record customer/contact snapshot, source channel, creator, approved quote, and room allocation through the shared actions. |
| Dashboard | Separate booked value, verified money collected, money refunded, refund obligations, and outstanding on-site amounts. Current confirmed/completed quote sums are not cash revenue. |

Suggested persisted records: rooms and compatible treatments; schedule guard; room blocks/reservations; booking source and payment preference; pricing and allocation snapshots; payment sessions and transactions; refund obligations and attempts; Telegram staff links/messages/action tokens; provider events; and outbox messages. Store a booking's operational status and financial facts separately. Use foreign keys, unique merchant/provider references, and unique event/idempotency constraints.

Do not rely on application checks alone for duplicate prevention. Detailed schema, indexes, adapter signatures, and route names belong in the implementation plan. Financial/audit records must survive customer account deletion without leaving unnecessary personal data exposed. Never cascade-delete transaction history with a user account.

## 13. Migration and release controls

1. Add structures and nullable legacy-compatible fields before enabling behavior. Use explicit feature flags for room enforcement, Telegram actions, and online payments.
2. Preserve historical quote, status, contact, and service snapshots. Legacy bookings are not automatically paid, unpaid, per-person priced, or room-assigned.
3. Have staff review existing future appointments, resolve existing overlaps, and assign rooms before the new availability view becomes authoritative. Conflicts must be reported rather than silently dropping or moving appointments.
4. Populate and verify real room data and package pricing. Enter existing phone/WhatsApp/walk-in reservations and closures into the shared schedule.
5. Establish owner access, linked staff accounts, mail delivery, production database, queue/scheduler supervision, and provider sandbox credentials through the normal secret-management process.
6. Rehearse success, uncertainty, cancellation, and refund recovery before enabling real checkout. Keep pay-at-spa operational if new online checkout needs to be disabled.
7. Update customer FAQ, booking terms, staff instructions, and `PRODUCT.md` to match the approved release. Remove the old rescheduling promise and any stale refund cutoff.

Disabling new online payments must not disable callbacks, reconciliation, or refunds for existing transactions. Rollback must preserve financial records and outstanding work. Never restore a database snapshot over newly accepted real payments as a routine deployment rollback.

## 14. Delivery order

| Stage | Deliverable and exit condition |
| --- | --- |
| A. Baseline safety and delivery | Fix audit findings relevant to authentication/recovery, malformed inputs, content/catalog integrity, and booking delivery. Restore reliable test configuration. Introduce durable work and visible failures. |
| B. Pricing and shared schedule | Verify package price basis; implement rooms, blocks, atomic approval, manual bookings, owner/staff permissions, and migration review. Prove concurrent requests cannot allocate overlapping occupancy. Launch-ready pay-at-spa flow. |
| C. Telegram and customer updates | Secure staff linking and Confirm/Decline/View callbacks using the same actions; email/status updates; revoked/stale/duplicate action coverage; dashboard fallback. |
| D. Online money flow | Implement the selected hosted provider, exact deadlines, authenticated events, unknown-outcome reconciliation, full refunds and agreed fallback, receipt recording, and corrected financial reporting. |
| E. Operational rehearsal | Exercise real database concurrency and provider sandbox flows, worker outages, recovery, staff procedures, and migration/rollback controls. Verify customer terms and required configuration before live activation. |

This is a delivery roadmap. After written-design approval, create detailed implementation plans with file ownership, test-first tasks, dependencies, and focused review checkpoints. Do not treat the roadmap as authorization to deploy or open a paid service account.

## 15. Acceptance and verification requirements

Tests must cover externally meaningful behavior, including:

- Two simultaneous approvals, including one from Telegram and one from the dashboard, cannot reserve overlapping intervals in the same room. Another compatible room may satisfy the second request.
- Walk-in entry, a room closure, and website approval obey the same conflict rule. Cleaning boundaries, room capacity, group suitability, and local-time boundaries are checked.
- Stale availability cannot bypass approval checks. Pending requests do not reserve inventory. Changes to room/catalog configuration do not rewrite existing snapshots.
- Per-person and per-booking totals, extensions, guest bounds, stale quotes, submission replays, and historical totals remain correct.
- Owner provisioning/revocation, an unlinked or unauthorized Telegram user, forwarded messages, repeated callbacks, and stale status all preserve authorization and audit integrity.
- Losing a provider create-session response does not create duplicate payment opportunities. Redirect spoofing, wrong amounts/currencies, duplicate events, out-of-order events, and unknown provider states cannot mark a booking paid incorrectly.
- Expiry racing with timely settlement yields one consistent outcome. Late settlement after cancellation creates a refund without reopening or double-booking the appointment.
- Provider outages at the deadline/appointment start retain explicit uncertainty and do not trigger a second charge or silent conversion to pay-at-spa.
- All agreed cancellation/no-show categories refund the full collected amount. There is no 24-hour eligibility cutoff. Unpaid on-site bookings generate no fictitious refund.
- Duplicate refund events, lost refund responses, and manual/automated refund races cannot produce double repayment. Staff intervention is visible; success needs actual evidence.
- Queue insertion failure, worker restart, and email/Telegram outages do not erase committed bookings or leave payment/refund work unrecoverable.
- Existing future bookings with overlaps are flagged during migration. Disabling new checkout still permits existing callback and refund processing.

Use unit tests for price, interval, and policy rules; integration tests for authorization, transactions, idempotency, outbox/inbox, and provider adapters; and E2E tests for customer/staff journeys. Add separate-connection concurrency tests against the production database engine. Fake external services for automated local tests; use explicit sandbox accounts for provider acceptance checks. Never run tests against production customers, Telegram groups, or payment accounts.

Follow the repository's coverage requirement for changed behavior and report any coverage-tool limitation rather than claiming an unmeasured percentage. Run the existing PHP/frontend/type/build checks and focused E2E suites during implementation. No product tests were rerun for this document-only task.

## 16. Decisions and inputs still needed

| Item | Recommendation or required evidence | Timing |
| --- | --- | --- |
| Refund fallback | Approved: staff-executed, recorded full refunds when the original provider cannot return funds, without per-case owner approval. Resolve ambiguous automatic attempts first. | Implement with the payment/refund workflow; define the private staff procedure before launch. |
| Provider and channels | Evaluate Midtrans Snap first; select only after the actual account's channels, fees, refund rules, expiry behavior, and funding requirements are checked. | Before provider-specific implementation. |
| Room data | Names/types, capacity, compatible treatments, cleaning duration, and known closures. | Before inventory configuration and availability launch. |
| Pricing | Per-person/per-booking basis, guest coverage, extension compatibility, and authoritative prices. | Before pricing migration/new-flow activation. |
| Operating limits | Review hours/target, booking horizon, minimum booking lead time, and minimum online checkout window. | Configured and communicated before launch. |
| Operations | Production database engine, worker/scheduler hosting, mail domain/transport, owner identity, and staff refund procedure. | Before integration/release verification. |

Missing factual configuration does not prevent writing the implementation plan around validated configuration inputs. It must prevent pretending the business is ready to launch with invented values.

## 17. Review record

Business interview consolidated into this draft on 22 October 2026. The only workspace change made by this design task is this document; the pre-existing audit and working-tree changes were preserved.

A fresh read-only builder review on 22 October returned "nothing important left" for planning, apart from the explicitly identified business confirmation and launch inputs. Self-review tightened refund deduplication to one obligation per collected payment, so cancellation and late-payment events cannot create competing refunds. This review is a design check, not implementation verification or a production-readiness certification.

The user subsequently approved the full written design and manual-refund fallback on 22 October 2026. The next deliverable is the implementation plan. This approval does not select a merchant contract, supply missing room/menu facts, or authorize live deployment.
