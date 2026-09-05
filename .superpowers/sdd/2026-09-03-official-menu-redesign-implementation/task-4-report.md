# Task 4 — Protected configured booking handoff

## TDD evidence

- RED: `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/EmailVerificationTest.php` failed as expected with five failures: booking remained public, did not expose normalized `selection` props, unverified login did not retain configured intent, and the verification prompt honored an external intended URL.
- GREEN: `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/PublicPagesTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/EmailVerificationTest.php` passed with 26 tests and 191 assertions.

## Implementation

- Added immutable `BookingSelectionResolver`, which progressively validates active package, treatment mode, duration, and matching mode-specific extension. Query duration is accepted only as a 1–3 digit scalar string.
- Added centralized `SafeIntendedPath` policy. It retains only valid local intent for unverified users and consumes validated local intent after login or verification; external, protocol-relative, malformed, and backslash-containing values are discarded.
- Protected the booking route with `auth` and `verified`, exposed package-shaped booking props and normalized selection, and redirected `/services` to `/#menu`.
- Registered owned verification endpoints after Fortify’s defaults so the custom safe-redirect policy actually handles prompt, signed verification, and notification requests.
- Removed Task 3's temporary presenter `service()` bridge after confirming it had no usages.

## Security self-review

- No query value is used as a price, database identifier, or authorization input. Selection is resolved only against active, eagerly loaded server records.
- Cross-mode extensions, inactive extension codes, and array duration input resolve to empty selection fields rather than being coerced.
- Safe redirects allow a single-slash local path or an exact configured-app origin; protocol-relative URLs, external origins, credentials, and backslashes are rejected.
- `git diff --check` is clean; only Git line-ending notices were emitted.

## Security fix round 1

- RED: Added regressions for a same-origin absolute `//attacker.example` path, raw and percent-encoded control characters, authenticated-unverified booking access, hostile selection arrays, inactive service/variant values, and already-verified prompt/resend behavior. The focused suite failed as expected: the initial safe-path policy returned the normalized protocol-relative path and raw control-byte target.
- GREEN: `php artisan test tests/Feature/ServiceCatalogPagesTest.php tests/Feature/PublicPagesTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/EmailVerificationTest.php` passed with 32 tests and 285 assertions.
- `SafeIntendedPath` now validates both the original value and the post-origin-normalization target. It rejects raw or percent-decoded C0/DEL controls and backslashes, requires the final path to be exactly single-slash local before emitting it, and rejects encoded paths that normalize to protocol-relative or backslash forms.
- Security self-review: selection array values are rejected without coercion; inactive services and variants clear progressively; unverified booking requests reach the verification notice; prompt and resend use the same hostile-intent policy. No redirect result can contain raw or decoded control bytes, a backslash, or a protocol-relative path.

## Final integration limiter fix

- Root cause: the custom verification-route overrides that enforce `SafeIntendedPath` used a hardcoded `throttle:6,1`, bypassing Fortify's configured verification limiter (`1,1`) and allowing repeated resend notifications.
- RED: the resend rate-limit regression returned 302 instead of 429, and the new route-policy regression found `throttle:6,1` rather than `throttle:1,1` on both signed verification and resend routes.
- GREEN: both overrides now derive one limiter from `config('fortify.limiters.verification', '6,1')`; the targeted verification, email-verification, and security-configuration suite passed (20 tests, 92 assertions), followed by the full suite (136 tests, 1,009 assertions).
- Security self-review: both custom routes preserve `auth`; signed verification also preserves `signed`. They share the same configured limiter so configuration changes cannot silently cause divergent verification and notification policies.
