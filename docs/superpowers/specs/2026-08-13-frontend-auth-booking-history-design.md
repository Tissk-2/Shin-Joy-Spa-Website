# Frontend Authentication and Booking History Design

## Summary

Add optional, frontend-only customer accounts to the Shin Joy Spa Vue application. Visitors may continue booking as guests. Registered users receive a persistent local session, prefilled contact details during booking, and a profile page containing only the bookings they made while signed in.

This feature is a browser-local prototype. Accounts, sessions, and booking history do not sync between browsers or devices and must not be presented as production-grade authentication.

## Goals

- Let a visitor register with a name, email address, phone number, and password.
- Let a registered visitor log in and remain logged in after a page refresh.
- Keep login optional for browsing and booking.
- Prefill the booking form from the signed-in profile without preventing edits for an individual booking.
- Save a successful booking to the signed-in account only.
- Show upcoming and past booking history on a protected profile page.
- Provide the complete experience in Indonesian and English.
- Preserve the existing visual language, responsive behavior, and accessible form patterns.

## Non-goals

- Backend authentication, server persistence, or cross-device sync.
- Password recovery, email verification, social login, or multi-factor authentication.
- Editing profile details or changing passwords.
- Cancelling, rescheduling, or editing saved bookings.
- Associating guest bookings with an account after submission.
- Admin access or staff booking management.

## User Experience

### Signed-out navigation

The header shows links for Login and Register alongside the existing booking action. Mobile navigation exposes the same actions. Login and registration use focused auth layouts based on the existing `.auth` styles, with a route back to the main site and the existing language selector.

### Registration

The registration form collects full name, email, phone, password, and password confirmation. It validates all fields before storage. Email matching is case-insensitive and surrounding whitespace is removed. A successful registration creates the account, starts a session, and redirects to `/profile`.

Validation errors cover:

- Missing or implausibly short name.
- Invalid email format.
- Invalid phone format.
- Password shorter than eight characters or lacking both letters and numbers.
- Password confirmation mismatch.
- Email already registered.

### Login

The login form accepts email and password. It shows one generic invalid-credentials message when the account does not exist or the password does not match, avoiding disclosure of registered emails. A successful login starts a persistent session and redirects to the originally requested protected route when present, otherwise `/profile`.

### Signed-in navigation

The header replaces Login and Register with an account control showing the user's initial. Its menu contains the user's name, Profile, and Logout. Logging out clears only the active session and returns the user to the home page; it does not delete accounts or booking history.

### Booking

Booking remains public. When a user is signed in, name, email, and phone are prefilled from the account when the page is opened. The user may change these values for that reservation without modifying the stored profile.

After existing booking validation succeeds, the confirmation screen behaves as it does today. If a user is signed in at submission time, an immutable booking snapshot is added to that user's history. Repeated rendering or page interactions must not duplicate the saved booking. If no user is signed in, no history entry is created.

Each saved booking contains:

- A generated unique identifier.
- The owning account identifier.
- Name, email, and phone used for the reservation.
- Service slug and localized-independent service data needed for stable display: service names, duration, and price.
- Date, time, guest count, and notes.
- Creation timestamp.

### Profile and history

`/profile` requires a valid local session. A signed-out visitor is redirected to `/login?redirect=/profile`. The page displays the user's name, email, and phone, followed by booking history.

Bookings are classified using the appointment's local date and time:

- Upcoming bookings include appointments at or after the current local time and are ordered soonest first.
- Past bookings include appointments before the current local time and are ordered newest first.

Each history item shows service, appointment date and time, duration, guest count, price, and booking contact details. Empty states explain that no bookings exist and link to `/booking`. No destructive booking controls are included.

## Architecture

### Storage layer

A focused module owns all browser-storage access. UI components do not parse or write storage records directly.

Suggested storage keys:

- `shinjoy-auth-v1`: versioned account and active-session data.
- `shinjoy-bookings-v1`: versioned booking records.

The storage module:

- Parses records defensively and falls back to an empty valid state when data is missing, malformed, or structurally invalid.
- Never mutates returned account or booking objects in place.
- Normalizes emails before comparisons.
- Exposes account creation, authentication, current-user lookup, logout, booking creation, and per-user history retrieval.
- Does not expose password hashes through current-user data returned to UI code.

### Password handling

Passwords are never stored as plain text. Registration generates a random salt with `crypto.getRandomValues` and derives a password hash through Web Crypto PBKDF2 with SHA-256. Login derives a candidate hash with the stored salt and compares encoded values.

This reduces casual password exposure in browser storage but does not make a frontend-only system secure. Anyone controlling the browser can inspect or replace local data. The UI and documentation must identify the feature as a local demo where relevant, and real credentials should not be encouraged.

### Reactive session layer

A small auth composable initializes session state from the storage module and exposes readonly current-user state plus register, login, and logout actions. Header, booking, profile, and route protection consume this shared state. State changes produce new account/session objects rather than mutating existing records.

### Routing

Add lazy-loaded routes for `/login`, `/register`, and `/profile`. The profile route receives `meta.requiresAuth`. A global navigation guard initializes auth state and redirects unauthenticated protected navigation to Login with a sanitized internal redirect query. Login and Register redirect an already authenticated user to Profile.

Only paths beginning with `/` and belonging to this router are accepted as post-login redirects. External URLs and protocol-relative values are ignored.

### Pages and layout

- `Login.vue`: focused auth form, language control, site return link, registration link, generic authentication error.
- `Register.vue`: focused registration form, language control, site return link, login link, field errors.
- `Profile.vue`: uses the main layout and renders account summary, upcoming history, past history, and empty states.
- `MainLayout.vue`: renders signed-out auth links or the signed-in account menu and handles menu focus/closing behavior.
- `Booking.vue`: prefills profile details and records a successful signed-in booking.

The existing auth CSS is reused and adjusted only where needed. New profile styles follow the existing rose, plum, white, Marcellus, and Mulish system, with the current radius and button conventions. Controls retain visible focus states, labels, error text, and sufficient contrast.

## Data Integrity and Error Handling

- Storage operations return explicit success or error results instead of silently swallowing expected failures.
- If browser storage is unavailable or quota-limited, registration/login/booking history shows a user-friendly local-storage error while guest booking itself can still complete.
- Corrupt records are ignored safely and replaced only when a subsequent valid write occurs.
- Duplicate registration is checked against normalized email addresses.
- Current-user resolution requires the session account ID to match a valid stored account.
- Booking ownership is set from the active session, never from form input or route data.
- UI errors contain no hashes, salts, storage payloads, or exception internals.

## Internationalization

All new visible strings are added to the existing translation dictionaries for Indonesian and English. Stored bookings keep language-independent values so changing the site language updates labels and formatting without changing ownership or appointment data. Dates use the active language's locale for display while retaining ISO date strings for sorting.

## Testing Strategy

Implementation follows RED, GREEN, REFACTOR.

### Unit tests

- Registration validation rejects malformed inputs and accepts valid input.
- Email normalization and duplicate detection are case-insensitive.
- Password hashing stores no plain-text password and valid/invalid credentials are distinguished.
- Corrupt or unavailable storage produces safe results.
- Sessions persist, resolve only existing users, and logout clears only the session.
- Booking creation binds ownership to the active account.
- Booking history excludes other users and guest submissions.
- Upcoming and past bookings are classified and sorted correctly at a fixed current time.
- Input objects and returned collections are not mutated.

### Integration-oriented frontend tests

- Route records include Login, Register, and protected Profile routes.
- The router guard redirects signed-out profile visits and rejects unsafe redirect targets.
- Booking source includes account prefill and history persistence through the storage boundary.
- Header source includes signed-out and signed-in navigation states.
- Indonesian and English translation keys stay in parity.

### Build and regression verification

- Run the complete Node frontend test suite.
- Run the production Vite build.
- Verify existing booking validation and current page routes remain operational.
- Confirm no generated `dist` artifacts are treated as source edits unless intentionally rebuilt.

The project currently uses source-level Node tests rather than a mounted Vue component test framework. Tests will follow that established approach and concentrate executable behavior in framework-independent modules. Adding a new test framework is outside scope unless implementation proves it necessary.

## Security and Privacy Constraints

- No secrets or fixed credentials are added to the repository.
- Passwords are salted and derived with browser-native cryptography.
- Authentication errors do not reveal whether an email exists.
- Redirects are restricted to internal application paths.
- User-entered values are rendered through Vue interpolation, not `v-html`.
- Booking history is filtered by the authenticated account identifier.
- The feature is explicitly a single-browser demonstration and is not suitable for real sensitive customer data.

## Acceptance Criteria

1. Visitors can register, log in, log out, and remain logged in after refresh using browser-local data.
2. Registration and login validate input and provide accessible, bilingual feedback.
3. Visitors can still complete a booking without an account.
4. Signed-in booking forms are prefilled from the profile.
5. Only bookings submitted while signed in are saved to that account.
6. Profile access is protected and redirects safely through Login.
7. Profile history shows only the current user's bookings, divided and sorted into upcoming and past groups.
8. Header navigation accurately reflects signed-in state on desktop and mobile.
9. Existing tests continue to pass, new auth/history tests pass, and the production build succeeds.
10. No plain-text passwords, external redirects, or cross-account booking leakage are present.
