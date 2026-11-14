# Booking operations guide: the pay-at-spa release

For the owner and the staff who run bookings, and for whoever deploys the site. Each procedure names the screen or command to use and what shows that it is done. Setup details live in the [README](../../../README.md); this guide links to them instead of repeating them.

## 1. What this release does

- Customers request a time on the website. Nothing is reserved until staff approve the request.
- Staff approve or decline in the admin area, or in Telegram once Telegram actions are switched on.
- **Every booking is paid at the spa.** The website records no payments; online payment comes in a later release.
- **There is no rescheduling.** A booking is cancelled, and another time is a new booking.
- A request that nobody decides **expires automatically at its appointment start time**.
- Each feature has its own switch, and each is off until you switch it on:
  - Telegram notifications;
  - Telegram actions;
  - customer status emails;
  - room scheduling.

## 2. Who can do what

| Person | How they get access | What they can do |
|---|---|---|
| Owner | A verified account, then `php artisan owner:grant <email>` | Everything below, plus: give and remove booking management and Telegram links (**Staff**, `/admin/staff`), booking settings (`/admin/settings/booking`), rooms, and confirming package pricing |
| Staff with booking management | A verified account, then `php artisan admin:grant <email>`, then the owner selects **Grant booking management** on the Staff page | Approve, decline, cancel, complete and record no-shows; enter outside bookings; maintenance periods; plus everything an admin can do |
| Admin without booking management | A verified account, then `php artisan admin:grant <email>` | See every booking, with the guest's contact details; edit the catalog (including prices) and the homepage text (**Content**), including the phone number customers use to reach the spa. No booking decisions |

**Admin access is not view-only.** Give it only to people you trust with prices and the public pages. Only a command removes it: `php artisan admin:revoke <email>` (see 4.6).

**A price change needs the owner again.** Changing a package's price, by anyone, clears the owner's pricing confirmation: the package's **Package pricing** shows "Not verified". With room scheduling on, new requests for that package are refused until the owner confirms the price again on the package's page under **Catalog**.

The software records who made each decision. Membership of the Telegram group gives no authority on its own.

## 3. Switching the release on

Do this once, in this order. For each switch, set `true` to turn it on and `false` to turn it off (`1`, `yes` and `on` also mean on; anything else means off). After changing a switch, run `php artisan config:cache` and `php artisan queue:restart`, and restart the web processes.

1. **Deploy the release.** This is the one step you can't undo (section 6), so prepare first:
   1. Take a database backup.
   2. Do [README: Before deploying this version](../../../README.md#before-deploying-this-version). This version sets every MySQL session to UTC (`DB_TIMEZONE`, default `+00:00`). On a server with another offset and existing data, set `DB_TIMEZONE` **before** the code goes live, or stored times read back shifted.
   3. Deploy as [README: Deploying a release that changes notifications](../../../README.md#deploying-a-release-that-changes-notifications) says: run `php artisan migrate --force` before restarting any worker, then refresh the configuration and restart the web processes and the workers together.

   Then keep the scheduler and a supervised queue worker running ([README: Telegram booking notifications](../../../README.md#telegram-booking-notifications-outbox)). They are needed even with every switch off: the scheduler also expires unanswered requests.
   - **Done when:** `php artisan migrate:status` shows no pending migration.
2. **Give people access** (section 2).
   - **Done when:**
     - the Staff page shows booking management for each person who decides bookings;
     - after one of them approves a test booking, its **Notifications** section (on its admin page) shows **Processed** and **Not sent: customer emails are switched off** within about a minute, not **Waiting to send**. That proves the scheduler and the worker run. Then cancel the test booking.
3. **Telegram notifications:** follow [README: Activate on a deployment](../../../README.md#activate-on-a-deployment).
   - **Done when:** a controlled booking's message reaches the staff group, and its delivery shows **Processed**.
4. **Telegram linking and actions:** follow [README: Telegram staff linking](../../../README.md#telegram-staff-linking-webhook) and [Before switching actions on](../../../README.md#before-switching-actions-on).
   - **Done when:** every check in step 7 of "Before switching actions on" passes.
5. **Customer emails:** follow [README: Before switching emails on](../../../README.md#before-switching-emails-on).
   - **Done when:** the test email arrives, in the right language, and the booking's Notifications section shows **Sent**.
6. **Room scheduling, only after readiness.**
   1. **First put every existing outside booking into the website.** The readiness check only sees bookings the website already has; a room held by a phone, WhatsApp or paper booking would otherwise look free and be given away twice.
      - Enter every future phone, WhatsApp and walk-in booking as in 4.4.
      - Enter every planned closure as a maintenance period (`/admin/room-blocks`, **Add maintenance period**).
   2. Then follow [README: Before switching scheduling on](../../../README.md#before-switching-scheduling-on).
   - `php artisan bookings:readiness` only reports; it never stops you from switching scheduling on. **Do not set `BOOKING_SCHEDULING_ENABLED=true` until it prints `Booking readiness: ready.`**
   - **Done when:** a controlled booking approved in the admin area shows an assigned room on its page and in **Schedule** (`/admin/schedule`). Then cancel it.

## 4. Daily work

### 4.1 A new request arrives

**Where:** the Telegram message, or **Bookings** (`/admin/bookings`), filtered to Pending.

1. **Check therapists first.** Make sure there are therapists for the treatment and for every guest; the software does not schedule therapists.
2. **To approve:**
   - in Telegram, press **Confirm: therapists checked for all guests**;
   - or open the booking and choose **Confirmed**.
   With room scheduling on, the dashboard also asks you to tick "I confirm suitable therapists are available…", and approval reserves a room. If no suitable room is free, the approval is refused and the request stays pending.
3. **To decline:**
   - in Telegram, press **Decline**, then **Yes, decline** within 5 minutes (only you can press it);
   - or open the booking and choose **Cancelled**, with an optional reason.
   The reason is for staff only: the customer never sees it.
4. **Decide before the start time.** An undecided request expires at its start time. Its history then shows "System (expired automatically)", and the customer gets the expiry email (when emails are on). An expired request can't be reopened (see 5.2).

**Done when:**
- the booking shows **Confirmed** or **Cancelled**;
- when Telegram notifications are on, the Telegram message shows the new status with no buttons;
- the Notifications section shows the customer email as **Sent**, or **Not sent:** with a reason.

### 4.2 A customer asks to cancel

Customers can't cancel online. Their booking page tells them to contact the spa, and links to WhatsApp when the spa's number is set.

1. Open the booking.
2. Choose **Cancelled**.
3. Record a reason such as "Guest asked by WhatsApp".

**Done when:**
- the booking shows **Cancelled**;
- the customer's booking page says "This booking was cancelled";
- the email shows **Sent**, when emails are on.

### 4.3 A customer wants another time

Bookings can't be moved.
1. The customer makes a new request, or you enter it yourself (4.4).
2. Then cancel the old booking (4.2).

**With room scheduling on:** if the new time overlaps the old booking, cancel the old booking **first**. The old booking still holds its room, so the new request is refused if no other suitable room is free.

**Done when:** the new booking is **Confirmed** and the old one is **Cancelled**.

### 4.4 Walk-in, phone and WhatsApp bookings

**Where:** **Bookings**, then **New booking** (`/admin/bookings/create`).

1. Choose the **Source**.
2. Enter the guest's name, and a phone number or an email (at least one).
3. Choose the package, treatment type, duration, guests, date and start time.
4. To approve in the same step, tick **Approve now** and press **Save and approve**.
   - With room scheduling on, also tick the therapist confirmation. The page shows which rooms are free at each time.
   - With room scheduling on, the page offers every time from the next half-hour, without the minimum notice customers must give. For a walk-in, choose the next half-hour.

Never enter your own account or email for a guest: staff-entered bookings have no customer account.

A guest without an email gets no status emails: tell them by phone or WhatsApp. When emails are on, the booking's Notifications section reminds you.

**Done when:** the booking page shows the source with "entered by" and your name, and the status you chose.

### 4.5 The guest arrives, or doesn't

- **Payment** is taken at the spa as before (cash, card or QRIS). Nothing about payment is recorded in the website in this release.
- **Completed:** choose it once the appointment has started; the website refuses it earlier.
- **No-show:** choose it only after the treatment's scheduled end; the website refuses it earlier. The customer gets the no-show email, when emails are on.

**Done when:** the booking shows **Completed** or **No-show**.

### 4.6 A staff member leaves or changes role

**Where:** the Staff page (owner only), then the server.

1. Select **Revoke booking management**.
2. Select **Remove Telegram link**.
3. Remove them from the Telegram staff group.
4. **If they leave,** remove their admin access too, which only a command can do: `php artisan admin:revoke <email>` (whoever deploys runs it). Without it they can still sign in, read every booking's contact details and edit the catalog and homepage.

A Telegram press they made before this, but that is processed after it, is refused and changes nothing.

**Done when:**
- for someone who stays without booking work: the Staff page shows neither booking management nor a Telegram link for them;
- for someone who leaves: they no longer appear on the Staff page.

### 4.7 A customer deletes their account

Their bookings stay, with the name, email, phone number and notes saved on each booking. Status emails still go to the saved email address, without the "View your booking" link. Nothing for staff to do.

## 5. When something goes wrong

### 5.1 A notification is stuck or failed

**Where:** the booking's **Notifications** section. [README: Reading the Notifications section](../../../README.md#reading-the-notifications-section) explains each label.

- **Waiting to send for more than a minute:** the scheduler or the queue worker is not running. Restart it; the delivery is then sent without anything else to do.
- **Failed, will retry:** a temporary failure; the next attempt is already scheduled.
- **Failed:** this needs whoever deploys, because the admin page shows no delivery IDs.
  1. Fix the cause (usually configuration).
  2. Run `php artisan queue:restart`.
  3. Requeue the delivery: `php artisan outbox:requeue <id>`. [README: Retries and failures](../../../README.md#retries-and-failures) shows how to find the IDs.

**Done when:** the label shows **Sent** or **Processed**.

### 5.2 A request expired that should have been approved

An expired request can't be reopened. Ask the customer to request again, or enter the booking yourself (4.4) with **Approve now**.

### 5.3 A Telegram button seemed to do nothing

An answer takes 10 to 25 seconds, and Telegram drops an answer that arrives too late. The press was still handled: check the message or the dashboard.

If the message shows an old status, it is an older copy of the same message. Use the newest copy or the dashboard, and delete the old copy from the chat.

### 5.4 Room scheduling causes trouble

Switch it off ([README: Switching scheduling off again](../../../README.md#switching-scheduling-off-again)). Room reservations already made stay in place and do no harm.

Before switching it on again, follow the README's steps 3 to 5 again. Bookings approved while it was off hold no room. Until `bookings:reconcile-rooms` assigns them one, approving a booking at an overlapping time is refused with "A confirmed booking at this time has no room yet".

## 6. Never roll back, never restore over new bookings

- **Never run `php artisan migrate:rollback` on the live site** once this release has run.
  - Once a request has expired, the booking lifecycle migration can't be rolled back at all: its system history rows have no staff name.
- **Never restore a database backup over a database that has received real bookings since the backup.** Those bookings would be lost.
- **To back out a feature, switch it off** (section 3). Know what that drops:
  - switching Telegram notifications off drops messages that are still waiting;
  - while customer emails are off, notices are dropped, not paused ([README: Retries and failures](../../../README.md#retries-and-failures)).
- **Running the previous code on the new database has not been tested.** Fix forward instead.

## 7. Not in this release

- Online payment, payment records and refunds.
- Therapist scheduling: staff check therapists by hand.
- Rescheduling.
- Automated WhatsApp messages.
