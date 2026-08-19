# Laravel Full-Stack Authentication Migration Design

## Summary

Replace the standalone Vue/Vite prototype with a Laravel 13 monolith using Vue 3, TypeScript, Inertia 3, and MySQL/MariaDB. Preserve the existing Shin Joy Spa public website, bilingual interface, login and registration appearance, and current user-owned edits while replacing browser-local authentication with production-oriented Laravel authentication.

The first backend phase covers customer registration, email verification, login, logout, password recovery, and protected account access. Booking persistence, service management, payments, and administrator authorization remain separate backend phases.

## Current State

- The project is a standalone Vue 3 application built with Vite.
- Customer accounts, password hashes, and the active session are currently stored in browser `localStorage` through `src/auth.js`.
- Login and registration screens already exist in `src/Pages/Login.vue` and `src/Pages/Register.vue`.
- Vue Router currently owns public and protected client-side routes.
- The root PHP files are incomplete legacy artifacts and do not provide a runnable backend because their shared initialization files are absent.
- The working tree contains user-owned modifications and deletions. Migration must preserve their behavior and must not restore, discard, or overwrite them.
- The system PHP CLI is 8.5.3, XAMPP PHP is 8.2.12, and XAMPP provides MariaDB 10.4.32. Laravel 13 requires PHP 8.3 or newer, so local Laravel commands and the development server will use the PHP 8.5 CLI. XAMPP MariaDB remains the local database.
- Composer is not currently installed and must be installed before Laravel scaffolding.

## Goals

- Establish one Laravel repository containing the backend and Vue frontend.
- Preserve the current visual design, responsive behavior, accessibility, and Indonesian/English content.
- Register customers with name, normalized unique email, phone number, and password.
- Require customers to verify their email before accessing protected account features.
- Support login, logout, persistent secure sessions, password reset requests, and password updates through expiring reset links.
- Replace browser-local accounts and sessions with server-side Laravel authentication.
- Provide clear, bilingual validation and failure feedback without exposing sensitive information.
- Create a secure, testable foundation for later booking and administration phases.

## Non-Goals

- Migrating existing browser-local prototype accounts into the server database.
- Persisting bookings, services, availability, payments, or customer profile edits in this phase.
- Implementing administrator roles, permissions, or an administrator dashboard backend in this phase.
- Social login, passkeys, multi-factor authentication, or single sign-on.
- A separate REST API, JWT authentication, or a separately deployed frontend.
- Changing the existing brand direction or redesigning public pages.

## Chosen Stack

- Laravel 13 for routing, controllers, validation, authentication, authorization boundaries, mail, database access, and testing.
- Vue 3 with the Composition API and TypeScript for page and component rendering.
- Inertia 3 to connect Laravel routes and controllers to Vue pages without a separate API or Vue Router.
- Vite through Laravel's official integration for frontend development and production assets.
- MySQL-compatible MariaDB for local development; production may use supported MySQL or MariaDB hosting.
- Laravel's built-in authentication implementation supplied by the official Vue starter kit, customized to the Shin Joy Spa interface.
- Laravel session authentication and CSRF protection instead of tokens stored in the browser.

## Application Architecture

Laravel owns HTTP routing, authentication state, validation, redirects, and page data. Inertia renders Vue page components from Laravel controllers or route actions. Vue components submit forms through Inertia and receive field errors and shared authenticated-user data from Laravel.

The application remains a single deployable unit:

```text
Browser
  -> Laravel web routes and middleware
  -> controllers / authentication actions
  -> Eloquent models
  -> MySQL or MariaDB
  -> Inertia response
  -> Vue page component
```

There is no client-side auth store containing accounts or password material. Laravel shares only a safe public user representation with Inertia. Vue Router is removed from the runtime; Laravel and Inertia own navigation.

## Source Migration

The Laravel application will occupy the repository root. Existing frontend sources will be migrated into Laravel's structure rather than discarded:

- Vue pages move from `src/Pages` to `resources/js/pages`.
- Shared Vue components move from `src/Components` to `resources/js/components`.
- Layouts move from `src/Layouts` to `resources/js/layouts`.
- Data and framework-independent helpers move into focused folders under `resources/js`.
- Styles move under `resources/css` while preserving existing selectors and visual behavior.
- Public images and fonts remain under `public` unless Laravel's asset pipeline requires an equivalent safe path.
- The existing public page behavior and current user edits are migrated from the working tree, not reconstructed from the last Git commit.

The old local authentication implementation is retired only after equivalent server-backed flows pass tests. Legacy root PHP files are excluded from the new runtime; they are not used as a security or data source.

## Authentication Flow

### Registration

1. A signed-out visitor opens `/register`.
2. The Vue form submits name, email, phone, password, and password confirmation to Laravel.
3. A Laravel form request validates and normalizes the input.
4. Laravel creates the user with a securely hashed password inside a database transaction.
5. Laravel starts an authenticated session, regenerates the session identifier, and dispatches the verification notification.
6. The user is redirected to the email-verification notice page.
7. Protected account pages remain unavailable until `email_verified_at` is set.

### Email Verification

- Verification links are signed and expire according to Laravel configuration.
- The verification endpoint requires an authenticated session and validates the signed URL.
- A successful verification sets `email_verified_at` and redirects to the protected profile page.
- Users can request another verification email through a rate-limited endpoint.
- Repeated use of an already-consumed valid verification link is handled safely and does not create duplicate side effects.

### Login

1. A signed-out visitor submits email, password, and an optional remember-me choice.
2. Laravel normalizes the email and validates the request.
3. Authentication returns one generic invalid-credentials message for unknown emails and incorrect passwords.
4. A successful login regenerates the session identifier.
5. Verified users continue to the intended internal page or `/profile`.
6. Unverified users continue to the verification notice.

### Logout

- Laravel invalidates the authenticated session and regenerates the CSRF token.
- The user is redirected to the public home page.
- Logout does not delete the user or future server-side booking records.

### Password Recovery

1. A visitor submits an email address on `/forgot-password`.
2. Laravel always returns a neutral response that does not reveal whether the address exists.
3. Existing users receive a signed, expiring reset link through the configured mail transport.
4. The reset form accepts email, token, password, and password confirmation.
5. A successful reset stores a new secure password hash, rotates the remember token, and permits a normal login.
6. Invalid, expired, or already-used tokens produce a safe bilingual error.

## Data Model

The initial `users` table contains:

| Column | Purpose |
| --- | --- |
| `id` | Primary key |
| `name` | Customer display and booking name |
| `email` | Normalized, unique login identifier |
| `phone` | Customer contact number |
| `email_verified_at` | Nullable verification timestamp |
| `password` | Laravel password hash |
| `remember_token` | Laravel remember-me token |
| `created_at`, `updated_at` | Audit timestamps |

Laravel's password-reset token storage and session storage use framework-supported migrations. Application code never stores a separate password salt because Laravel's configured password hasher includes the necessary salt and algorithm metadata in the hash.

## Routes and Access Rules

Signed-out-only routes:

- `GET /login`
- `POST /login`
- `GET /register`
- `POST /register`
- `GET /forgot-password`
- `POST /forgot-password`
- `GET /reset-password/{token}`
- `POST /reset-password`

Authenticated routes:

- `POST /logout`
- `GET /verify-email`
- `GET /verify-email/{id}/{hash}`
- `POST /email/verification-notification`

Authenticated and verified route:

- `GET /profile`

Public marketing, service-detail, and booking pages remain accessible without an account. Later booking persistence will decide which booking actions are guest-accessible without changing this authentication contract.

## Validation and Normalization

- Name is required, trimmed, contains at least two characters, and is limited to 100 characters.
- Email is required, trimmed, lowercased before persistence, limited to 254 characters, validated as an email address, and unique case-insensitively.
- Phone is required, accepts an optional leading `+` plus spaces and hyphens for display, contains between 8 and 15 digits, and is stored with only its optional leading `+` and digits.
- Password is between 8 and 255 characters and contains at least one letter and one number, matching the current customer-facing rule without relying on an external password-checking service.
- Password confirmation must match.
- Server-side validation is authoritative; matching client hints improve feedback but never replace it.
- Validation messages and authentication statuses are mapped to Indonesian and English UI strings.

## Security and Privacy

- Use Laravel's password hashing facilities; never hash passwords in Vue or log password input.
- Use cookie-based sessions with `HttpOnly`, `SameSite=Lax`, and `Secure` enabled in production over HTTPS.
- Regenerate session identifiers after registration and login; invalidate sessions and rotate CSRF tokens on logout.
- Protect state-changing requests with Laravel CSRF middleware.
- Rate-limit login, password-reset, verification-resend, and other abuse-sensitive endpoints.
- Return generic login and password-reset responses to reduce account enumeration.
- Accept post-authentication redirects only through Laravel's intended redirect mechanism or explicitly validated internal destinations.
- Render user values through Vue interpolation; do not use untrusted HTML rendering.
- Keep environment secrets in `.env`, commit only `.env.example`, and never commit real mail or database credentials.
- Production requires HTTPS, a non-debug environment, a strong application key, and restricted database credentials.
- Authorization must be enforced on the server in later booking and administration phases; hidden Vue controls never constitute authorization.

## Inertia Shared Data

Laravel middleware shares only the fields required by the interface:

```text
auth.user.id
auth.user.name
auth.user.email
auth.user.phone
auth.user.email_verified_at
locale
flash.status
```

Password hashes, remember tokens, password-reset tokens, internal exception data, and database details are never exposed as Inertia props.

## Internationalization

- Existing Indonesian and English copy is preserved.
- Laravel owns the active locale through a validated session value.
- The selected locale survives navigation and authentication redirects.
- Vue receives the locale and translated strings through a consistent application boundary.
- Validation, verification, password-reset, throttling, and generic error messages are available in both languages.
- Email verification and password-reset notifications use the recipient's selected locale when it is available.

## Error Handling

- Validation errors return to the relevant fields without losing safe form input.
- Password fields are never flashed back to the client.
- Authentication failures use a generic credential error.
- Password-reset requests use a neutral acknowledgement.
- Mail transport failure produces a user-friendly retry message and is logged server-side without exposing credentials.
- Database failures result in a generic service error and structured server logs.
- Expired or invalid signed links route to a clear recovery action.
- Production responses do not expose stack traces, SQL, environment values, or mail configuration.

## Testing Strategy

Implementation follows test-driven development with a failing test observed before each production behavior.

### Backend Feature Tests

- Registration accepts valid customer data and creates exactly one user.
- Registration rejects invalid, duplicate, or case-variant duplicate emails.
- Passwords are hashed and never stored or returned in plain text.
- Registration starts a regenerated authenticated session.
- Verification notifications are sent and protected routes reject unverified users.
- Valid signed links verify users; tampered and expired links fail safely.
- Verification resend is rate-limited.
- Login succeeds with normalized email and correct password.
- Login uses generic errors and throttles repeated failures.
- Login regenerates the session.
- Logout invalidates the session and rotates the CSRF token.
- Password-reset requests do not disclose account existence.
- Valid reset tokens change the password and invalid tokens do not.
- Protected profile access requires authentication and verification.
- Shared Inertia props contain only approved user fields.
- Indonesian and English authentication messages are available.

### Frontend Tests

- Existing visual and source-level regression tests are migrated to their Laravel paths.
- Login, registration, verification, forgot-password, and reset-password forms render their required labels and accessible errors.
- Existing public navigation and responsive behavior remain intact.
- Local-storage authentication code is no longer used.

### End-to-End Tests

- Register, receive a test verification link, verify, and access the profile.
- Log out and log back in.
- Request and complete a password reset.
- Confirm an unverified user cannot open the profile.
- Confirm invalid credentials and unsafe redirect attempts fail safely.

### Verification Commands

- Run Laravel's complete test suite with coverage and require at least 80% coverage for authentication application code.
- Run frontend unit tests.
- Run the production asset build.
- Run browser end-to-end tests for critical authentication flows.
- Run dependency and security checks before delivery.

## Local Development and Deployment

- Local Laravel commands use the installed PHP 8.5 CLI.
- XAMPP MariaDB supplies the local database.
- The Laravel development server is used initially because XAMPP Apache currently loads PHP 8.2, which cannot run Laravel 13.
- Composer must be installed from the official Composer distribution before scaffolding.
- Local mail uses a non-production mail transport or log/testing driver.
- Production hosting must provide PHP 8.3 or newer, the required PHP extensions, HTTPS, MySQL-compatible storage, mail delivery, and a web root pointing to Laravel's `public` directory.
- Environment-specific database, mail, URL, session, queue, and cache values live outside version control.

## Migration Safety

- Record the pre-migration Git status and tests before moving files.
- Preserve the current working tree as the source of truth for user-edited frontend files.
- Scaffold Laravel in an isolated temporary location, then merge required framework files into the repository with explicit patches or reviewed file moves.
- Do not run destructive Git commands or restore deleted frontend pages.
- Do not overwrite the existing design with starter-kit visuals; authentication behavior is reused while the Shin Joy Spa appearance remains authoritative.
- Keep migration changes reviewable and separate framework scaffolding, authentication behavior, and frontend porting where practical.

## Acceptance Criteria

1. The repository runs as a Laravel 13 application with Vue 3, TypeScript, Inertia 3, and MySQL/MariaDB.
2. Existing public pages retain their content, visual design, responsive behavior, bilingual copy, and current user edits.
3. A customer can register with name, unique email, phone, and password.
4. Registration stores a Laravel password hash and never stores password material in browser storage.
5. A registered customer receives a verification notification and must verify before opening `/profile`.
6. A customer can log in, remain signed in through a secure session, and log out.
7. Login and password-reset responses do not disclose whether an email exists.
8. A customer can request and complete an expiring password reset.
9. Authentication, verification, and recovery forms provide accessible Indonesian and English feedback.
10. CSRF protection, session rotation, secure production cookie configuration, input validation, and endpoint rate limiting are in place.
11. No separate frontend deployment, REST API, JWT storage, hardcoded credentials, or legacy PHP authentication path is introduced.
12. Backend, frontend, end-to-end, build, and security verification pass with at least 80% authentication-code coverage.

## Future Phases

- Persist services, service categories, pricing, durations, and media.
- Persist bookings and associate them with authenticated customers or guest contact details.
- Add availability, staff schedules, capacity rules, and booking status transitions.
- Add administrator roles, policies, protected admin authentication, and management screens.
- Add transactional booking emails and optional payment integration.
