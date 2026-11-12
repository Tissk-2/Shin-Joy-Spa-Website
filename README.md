# Shin Joy Spa

Bilingual (English/Indonesian) spa website built with Laravel, Inertia, and Vue 3:
a public service catalogue, customer accounts with email verification, online
booking, and an admin panel for bookings and catalogue management.

Running bookings day to day, switching the release on and recovering from
problems: see the [booking operations guide](docs/superpowers/plans/2026-10-22-booking-operations.md).

## Requirements

- PHP ^8.3 with the `pdo_sqlite` extension
- Composer
- Node.js 20+ and npm

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

The default `.env.example` uses SQLite and needs no database configuration; the
database file lives at `database/database.sqlite` (create an empty file first if
it does not exist yet). To use MySQL instead, set `DB_CONNECTION=mysql` in `.env`
and fill in the connection values.

Run the app with two terminals:

```bash
php artisan serve   # http://127.0.0.1:8000
npm run dev         # Vite dev server
```

The sample `.env` is for local development. It uses the local URL, permits a
session cookie over local HTTP, and writes email verification links to
`storage/logs/laravel.log` through the `log` mailer. Open the logged verification
link to use booking features with a new account.

For a deployment, set `APP_ENV=production`, the real HTTPS `APP_URL`,
`SESSION_SECURE_COOKIE=true`, and `MAIL_MAILER` plus its credentials for a
delivery transport with a valid `MAIL_FROM_ADDRESS`. Verify that registration
and password-reset emails arrive before accepting customers; both flows require
email delivery.

## Common commands

| Command                                             | Purpose                                                                  |
| --------------------------------------------------- | ------------------------------------------------------------------------ |
| `npm run build`                                     | Production frontend build                                                |
| `npm run dev`                                       | Vite dev server                                                          |
| `npm run lint`                                      | ESLint (with auto-fix)                                                   |
| `npm run format`                                    | Prettier (write)                                                         |
| `npm run types:check`                               | `vue-tsc` type check                                                     |
| `npm run test:frontend`                             | Vitest unit tests                                                        |
| `npm run test:legacy`                               | Node built-in frontend tests                                             |
| `composer test:coverage`                            | PHP tests with coverage (needs Xdebug or PCOV; fails below 80%)          |
| `php artisan test`                                  | Backend feature tests                                                    |
| `php artisan bookings:readiness`                    | Read-only check before switching room scheduling on                      |
| `php artisan bookings:reconcile-rooms`              | List bookings without a room; `--booking= --room= [--apply]` assigns one |
| `php vendor/bin/phpunit -c phpunit.concurrency.xml` | Multi-process race suite (SQLite, or MySQL via `CONCURRENCY_DB_*`)       |
| `npm run test:e2e`                                  | Playwright end-to-end tests                                              |

## Telegram booking notifications (outbox)

When `TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED=true`, a new booking records one
`booking.created` outbox message and one `telegram` delivery in the same
database transaction as the booking. The web request never contacts Telegram.
Delivery then needs three things on the same release and database:

1. **The outbox tables.** Run the migrations before enabling notifications:

   ```bash
   php artisan migrate --force
   ```

2. **The scheduler.** `routes/console.php` runs `outbox:dispatch --limit=100`
   every 10 seconds (with a 5-minute overlap lock). It claims up to 100 due
   deliveries under a 5-minute lease and queues one job per delivery. Run
   Laravel's scheduler from cron:

   ```cron
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   ```

   or keep `php artisan schedule:work` running under a process manager.

   Each `schedule:run` stays alive for its whole minute so it can repeat the
   10-second task. If your host stops cron jobs that run that long, use
   `schedule:work` instead. After a deploy, the previous release's scheduler
   can keep running until the end of that minute. The scheduler does not poll
   for `schedule:pause` or `schedule:interrupt` (`routes/console.php` turns
   that off to spare the cache store), so those commands have no effect.

3. **A queue worker** for the queued delivery jobs:

   ```bash
   php artisan queue:work database --queue=default --sleep=3 --timeout=60
   ```

   Run it under a process manager that restarts it if it exits, and set
   `QUEUE_CONNECTION=database`. Delivery jobs go to the connection's default
   queue (`DB_QUEUE`, `default` unless changed); keep `--queue` in step with it.
   The worker timeout is shorter than the database queue's default
   `retry_after` of 90 seconds.

Set the `TELEGRAM_*` values in the deployment environment. Keep the bot token
and staff chat ID out of Git and logs.

The worker's PHP must trust HTTPS certificates. If the CLI PHP installation
has no CA bundle configured, set `curl.cainfo` to a trusted local certificate
bundle in its `php.ini` or pass it with `php -d curl.cainfo=...` when starting
the worker. Alternatively, set `TELEGRAM_CA_BUNDLE` to a PEM bundle path for
this application's Telegram requests. The bundle must contain trusted CA
certificates; keep machine-specific bundles under `storage/app/private/`.
Do not disable certificate verification.

### Retries and failures

Each delivery has its own state in the `outbox_deliveries` table: `pending`,
`leased`, `retry`, `completed`, `failed` or `skipped`. `skipped` means the
handler deliberately sent nothing, and `last_error` gives the reason: customer
status emails use `skipped: disabled`, `skipped: superseded`,
`skipped: awaiting_payment` or `skipped: no_email`. A skipped delivery is final:
it is never retried and `outbox:requeue` refuses it. A retryable failure (network error, HTTP 408, 429, 5xx, any other
unexpected response, or an unexpected exception) is retried after 60 s, 5 min,
15 min and then 1 hour, up to 5 attempts. An attempt is counted only when a
worker starts the delivery. A permanent failure (missing Telegram
configuration, HTTP 400/401/403/404, a message that cannot be formatted, a
missing booking, an invalid payload, or a handler missing from
`config/outbox.php`) is marked `failed` immediately. If a worker dies
mid-delivery, the next `outbox:dispatch` reclaims
the delivery after its lease expires, so recovery does not depend on new
bookings. Telegram's `retry_after` hint is not used; the fixed schedule above
applies.

`last_error` holds only a sanitized reason such as `retryable: telegram_http`,
`permanent: configuration`, `unexpected: <exception class>` or
`retryable: lease expired after max attempts`. Failure logs contain the
category and, when available, the booking reference and the HTTP status or
Telegram error code. The success log contains the booking reference and the
sent message ID. No log contains the message text or customer details.

Two results of editing the staff message are not failures: Telegram's "message
is not modified" counts as success, and a message that was deleted from the
chat is recorded as deleted and never edited again.

A customer email that the mail server refuses, or that cannot reach it, is
retried as `retryable: mail`. Its log line holds the booking reference, the
event, the exception class and the mail transport's error code (for SMTP, the
server's reply code), never the address or the server's reply text.

**Turning notifications off drops pending messages.** While
`TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED=false`, any delivery that runs is
marked `completed` without sending. Check that no deliveries are `pending`,
`leased` or `retry` before switching the flag off if those messages matter.

**Customer status emails are dropped, not paused, while they are off.** While
`BOOKING_CUSTOMER_NOTICES_ENABLED` is off, every approval, cancellation and
no-show notice that runs is marked `skipped: disabled` and is never sent later.
The switch is read when the delivery runs, not when the decision is made.

To check delivery health, query the table read-only, for example:

```sql
SELECT state, COUNT(*) FROM outbox_deliveries GROUP BY state;
SELECT id, message_id, attempts, last_error, failed_at
  FROM outbox_deliveries WHERE state = 'failed' ORDER BY failed_at DESC;
```

A growing `pending`/`retry` count usually means the scheduler or the worker is
not running. `php artisan queue:failed` only shows delivery jobs that crashed
outright; the outbox still recovers those deliveries when their lease expires.

A `failed` delivery stays visible for investigation. After fixing the cause
(for example the configuration), restart the worker with
`php artisan queue:restart` so it reads the new values, then requeue the
deliveries you checked by ID:

```bash
php artisan outbox:requeue 12 15
```

The command lists each ID with its handler and last error, asks for
confirmation (`--yes` skips it), and resets only `failed` rows to `retry` with
a fresh budget of 5 attempts; the next scheduled `outbox:dispatch` sends them
within seconds. Other states and unknown IDs are reported and left
unchanged, and the command exits non-zero if any requested ID was not
requeued (declining the prompt changes nothing and exits zero). Each requeue
is logged. Delivery is at least once: if Telegram
accepted a message just before a worker failed, a retry or requeue can send it
again. The booking remains saved even if notification delivery fails.

If a server was killed while `outbox:dispatch` was running, its overlap lock
expires after 5 minutes. `php artisan schedule:clear-cache` clears it sooner.

Jobs queued by the previous direct notification code are still accepted: when
a worker processes one with notifications enabled, it records the same outbox
intent instead of sending directly, so it cannot produce a second message for a
booking that already has one.

### Activate on a deployment

1. Create or confirm the bot with [BotFather](https://core.telegram.org/bots/features#creating-a-new-bot).
   Add it to a private group limited to authorized staff and allow it to post.
   Review membership when staff leave. These messages contain customer contact
   details and notes, so apply the business retention policy and do not forward
   them outside the group.
2. Obtain the group chat ID from an update received by that bot. If the group
   uses topics, also obtain the target `message_thread_id`; otherwise leave
   `TELEGRAM_MESSAGE_THREAD_ID` empty. [Telegram's Bot API](https://core.telegram.org/bots/api#sendmessage)
   defines both fields. Keep the bot token and any tokenized API URL out of
   tickets, screenshots, logs, and Git.
3. Set the `TELEGRAM_*` values in the deployment's secret environment.
   Set `APP_URL` to the public site origin so the admin link is correct. Keep
   `TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED=false` until the migrations, the
   scheduler and the supervised worker are all running. Refresh cached
   configuration and restart web and worker processes after changing
   environment values.
4. Enable notifications and make one controlled booking with test contact
   details. Within about a minute, confirm one message reaches the intended
   group or topic, every field and the admin link are correct, and its
   `outbox_deliveries` row is `completed`. Replay the same submission key and
   confirm it produces no second message. In staging, also confirm that a
   Telegram delivery failure leaves the booking successful and shows the
   delivery as `retry` or `failed` with a sanitized `last_error`.

## Telegram staff linking (webhook)

The owner links each staff member's Telegram account from **Staff** in the
admin area (`/admin/staff`):

1. The owner selects **Create Telegram link** for a staff member and sends the
   one-time link to them privately. It works once and expires after 15 minutes.
2. The staff member opens it in Telegram and presses **Start**. The bot replies
   with the last four digits of their Telegram account.
3. The staff page shows the claim with the same four digits. The owner checks
   them with the staff member and selects **Activate**.

Telegram reaches the app through `POST /webhooks/telegram`. Linking alone gives
no booking actions: those also need `TELEGRAM_ACTIONS_ENABLED=true`. See
[Booking actions in Telegram](#booking-actions-in-telegram).

### Set up the webhook

Register the webhook only on a release that includes the update handler
(`telegram.update.received` subscribed in `config/outbox.php`). An older
release stores updates but never processes them.

1. If you still need the staff group's chat ID for booking notifications, get
   it first: once a webhook is set, Telegram's `getUpdates` stops working.
2. In BotFather, keep privacy mode on (`/setprivacy`, then **Enable**), so in
   groups the bot only receives commands and replies to it. Do not make the bot
   a group admin: an admin bot receives every group message whatever the
   privacy setting. A privacy change applies after the bot is re-added to the
   group.
3. Create the webhook secret with `openssl rand -hex 32`. Set
   `TELEGRAM_WEBHOOK_SECRET` to it and `TELEGRAM_BOT_USERNAME` to the bot's
   username without `@`; the staff page uses the username for the one-tap link.
   `TELEGRAM_BOT_TOKEN` must be set too. Refresh cached configuration and
   restart web and worker processes.
4. Register the webhook over HTTPS. These commands read the token and secret
   without echoing them or saving them in shell history:

   ```bash
   printf 'Bot token: '; IFS= read -rs BOT_TOKEN; echo
   printf 'Webhook secret: '; IFS= read -rs WEBHOOK_SECRET; echo
   curl -sS "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
     --data-urlencode "url=https://spa.example.com/webhooks/telegram" \
     --data-urlencode "secret_token=${WEBHOOK_SECRET}" \
     --data-urlencode 'allowed_updates=["message","callback_query"]'
   curl -sS "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo"
   unset BOT_TOKEN WEBHOOK_SECRET
   ```

   Replace `spa.example.com` with the site's domain. `getWebhookInfo` should
   show that URL, a `pending_update_count` near 0 and no `last_error_message`.
   The token and the secret are briefly visible in the process list, so run
   this on a machine you trust.

5. Check with a test staff account: create a link, open it, and confirm that
   the reply arrives within about 20 seconds, that its four digits match the
   staff page, and that activation works.

### How the endpoint answers

| Response | Meaning                                                                                                                                                |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 200      | Stored, already stored, or authenticated but unusable (logged as `telegram.webhook.rejected` with a reason)                                            |
| 403      | Missing or wrong secret header. Nothing is stored or logged                                                                                            |
| 429      | More than 300 authenticated requests in a minute; Telegram retries                                                                                     |
| 503      | `TELEGRAM_WEBHOOK_SECRET` or `TELEGRAM_BOT_TOKEN` missing or invalid, or the database write failed (`telegram.webhook.store_failed`); Telegram retries |

The secret must be 32 to 256 characters of letters, digits, `_` and `-`.
To rotate it, set the new value, refresh configuration and run `setWebhook`
again with it. To stop receiving updates, call `deleteWebhook`.

### Processing, replies and recovery

Each update is stored once per bot and `update_id`. The outbox then processes
it like a booking notification: `outbox:dispatch` runs every 10 seconds, the
worker runs the `telegram.update.received` delivery, and a second delivery
sends the result: the bot's reply (`telegram.reply`) or, for a button press,
its answer (`telegram.callback.answer`) and, after Decline, the prompt
(`telegram.decline.prompt`). It normally arrives within about 20 seconds.

Processing is safe to repeat. A claim and its outcome are saved together, and
an update that already has an outcome is never processed again. Replies are
delivered at least once, like booking notifications. Requeue a `failed`
delivery with `php artisan outbox:requeue <id>` after fixing the cause.

To summarise stored updates read-only (old rows are included):

```sql
SELECT kind, outcome, outcome_reason, COUNT(*)
  FROM telegram_updates GROUP BY kind, outcome, outcome_reason;
```

`outcome` is empty until an update is processed. Afterwards a message is
`claimed`, `rejected` or `ignored`, and a button press is `approved`,
`declined`, `prompted`, `denied`, `stale`, `conflict`, `rejected` or `ignored`,
with the reason in `outcome_reason`.

### Retention

The `/start` token is cleared as soon as an update is processed. Every day,
`telegram:prune-data` clears the remaining data of updates received more than
7 days ago, and the text and chat ID of bot replies and Decline prompts created
more than 7 days ago. The update rows stay, so a repeated old update is still
recognised. Updates, replies and prompts older than 7 days can still be
requeued, but they no longer act: an update is recorded as `ignored` without a
reply, and a reply or a prompt fails with `permanent: payload`.

## Booking actions in Telegram

With `TELEGRAM_ACTIONS_ENABLED=true`, the staff message for a pending booking
request carries buttons:

- **Confirm: therapists checked for all guests** approves the request as the
  staff member who pressed it. With room scheduling on, it also assigns a room,
  or answers that no compatible room is free.
- **Decline** posts a second message that asks the same staff member to
  confirm. Only they can press **Yes, decline**, within 5 minutes, or less if
  the appointment starts sooner.
- **View booking** opens the admin booking page. It appears only when `APP_URL`
  is an `https://` address on a real domain.

Every press is checked again when it arrives. The staff member must still be
linked and still have booking access, and the booking must not have changed
since the message was posted. A refused press changes nothing and tells the
staff member why. After any decision, from Telegram or from the dashboard, the
recorded message is edited to the new status and its buttons are removed.

The answer to a press normally appears within 10 to 25 seconds, because it
passes through two `outbox:dispatch` runs and the queue worker. Telegram drops
an answer that arrives too late: the press was still handled, but nothing pops
up, so check the message or the dashboard.

### Before switching actions on

1. Booking notifications must already work
   (`TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED=true`): the buttons are part of the
   notification message.
2. Run the migrations before restarting the workers. The notification path
   needs the message tables even while actions are off.
3. The webhook must be registered with `callback_query` in `allowed_updates`
   (step 4 of "Set up the webhook").
4. `TELEGRAM_STAFF_CHAT_ID` must be the chat's numeric ID, such as
   `-1001234567890`. A chat name gets no buttons.
5. Every staff member who should act must be linked and have booking access.
6. Switch `TELEGRAM_ACTIONS_ENABLED` on, refresh the cached configuration and
   restart the web and worker processes.
7. Make one controlled booking and check, on a phone:
   - the Confirm label is not cut off;
   - a press is answered before Telegram's spinner gives up; time it;
   - Confirm changes the message to "Confirmed" and removes the buttons;
   - Decline asks for the second press, and another staff member cannot make
     it;
   - a group member who is not linked is refused.

### What to expect

- **Delivery is at least once.** If Telegram posts a message but its reply is
  lost, the retry posts a second copy. Only the newest copy's buttons work.
  The older copy answers "This button is no longer valid", and a booking can
  never be confirmed or declined twice. The older copy is never edited
  afterwards, so it keeps showing the old status; delete it from the chat by
  hand. Until the retry succeeds, the admin booking page says the message is
  "Not confirmed as delivered".
- **Switching actions off** removes every button from messages posted
  afterwards, including **View booking**. Presses on older messages are
  refused.
- **While notifications are off, decisions do not reach the chat.** The staff
  message is not edited, and it is not caught up later.
- **Online-payment requests cannot be confirmed** from Telegram while online
  payments are off.

## Customer status emails

With `BOOKING_CUSTOMER_NOTICES_ENABLED=true`, the customer is emailed when
their booking is confirmed, cancelled or declined, when a request expires
because nobody answered it in time, and when a no-show is recorded. The email
is in the language the booking was made in; staff-entered and older bookings
get Indonesian. No email is sent for a new request, for a completed
appointment, or for a booking that has no email address.

While the switch is off, these notices are dropped, not paused (see "Retries
and failures").

Emails are delivered at least once. If the mail server accepts an email but
its answer does not arrive within `MAIL_TIMEOUT`, the retry sends the email
again.

### Before switching emails on

1. Set a real mail transport. `MAIL_MAILER=log`, the default, writes each email
   to the log file and sends nothing, and the admin page still shows it as
   "Sent". The same happens when `failover` falls back to `log`.
2. Set `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`, and set `APP_URL` to the
   public site address: the email's link is built from it.
3. Keep `MAIL_TIMEOUT` short (15 seconds by default; it applies to SMTP). A
   mail server that does not answer would otherwise hold the queue worker for
   a minute per email. Raise it if your mail server is slow to accept an
   email, or customers can get the same email twice.
4. The scheduler and the queue worker must be running, as for Telegram.
5. Switch the flag on, refresh the cached configuration and restart the web
   and worker processes.
6. Make one controlled booking with an address you control and approve it.
   Check that the email arrives, that its language, date and time are right,
   and that its link opens the customer's booking page.

### Reading the Notifications section

The admin booking page lists every staff-message update and customer email of
a booking. It is read-only.

| Label                              | Meaning                                                                                              |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------- |
| Waiting to send, Sending now       | Queued, or claimed by a worker (a claim lasts up to 5 minutes)                                       |
| Sent (customer email)              | The mailer accepted the email. This does not prove it reached the inbox                              |
| Processed (staff Telegram message) | The delivery ran. Whether the chat changed is on the "Staff Telegram message" line above the list    |
| Failed, will retry                 | A temporary failure; the next attempt is scheduled                                                   |
| Failed                             | No attempts are left, or the failure is permanent. Fix the cause, then use `outbox:requeue`          |
| Not sent: (reason)                 | Deliberately skipped, and final                                                                      |

When a customer email was skipped because the booking has no email address,
the page asks staff to tell the guest by phone or WhatsApp.

## Deploying a release that changes notifications

1. Run `php artisan migrate --force` before restarting any worker.
2. Refresh the cached configuration (`php artisan config:cache`), then restart
   the web processes and the workers (`php artisan queue:restart`) together.
   The previous release's scheduler can keep running until the end of its
   minute.

A worker that still runs the previous code or the previous configuration fails
the deliveries it does not know: `permanent: event type is not configured` for
a new kind of event, or `permanent: handler is not configured` for a new
handler of a known event. Requeue those with `outbox:requeue` once every
worker runs the new release.

## Room scheduling

Room scheduling assigns a room to confirmed bookings. When
`BOOKING_SCHEDULING_ENABLED=true`, new bookings reserve a room as they are
confirmed.

### Before deploying this version

This version sets every MySQL session to `+00:00` (`DB_TIMEZONE`, default
`+00:00`) as soon as it is deployed, whether or not scheduling is switched on.
Check the production server first:

```sql
SELECT @@global.time_zone, @@system_time_zone, TIMEDIFF(NOW(), UTC_TIMESTAMP());
```

If the offset is not `00:00:00` and the database already holds data, set
`DB_TIMEZONE` in the deployment's environment to that offset (for example
`+07:00`) **before** the new code goes live. Otherwise existing `TIMESTAMP`
values read back shifted and new rows mix with old ones. Remove the override
only after a separate, reviewed conversion of the `TIMESTAMP` columns.

### Before switching scheduling on

1. Take a database backup and keep it until scheduling has run cleanly.
2. Run the pending migrations: `php artisan migrate --force`.
3. Run `php artisan bookings:readiness` and fix every line it prints. It is
   read-only and never prints customer contact details. Typical fixes:
   - the owner saves booking settings at `/admin/settings/booking`, and attests
     pricing on each package's page under `/admin/catalog`;
   - rooms are added, activated or linked to treatments;
   - conflicting bookings or maintenance periods are resolved by staff.

   Leave the `[assignments] … holds no room` lines for the next step; only it
   fixes them.

4. Run `php artisan bookings:reconcile-rooms` to list confirmed bookings that
   hold no room, with free rooms for each. For each booking:
   - check a room with
     `php artisan bookings:reconcile-rooms --booking=<reference> --room=<code>`,
     which is read-only;
   - then add `--apply`, which writes one room reservation and never changes
     the booking's status or price;
   - bookings it says to cancel and record again need staff, not the command.
5. Run `php artisan bookings:readiness` again; it must now say
   `Booking readiness: ready.`
6. Set `BOOKING_SCHEDULING_ENABLED=true` in the deployment's environment, then
   run `php artisan config:cache` and `php artisan queue:restart`, and restart
   the web processes. Keep the scheduler from the Telegram section running; it
   also expires pending requests.
7. Make one controlled test booking with test contact details, approve it in
   the admin panel, and confirm that it shows an assigned room on its detail
   page and in `/admin/schedule`. Then cancel it.

### Switching scheduling off again

Set `BOOKING_SCHEDULING_ENABLED=false`, run `php artisan config:cache` and
`php artisan queue:restart`, and restart the web processes. Room reservations
already made stay in place and do no harm. Do not restore the backup once real
bookings have arrived after the switch: they would be lost.

### Concurrency checks (MySQL)

`php vendor/bin/phpunit -c phpunit.concurrency.xml` runs real multi-process
races, plus a migration round trip, against a **temporary SQLite file**. It is
not part of `php artisan test`.

**To check the production engine:**

- create an **empty, throwaway** database on a server that holds no real data.
  Its name may use only lowercase letters, digits and underscores, and must end
  in `concurrency_test` (for example `shinjoy_concurrency_test`);
- export `CONCURRENCY_DB_DRIVER=mysql` (or `mariadb`), `CONCURRENCY_DB_HOST`,
  `CONCURRENCY_DB_PORT`, `CONCURRENCY_DB_DATABASE`, `CONCURRENCY_DB_USERNAME`
  and `CONCURRENCY_DB_PASSWORD`;
- run the same command.

**The suite wipes and rebuilds that database** (`migrate:fresh`). It refuses any
database name that does not end in `concurrency_test`.

On MySQL every test should pass, with none skipped. **Room scheduling is not
production-ready until this has passed at least once on the production engine.**

**What it proves:** the app's lock order serializes approvals, maintenance
blocks and outcome recording. The contended lock is the single staff-guard row,
so it proves the serialization design rather than contention on individual room
or reservation rows.

## Testing

`php artisan test` runs the backend tests against an in-memory SQLite database
with the array mailer, a synchronous queue, and Telegram notifications disabled
with empty credentials (Telegram tests fake HTTP); it refuses to start if
credentials or delivery settings leak in from `.env`. The multi-process race
suite is separate: see Concurrency checks (MySQL).

Playwright boots its own Laravel server against an isolated SQLite
database (`storage/framework/testing.sqlite`), so E2E runs never touch your
local development data. In tests Laravel looks for its Vite hot file under
`storage/framework/testing/` instead of `public/hot`, so a running `npm run dev`
doesn't change what the tests load. Pages are served from the built assets in
`public/build`: run `npm run build` after frontend changes and before
`npm run test:e2e`. E2E setup refuses to start when `public/build` is missing
or older than its sources. Each spec file starts from the freshly seeded
database (`test.beforeAll(resetTestingDatabase)`), so no spec sees rows another
spec left behind; files outside the database, such as screenshots and uploaded
catalogue images, are not reset.
Catalogue E2E fixtures live in `tests/e2e/fixtures`.

The five room specs that need room scheduling skip themselves unless
`E2E_BOOKING_SCHEDULING_ENABLED=true`. The other specs assume scheduling is
off, so run those five on their own (in Git Bash):

```bash
E2E_BOOKING_SCHEDULING_ENABLED=true npx playwright test tests/e2e/room-approval.spec.ts tests/e2e/room-block-termination.spec.ts tests/e2e/room-blocks.spec.ts tests/e2e/room-booking-submission.spec.ts tests/e2e/room-booking.spec.ts
```

`composer test:coverage` needs the Xdebug or PCOV PHP extension and fails if
line coverage is below 80%.

## Fonts

Playfair Display and Plus Jakarta Sans are bundled in `public/fonts` under the
SIL Open Font License 1.1 — see the `OFL-*.txt` files in the same directory.
