# Frontend Authentication and Booking History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add optional browser-local registration and login, persistent user sessions, signed-in booking capture, and a protected bilingual booking-history profile to the existing Vue spa website.

**Architecture:** Framework-independent modules own validation, Web Crypto password derivation, versioned browser storage, and booking-history classification. A shared Vue composable mirrors the active session into reactive state, while router guards, auth pages, the existing booking page, and the main header consume that interface without reading storage directly.

**Tech Stack:** Vue 3 Composition API, Vue Router 4, browser Web Crypto API, browser `localStorage`, Vite 8, Tailwind CSS 4 plus existing CSS, Node built-in test runner.

**Spec:** `docs/superpowers/specs/2026-08-13-frontend-auth-booking-history-design.md`

## Global Constraints

- Authentication remains optional; all existing public pages and guest booking stay public.
- This is a browser-local prototype with no server persistence or cross-device sync.
- Passwords must never be stored in plain text; use random salts and PBKDF2-SHA-256.
- Account emails are normalized with `trim().toLowerCase()` before lookup.
- Booking ownership always comes from the active session, never form or route input.
- Guest reservations are not stored in profile history.
- All new visible strings must exist in both Indonesian and English.
- UI must preserve existing accessible labels, visible focus behavior, responsive layout, fonts, colors, and button conventions.
- Input records and returned values are copied rather than mutated.
- Do not add a component-test framework or runtime dependency.
- The workspace is not a Git repository, so replace commit steps with diff/file checkpoints unless Git metadata becomes available.

## File Map

- Create `src/auth.js`: pure validation, email normalization, password derivation, account/session storage, safe redirects, and injectable browser dependencies.
- Create `src/useAuth.js`: singleton reactive current-user state and UI-facing auth actions.
- Create `src/bookingHistory.js`: immutable booking snapshots plus upcoming/past classification and ordering.
- Create `src/Pages/Login.vue`: bilingual focused login page.
- Create `src/Pages/Register.vue`: bilingual focused registration page.
- Create `src/Pages/Profile.vue`: protected account summary and booking-history page.
- Modify `src/router.js`: initialize auth and enforce route metadata.
- Modify `src/routes.js`: add login, registration, and protected profile records.
- Modify `src/Pages/Booking.vue`: prefill signed-in details and persist signed-in bookings exactly once.
- Modify `src/Layouts/MainLayout.vue`: signed-out links and signed-in account menu.
- Modify `src/Data/translations.js`: complete Indonesian and English auth/profile/history copy.
- Modify `src/css/legacy.css`: auth feedback, header account actions, and responsive profile-history styling.
- Create `tests/frontend/auth.test.mjs`: validation, cryptography, storage, session, corruption, and redirect tests.
- Create `tests/frontend/booking-history.test.mjs`: ownership, immutability, classification, and ordering tests.
- Create `tests/frontend/auth-integration.test.mjs`: source-level route, guard, UI wiring, and translation parity checks.
- Modify `tests/frontend/standalone-app.test.mjs`: expected route list.
- Modify `tests/frontend/booking.test.mjs`: retain existing validation tests and cover account-prefill helper only if extracted there.

---

### Task 1: Browser-local account and session domain

**Files:**

- Create: `src/auth.js`
- Create: `tests/frontend/auth.test.mjs`

**Interfaces:**

- Consumes: `Storage`-compatible objects with `getItem`, `setItem`, and `removeItem`; Web Crypto `subtle` and `getRandomValues`.
- Produces: `AUTH_STORAGE_KEY`, `normalizeEmail(email)`, `validateRegistration(input)`, `validateLogin(input)`, `createAuthStore(options)`, and `sanitizeRedirect(value, routes)`.
- `createAuthStore(options)` returns async `register(input)`, async `login(credentials)`, synchronous `getCurrentUser()`, and synchronous `logout()` methods.
- Action results use `{ ok: true, user }` or `{ ok: false, code, fields? }`; public user objects contain only `{ id, name, email, phone, createdAt }`.

- [ ] **Step 1: Write failing validation and normalization tests**

Add tests that import `normalizeEmail`, `validateRegistration`, and `validateLogin`, then assert:

```js
assert.equal(normalizeEmail("  AYU@Example.COM "), "ayu@example.com");
assert.deepEqual(
  validateRegistration({
    name: "A",
    email: "bad",
    phone: "123",
    password: "short",
    confirmPassword: "different",
  }),
  {
    name: "auth.err.name",
    email: "auth.err.email",
    phone: "auth.err.phone",
    password: "auth.err.password",
    confirmPassword: "auth.err.confirm",
  },
);
assert.deepEqual(
  validateRegistration({
    name: "Ayu Lestari",
    email: "ayu@example.com",
    phone: "+62 812 3456 7890",
    password: "calmspa9",
    confirmPassword: "calmspa9",
  }),
  {},
);
assert.deepEqual(validateLogin({ email: "", password: "" }), {
  email: "auth.err.email",
  password: "auth.err.passwordRequired",
});
```

- [ ] **Step 2: Run the auth test and verify RED**

Run: `node --test tests/frontend/auth.test.mjs`

Expected: FAIL because `src/auth.js` does not exist or named exports are missing.

- [ ] **Step 3: Implement pure validation and safe redirect handling**

Implement schema-free boundary validation with no object mutation. `sanitizeRedirect(value, routes)` must accept only a single string beginning with exactly one `/`, reject `//`, backslashes, schemes, unknown route paths, Login, and Register, and return `/profile` as the fallback.

Add tests such as:

```js
const known = ["/", "/services", "/booking", "/about", "/profile"];
assert.equal(
  sanitizeRedirect("/booking?service=facial", known),
  "/booking?service=facial",
);
assert.equal(sanitizeRedirect("//evil.example", known), "/profile");
assert.equal(sanitizeRedirect("https://evil.example", known), "/profile");
assert.equal(sanitizeRedirect("/missing", known), "/profile");
```

- [ ] **Step 4: Write failing storage and cryptography tests with injected fakes**

Create an in-memory `Storage` fake and inject deterministic `now`, `randomBytes`, and Web Crypto-compatible password derivation dependencies. Cover:

```js
const registered = await store.register(validRegistration);
assert.equal(registered.ok, true);
assert.deepEqual(store.getCurrentUser(), registered.user);
assert.equal(JSON.stringify(storage.dump()).includes("calmspa9"), false);

const duplicate = await store.register({
  ...validRegistration,
  email: " AYU@EXAMPLE.COM ",
});
assert.deepEqual(duplicate, { ok: false, code: "duplicate-email" });

store.logout();
assert.equal(store.getCurrentUser(), null);
assert.equal(
  (await store.login({ email: "ayu@example.com", password: "wrongpass9" }))
    .code,
  "invalid-credentials",
);
assert.equal(
  (await store.login({ email: "ayu@example.com", password: "calmspa9" })).ok,
  true,
);
```

Also test malformed JSON, structurally invalid records, a session pointing at a missing user, storage reads/writes that throw, and registration input immutability.

- [ ] **Step 5: Run storage tests and verify RED**

Run: `node --test tests/frontend/auth.test.mjs`

Expected: validation tests pass; account/storage tests fail because `createAuthStore` is not implemented.

- [ ] **Step 6: Implement versioned storage and PBKDF2**

Use this record shape under `shinjoy-auth-v1`:

```js
{
  version: 1,
  accounts: [{
    id, name, email, phone, createdAt,
    password: { algorithm: 'PBKDF2-SHA-256', iterations: 210000, salt, hash },
  }],
  session: { accountId, createdAt } | null,
}
```

Encode salts/hashes as base64. Default to `globalThis.crypto`, `globalThis.localStorage`, `Date`, and `crypto.randomUUID()` with a random-byte identifier fallback. Return `storage-unavailable` when required storage access fails. Clone data on reads/writes and strip password metadata from all public users.

- [ ] **Step 7: Run tests and checkpoint Task 1**

Run: `node --test tests/frontend/auth.test.mjs`

Expected: all auth domain tests PASS. Review `src/auth.js` to confirm no plain-text password is serialized or returned.

---

### Task 2: Shared session state and protected routing

**Files:**

- Create: `src/useAuth.js`
- Modify: `src/routes.js`
- Modify: `src/router.js`
- Modify: `tests/frontend/standalone-app.test.mjs`
- Create: `tests/frontend/auth-integration.test.mjs`

**Interfaces:**

- Consumes: `createAuthStore()` and `sanitizeRedirect()` from `src/auth.js`.
- Produces: readonly `currentUser`, computed `isAuthenticated`, `initializeAuth()`, async `registerUser(input)`, async `loginUser(input)`, and `logoutUser()` from `src/useAuth.js`.
- Produces route names `login`, `register`, and `profile`; Profile has `meta.requiresAuth: true`.

- [ ] **Step 1: Write failing route and session-wiring tests**

Update the expected route paths to:

```js
[
  "/",
  "/services",
  "/services/:slug",
  "/booking",
  "/about",
  "/login",
  "/register",
  "/profile",
  "/:pathMatch(.*)*",
];
```

In `auth-integration.test.mjs`, read source files and assert the Profile record has `meta.requiresAuth`, auth routes lazy-load their exact page files, `router.beforeEach` exists, protected routes redirect to Login with `redirect: to.fullPath`, and authenticated visits to Login/Register redirect to Profile.

- [ ] **Step 2: Run route/integration tests and verify RED**

Run: `node --test tests/frontend/standalone-app.test.mjs tests/frontend/auth-integration.test.mjs`

Expected: FAIL because auth routes, composable, and guard do not exist.

- [ ] **Step 3: Implement the singleton auth composable**

Initialize one `createAuthStore()` instance. Every successful register/login sets `currentUser.value` to a fresh public-user object; logout sets it to `null`. `initializeAuth()` is idempotent and loads the current public user once. Do not expose the storage instance or password metadata.

- [ ] **Step 4: Add routes and navigation guard**

Add lazy routes in `src/routes.js` and guard logic in `src/router.js`. Before each navigation, call `initializeAuth()`. Redirect signed-out protected visits to:

```js
{ name: 'login', query: { redirect: to.fullPath } }
```

Redirect signed-in visits to Login/Register to `{ name: 'profile' }`. Login will sanitize the redirect again before navigation.

- [ ] **Step 5: Run tests and checkpoint Task 2**

Run: `node --test tests/frontend/auth.test.mjs tests/frontend/standalone-app.test.mjs tests/frontend/auth-integration.test.mjs`

Expected: all selected tests PASS.

---

### Task 3: Bilingual Login and Register pages

**Files:**

- Create: `src/Pages/Login.vue`
- Create: `src/Pages/Register.vue`
- Modify: `src/Data/translations.js`
- Modify: `src/css/legacy.css`
- Modify: `tests/frontend/auth-integration.test.mjs`

**Interfaces:**

- Consumes: `loginUser`, `registerUser`, and `isAuthenticated` from `src/useAuth.js`; `sanitizeRedirect` from `src/auth.js`; `language`, `setLanguage`, and `tr` from `src/useLanguage.js`.
- Produces: accessible forms with field-level validation, submit-level errors, password visibility controls, and router navigation.

- [ ] **Step 1: Write failing auth-page and translation parity tests**

Assert source-level contracts:

```js
assert.match(loginSource, /@submit\.prevent="submit"/);
assert.match(loginSource, /autocomplete="current-password"/);
assert.match(registerSource, /autocomplete="new-password"/);
assert.match(registerSource, /aria-live="polite"/);
assert.match(registerSource, /registerUser/);
```

Import translations and compare `Object.keys(translations.id).sort()` with English keys. Assert the presence of navigation, auth labels, validation messages, local-demo disclosure, storage failure, show/hide-password, and alternate-action keys.

- [ ] **Step 2: Run integration tests and verify RED**

Run: `node --test tests/frontend/auth-integration.test.mjs`

Expected: FAIL because pages and translation keys are missing.

- [ ] **Step 3: Implement Register page**

Build a focused `.auth` layout using `/img/stilllife.jpg` for the decorative aside, empty initial fields, reactive field errors, a submit error, and a pending state. Call `registerUser` only after `validateRegistration` passes. Map `duplicate-email` and `storage-unavailable` to bilingual user-facing messages. On success, use `router.replace({ name: 'profile' })`. Disable duplicate submission while pending.

- [ ] **Step 4: Implement Login page**

Use the same layout with `autocomplete="email"` and `autocomplete="current-password"`. Validate required fields, call `loginUser`, show one generic credentials error, map storage failure, sanitize `route.query.redirect` against public route paths, then `router.replace(destination)`.

- [ ] **Step 5: Finish auth styling and bilingual copy**

Extend existing auth CSS for `.auth-error`, `.auth-demo-note`, `.field-error`, disabled buttons, focus-visible password toggles, and mobile spacing. Add complete `auth.*`, `nav.login`, `nav.register`, `nav.profile`, and `nav.logout` keys in both languages. Do not add em dashes to visible copy.

- [ ] **Step 6: Run tests and production build checkpoint**

Run: `node --test tests/frontend/auth.test.mjs tests/frontend/auth-integration.test.mjs`

Run: `npm run build`

Expected: tests PASS and Vite exits 0.

---

### Task 4: Signed-in booking persistence and profile history

**Files:**

- Create: `src/bookingHistory.js`
- Create: `tests/frontend/booking-history.test.mjs`
- Modify: `src/useAuth.js`
- Modify: `src/Pages/Booking.vue`
- Create: `src/Pages/Profile.vue`
- Modify: `src/Data/translations.js`
- Modify: `src/css/legacy.css`
- Modify: `tests/frontend/auth-integration.test.mjs`

**Interfaces:**

- Consumes: current public user from `src/useAuth.js`, selected service data, and `Storage`-compatible objects.
- Produces: `BOOKING_STORAGE_KEY`, `createBookingStore(options)`, and `partitionBookings(bookings, now)`.
- `createBookingStore(options)` returns `addForUser(user, form, service)` and `listForUser(userId)`.
- Booking action result uses `{ ok: true, booking }` or `{ ok: false, code: 'storage-unavailable' }`.

- [ ] **Step 1: Write failing booking-history domain tests**

At a fixed `2026-08-13T10:00:00+07:00`, assert:

```js
const { upcoming, past } = partitionBookings(bookings, fixedNow);
assert.deepEqual(
  upcoming.map(({ id }) => id),
  ["soon", "later"],
);
assert.deepEqual(
  past.map(({ id }) => id),
  ["recent-past", "old-past"],
);
```

Test that `addForUser` takes ownership from `user.id`, stores a snapshot of service names/duration/price and reservation contact data, does not mutate `user`, `form`, or `service`, filters other users, returns fresh arrays, handles corrupt JSON, and reports storage write failure.

- [ ] **Step 2: Run booking-history tests and verify RED**

Run: `node --test tests/frontend/booking-history.test.mjs`

Expected: FAIL because `src/bookingHistory.js` does not exist.

- [ ] **Step 3: Implement immutable booking storage and classification**

Use `shinjoy-bookings-v1` with `{ version: 1, bookings: [] }`. Combine ISO date and 24-hour time as local time for classification. Save stable service fields:

```js
{
  id, accountId, createdAt, name, email, phone,
  service: { slug, nameId, nameEn, duration, price },
  date, time, guests, notes,
}
```

Reject calls without a valid public user, valid service, ISO date, or supported time with `{ ok: false, code: 'invalid-booking' }`.

- [ ] **Step 4: Run domain tests and verify GREEN**

Run: `node --test tests/frontend/booking-history.test.mjs`

Expected: all booking-history tests PASS.

- [ ] **Step 5: Wire booking prefill and exactly-once saving**

Initialize auth before form construction or apply a one-time watcher that fills only empty `name`, `email`, and `phone` fields. In `submit()`, after validation and before confirmation, call `addForUser(currentUser.value, { ...form }, selectedService.value)` only when signed in and only while `savedBookingId` is empty. A storage failure must show a non-sensitive history warning while keeping the reservation confirmation successful. Reset clears `savedBookingId` and starts a new reservation.

- [ ] **Step 6: Write failing profile-source tests**

Assert Profile imports `partitionBookings`, reads only `currentUser.id`, renders upcoming and past sections, uses service `nameId`/`nameEn`, has an empty-state link to `/booking`, and contains no cancellation or deletion action.

- [ ] **Step 7: Implement Profile page and history styling**

Use `MainLayout`. Present a compact account summary and two semantic history sections. Format appointment dates with `Intl.DateTimeFormat(language === 'id' ? 'id-ID' : 'en-GB', { dateStyle: 'long' })`. Render upcoming and past cards with `<article>` and `<time datetime="...">`. Add responsive `.profile-layout`, `.profile-summary`, `.history-section`, `.history-list`, `.history-card`, `.history-meta`, `.history-empty`, and status-label styles using existing tokens.

- [ ] **Step 8: Add bilingual profile/history copy and run checkpoint**

Add `profile.*` and `booking.historyWarning` keys to both languages. Then run:

`node --test tests/frontend/booking-history.test.mjs tests/frontend/auth-integration.test.mjs tests/frontend/booking.test.mjs`

Expected: all selected tests PASS.

---

### Task 5: Header account states, review, and complete verification

**Files:**

- Modify: `src/Layouts/MainLayout.vue`
- Modify: `src/css/legacy.css`
- Modify: `tests/frontend/auth-integration.test.mjs`
- Verify: all changed source and tests

**Interfaces:**

- Consumes: `currentUser`, `isAuthenticated`, and `logoutUser` from `src/useAuth.js`.
- Produces: signed-out Login/Register controls and an accessible signed-in Profile/Logout account menu on desktop and mobile.

- [ ] **Step 1: Write failing header-state tests**

Assert `MainLayout.vue` imports auth state, conditionally renders Login/Register and account controls, includes Profile and Logout, derives the avatar initial safely, closes the account menu on route changes/Escape/outside interaction, and calls `logoutUser()` before routing home.

- [ ] **Step 2: Run integration tests and verify RED**

Run: `node --test tests/frontend/auth-integration.test.mjs`

Expected: FAIL on missing header account-state contracts.

- [ ] **Step 3: Implement responsive account navigation**

Add signed-out links without displacing the existing booking CTA. For signed-in users, use a real button with `aria-expanded`, `aria-controls`, and a text alternative naming the account. The menu contains the current name, a RouterLink to Profile, and a button for Logout. Close it on route change, Escape, outside pointer interaction, mobile menu close, and logout. Restore focus to the trigger when Escape closes the menu.

- [ ] **Step 4: Complete CSS and accessibility preflight**

Verify keyboard reachability, focus-visible states, mobile menu layout, button/input contrast, error associations, status announcements, and reduced-motion behavior. Ensure the auth and profile pages use one light theme and the existing rose accent/radius system. Search visible new strings for em dashes and replace them.

- [ ] **Step 5: Perform security and code-quality review**

Inspect all changes for plain-text credential persistence, unsafe redirect acceptance, cross-account booking leakage, rendering through `v-html`, mutation of caller-owned objects, leaked storage exceptions, duplicated booking writes, and missing input validation. Fix every critical or high-severity issue and add a regression test for each corrected behavior.

- [ ] **Step 6: Run the full verification suite**

Run: `npm run test:frontend`

Expected: every Node test passes with zero failures.

Run: `npm run build`

Expected: Vite production build exits 0 and creates `dist/index.html` plus auth/profile chunks.

Run: `rg -n --glob '!node_modules/**' --glob '!dist/**' "calmspa9|password\s*[:=]\s*['\"]|v-html|https?://.*redirect" src tests`

Expected: no hardcoded test password in source, no `v-html`, and no unsafe external redirect code. Test fixtures may contain test passwords only in `tests/`.

- [ ] **Step 7: Final requirements checkpoint**

Re-read `docs/superpowers/specs/2026-08-13-frontend-auth-booking-history-design.md` and confirm each acceptance criterion against a passing test or inspected UI contract. Report any limitation explicitly, especially that accounts and history are local to one browser. Since Git is unavailable, provide the user a concise list of created/modified files rather than a commit hash.
