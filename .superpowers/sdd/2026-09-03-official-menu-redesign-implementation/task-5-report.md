# Task 5 report — package selection controls

## Scope

Created only the six owned Task 5 files:

- `resources/js/lib/serviceSelection.ts`
- `resources/js/lib/media.ts`
- `resources/js/components/PackageConfigurator.vue`
- `resources/js/components/PackageMenuCard.vue`
- `tests/frontend/serviceSelection.test.ts`
- `tests/frontend/officialMenuComponents.test.ts`

## TDD record

- **RED (utilities):** `npx vitest run tests/frontend/serviceSelection.test.ts --reporter=verbose` failed because `@/lib/serviceSelection` did not exist.
- **GREEN (utilities):** exact variant/extension normalization, totals, booking URL generation, immutable state transitions, and one-shot image fallback pass.
- **RED (components):** `npx vitest run tests/frontend/serviceSelection.test.ts tests/frontend/officialMenuComponents.test.ts --reporter=verbose` failed because `@/components/PackageConfigurator.vue` did not exist.
- **GREEN (components):** four focused assertions pass for role-based mode/duration controls, mode-compatible options, native extension checkbox, exact total/URL, disabled and mobile CTA states, localized editorial card content, fallback media, and prop immutability.

## Verification

| Command | Result |
| --- | --- |
| `npx vitest run tests/frontend/serviceSelection.test.ts tests/frontend/officialMenuComponents.test.ts --reporter=verbose` | PASS — 2 files, 4 tests |
| `git diff --check` | PASS |
| `npm run types:check` | Sequencing-red, with no Task 5 diagnostics |

`npm run types:check` reports only unchanged legacy/public-layout ownership for Tasks 6–8:

- `resources/js/components/ServiceCard.vue` — stale `ServiceCategoryData` / `ServiceData` imports
- `resources/js/layouts/MainLayout.vue` — stale `catalog.categories`
- `resources/js/pages/Booking.vue` — stale `ServiceData` import
- `resources/js/pages/Home.vue` — stale `ServiceCategoryData` / `ServiceData` imports
- `resources/js/pages/ServiceDetail.vue` — stale `ServiceCategoryData` / `ServiceData` imports

No diagnostics name `serviceSelection.ts`, `media.ts`, `PackageConfigurator.vue`, `PackageMenuCard.vue`, or either Task 5 test file.

## Fix round 1

- **RED:** The expanded localized CTA regression test failed in both English and Indonesian when `v-if="showBookingLink"` was temporarily removed: valid hidden-link configurations still rendered the desktop CTA (assertion at `officialMenuComponents.test.ts:243`).
- **GREEN:** Restoring the conditional CTA boundary made the focused suite pass with 2 files and 7 tests. The test now checks disabled desktop/mobile invalid messages in both locales, no CTA or invalid wording for valid hidden-link configurations, real host `v-model` updates, all Nezuko regular/double choices and compatible extensions, immutable package data, and Arrow/Home/End selection plus focus.
- The radio navigation helper now explicitly returns before modulo/index selection when a radiogroup has no radios.

## Self-review

- Selection functions construct fresh objects and never mutate package props or model values.
- Base variants and extensions are resolved by exact package, mode, duration, and extension code compatibility.
- Booking links are absent until a valid base selection exists; both desktop and mobile CTAs are disabled otherwise.
- The fallback only uses the approved local public URL once and exposes no filesystem path.
