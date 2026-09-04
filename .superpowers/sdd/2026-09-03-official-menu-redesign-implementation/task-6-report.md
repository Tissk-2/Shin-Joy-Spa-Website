# Task 6 report

## RED

`npx vitest run tests/frontend/serviceCatalog.test.ts tests/frontend/language.test.ts` failed as expected before implementation: the mounted homepage still required category/service props, the `#menu` anchor and Menu navigation were absent, and official-menu translation keys were undefined.

## GREEN

- Focused Vitest: 3 files, 10 tests passed.
- Legacy translation parity command ran; its translation parity check passed. Two unrelated legacy checks remain red: the standalone static-build fixture and existing compact-control sizing assertion.
- Global typecheck remains red only in unchanged legacy compatibility files: `ServiceCard.vue`, `Booking.vue`, and `ServiceDetail.vue` import retired `ServiceData`/`ServiceCategoryData` exports. Task 6-owned files produced no diagnostics.

## Design self-review

The homepage has exactly two `PackageMenuCard` panels at `#menu`, with canonical package links, a starting price, a restrained standard/VIP comparison, official location/map query, and bilingual hot-water policy in both menu and FAQ. `menu.css` is scoped to public menu classes, uses the approved ivory/bronze/espresso/gold system, includes a VIP text badge, keyboard focus treatment, 2-to-1 responsive grid, 44px option controls, safe-area mobile CTA, and reduced-motion override.
