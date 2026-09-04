# Task 6 report

## RED

`npx vitest run tests/frontend/serviceCatalog.test.ts tests/frontend/language.test.ts` failed as expected before implementation: the mounted homepage still required category/service props, the `#menu` anchor and Menu navigation were absent, and official-menu translation keys were undefined.

## GREEN

- Focused Vitest: 3 files, 10 tests passed.
- Legacy translation parity command ran; its translation parity check passed. Two unrelated legacy checks remain red: the standalone static-build fixture and existing compact-control sizing assertion.
- Global typecheck remains red only in unchanged legacy compatibility files: `ServiceCard.vue`, `Booking.vue`, and `ServiceDetail.vue` import retired `ServiceData`/`ServiceCategoryData` exports. Task 6-owned files produced no diagnostics.

## Design self-review

The homepage has exactly two `PackageMenuCard` panels at `#menu`, with canonical package links, a starting price, a restrained standard/VIP comparison, official location/map query, and bilingual hot-water policy in both menu and FAQ. `menu.css` is scoped to public menu classes, uses the approved ivory/bronze/espresso/gold system, includes a VIP text badge, keyboard focus treatment, 2-to-1 responsive grid, 44px option controls, safe-area mobile CTA, and reduced-motion override.

## Fix round 1

### RED

The new CTA/static and active-dictionary tests exposed that menu CTA anchors had no explicit flex hit-area styling and the promised neutral review/unavailable keys were absent.

### GREEN

- Focused Vitest: 3 files, 11 tests passed.
- Direct active-dictionary parity: `node --test tests/frontend/translation-parity.test.mjs` passed.
- `git diff --check` passed.
- Typecheck retains only the existing allowed diagnostics in unchanged `ServiceCard.vue`, `Booking.vue`, and `ServiceDetail.vue`; no Task 6-owned diagnostics.

The obsolete Couple Retreat FAQ was replaced with honest availability guidance. The menu CTA anchor is explicitly centered, 44px minimum-height, and full-width/flex in the mobile sticky bar. Active translation parity now reads `resources/js/data/translations.ts`; package names were removed from translation values and comparison labels come from package props.

## Copy polish

The hero subtitle now uses generic official-menu wording in Indonesian and English, so package names remain data-owned. A focused regression assertion scans every active translation value for exact package names. RED failed on the former Indonesian hero subtitle; GREEN passed with `npx vitest run tests/frontend/language.test.ts` (3 tests), direct active-dictionary parity, and `git diff --check`.
