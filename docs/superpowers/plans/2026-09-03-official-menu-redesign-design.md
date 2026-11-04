# Official Menu Website Redesign

**Date:** 2026-09-03  
**Status:** Approved design  
**Application:** Laravel 13, Inertia, Vue 3, TypeScript

## Purpose

Replace the placeholder twelve-service catalog with the complete official Shin Joy Spa menu and redesign the public website around the two real packages: Nezuko and Nezuko VIP. The result must preserve the existing branded Laravel application, support Indonesian and English, work cleanly from mobile through desktop, and prepare the catalog for authenticated booking and later admin CRUD without adding payment processing now.

The two supplied official menu posters are the authoritative source for package structure, prices, address, and the hot-water disruption policy. Their content will be rebuilt as accessible responsive HTML rather than embedded as text-heavy poster images.

## Approved Product Decisions

- The supplied Nezuko and Nezuko VIP posters are the complete official menu.
- The current twelve placeholder services are not part of the real offering and will be retired.
- Package names remain `Nezuko` and `Nezuko VIP` in both languages.
- Official descriptions and interface labels will receive professional English translations.
- Obvious Indonesian spelling and grammar issues may be corrected without changing meaning.
- `Extra 60 Menit` applies only to regular treatment.
- `Extra 30 Menit` applies only to double-therapist treatment.
- Booking requires an authenticated account with a verified email address.
- A treatment price is not multiplied by guest count.
- The conditional 10% hot-water disruption discount is displayed as a policy and is not calculated automatically.
- Payment gateway work remains outside this milestone and will be completed last.
- The future admin CRUD must be able to manage the normalized package choices, but admin UI work is not part of this redesign.

## Information Architecture

### Shared navigation

Rename the public `Services` navigation concept to `Menu`. The primary menu link targets the official-menu section on the homepage. Shared footer links list the two packages rather than the obsolete category collection.

### Homepage

1. **Hero**
   - Premium spa photography from the existing asset library.
   - Concise value proposition.
   - `Mulai dari Rp199.000` / `Starting from Rp199,000` price anchor.
   - Primary booking action and secondary menu action.

2. **Official Menu**
   - Two large editorial package panels: Nezuko and Nezuko VIP.
   - Each panel includes its description, benefits, three regular duration tiers, the regular extension, the double-therapist treatment, its extension, a detail link, and a booking action.

3. **Package Comparison**
   - A compact explanation of the difference between the standard and VIP offerings.
   - Avoid a dense feature matrix because the official source only distinguishes the packages by positioning, tailored experience, and pricing.

4. **Supporting Sections**
   - Retain and visually refine About, Gallery, Testimonials, FAQ, and Contact.
   - Remove statistics and copy that imply a broader service catalog than the official menu.

5. **Contact and Policy**
   - Replace the placeholder address with `Jalan Terusan Batubara No. 12, Lantai 2`.
   - Update the map query to the official address.
   - Display the 10% hot-water disruption policy near the menu and in FAQ/contact context without treating it as an always-available promotion.

### Package details

Keep two indexable detail routes:

- `/services/nezuko`
- `/services/nezuko-vip`

Each detail page presents the package story, benefits, imagery, selectable regular duration tiers, a distinct double-therapist option, valid extensions, total duration/price preview, and a configured booking action. The old same-category related-service grid becomes a direct comparison link to the other package.

### Booking handoff

Menu and detail selections hand off package, mode, duration, and optional extension to the booking page. The canonical query shape is:

```text
/booking?service=nezuko-vip&mode=regular&duration=90
```

An extension may be added only through a validated identifier. Laravel treats query parameters as untrusted input and resolves the canonical active database records before returning page props.

## Official Menu Content

### Nezuko

**Indonesian description**

> Pijatan lembut dengan perpaduan pijat tradisional dan pijat Thailand, menggunakan minyak atau tanpa minyak, yang menghadirkan sensasi relaksasi.

**English description**

> A gentle treatment combining traditional and Thai massage techniques, with or without oil, designed to create a deeply relaxing experience.

**Indonesian benefit**

> Membantu memperlancar peredaran darah dan meningkatkan kualitas tidur.

**English benefit**

> Helps support healthy circulation and improve sleep quality.

### Nezuko VIP

**Indonesian description**

> Perpaduan pijat tradisional dan pijat Thailand, menggunakan minyak atau tanpa minyak, yang disesuaikan dengan kebutuhan dan keluhan klien untuk menghadirkan sensasi relaksasi.

**English description**

> A tailored blend of traditional and Thai massage, with or without oil, adjusted to the client's needs and preferences for a deeply relaxing experience.

**Indonesian benefit**

> Membantu memperlancar peredaran darah dan meningkatkan kualitas tidur.

**English benefit**

> Helps support healthy circulation and improve sleep quality.

### Exact prices

| Package    | Mode             | Base duration | Base price |        Valid extension |
| ---------- | ---------------- | ------------: | ---------: | ---------------------: |
| Nezuko     | Regular          |    60 minutes |  Rp199.000 |  +60 minutes, Rp75.000 |
| Nezuko     | Regular          |    90 minutes |  Rp265.000 |  +60 minutes, Rp75.000 |
| Nezuko     | Regular          |   120 minutes |  Rp315.000 |  +60 minutes, Rp75.000 |
| Nezuko     | Double therapist |    60 minutes |  Rp250.000 | +30 minutes, Rp100.000 |
| Nezuko VIP | Regular          |    60 minutes |  Rp299.000 | +60 minutes, Rp100.000 |
| Nezuko VIP | Regular          |    90 minutes |  Rp349.000 | +60 minutes, Rp100.000 |
| Nezuko VIP | Regular          |   120 minutes |  Rp499.000 | +60 minutes, Rp100.000 |
| Nezuko VIP | Double therapist |    60 minutes |  Rp350.000 | +30 minutes, Rp100.000 |

## Visual System

### Direction

Use a premium editorial comparison layout rather than a literal poster reproduction or a form-first configurator. The interface should feel recognizably connected to the official menu while remaining calm, spacious, and easy to scan.

### Color and hierarchy

- Warm ivory page backgrounds.
- Muted bronze package surfaces.
- Espresso primary text and dark surfaces.
- Restrained gold borders and accents.
- Nezuko uses a lighter bronze/ivory treatment.
- Nezuko VIP uses a deeper espresso/bronze treatment with a clear gold VIP badge.
- Text and controls must meet WCAG AA contrast; color is never the only differentiator.

### Typography

Retain the existing local `Marcellus` and `Mulish` fonts. Marcellus is reserved for package names, prices, and major headings. Mulish handles body copy, labels, navigation, and form controls.

### Motif and media

Use one restrained curved-line motif inspired by the official posters at the transition into the menu. Do not reproduce every decorative wave. Reuse suitable existing spa photography; the supplied posters are reference material, not rasterized page content.

### Responsive behavior

- Desktop: balanced two-package comparison with generous whitespace.
- Tablet: maintain comparison where width permits, then stack before text or price controls become cramped.
- Mobile: stack packages, present prices as full-width selection rows or buttons, and avoid horizontal scrolling.
- Touch targets are at least 44 by 44 CSS pixels.
- A compact sticky mobile booking action appears only after a valid package option is selected.
- Respect `prefers-reduced-motion` for all reveal and selection transitions.
- Preserve visible focus states and semantic heading order.

## Catalog Data Design

The `services` record becomes the package-level entity. Scalar duration and price are no longer the public source of truth.

### Package variants

Add a child table for selectable base variants with:

- package/service foreign key;
- stable public code;
- treatment mode (`regular` or `double_therapist`);
- duration in minutes;
- price in IDR as an integer;
- active flag;
- display order;
- timestamps;
- a uniqueness constraint preventing duplicate package/mode/duration combinations.

### Extensions

Add a child table for extensions with:

- package/service foreign key;
- stable public code;
- applicable treatment mode;
- additional duration in minutes;
- price in IDR as an integer;
- active flag;
- display order;
- timestamps;
- a uniqueness constraint preventing duplicate package/mode/duration extensions.

An extension is valid only when its package and treatment mode match the selected base variant. This relationship is checked both when page props are built and when a future booking is persisted.

### Public data contract

Public Inertia props expose only presentation-safe fields:

- package slug and localized content;
- image and gallery paths;
- ordered active variants;
- ordered active extensions;
- package positioning such as VIP state.

Internal IDs, foreign keys, timestamps, and inactive catalog rows are not exposed. Prices and durations remain integers in transport and are formatted by the UI.

### Transition strategy

- Add forward-only migrations rather than editing existing migrations.
- Seed the two official package records and their exact variants/extensions idempotently.
- Retire only the known placeholder catalog slugs; do not broadly delete unknown or future admin-created records.
- Stop rendering public categories, while retaining the internal category relationship for compatibility with the later admin milestone.
- Do not touch the developer's real `.env`.

## Booking Configuration Behavior

The configurator follows this order:

1. Select Nezuko or Nezuko VIP.
2. Select regular or double therapist.
3. Select one available base duration.
4. Optionally select the extension valid for that mode.
5. Review the calculated total duration and estimated price.
6. Continue with authenticated customer, date, and time details.

Changing package or treatment mode clears any now-invalid duration or extension rather than silently retaining an incompatible value. Calculation functions return new values and do not mutate shared catalog props.

The booking page and booking actions require `auth` and `verified` middleware. Laravel's intended redirect carries a safe local configured booking URL through login and email verification. Hostile or external intended redirects remain rejected by the existing authentication safeguards.

No client storage is used for authentication, pricing, or booking history. The server remains authoritative. Actual booking persistence belongs to the next booking-domain milestone unless separately approved as part of implementation planning.

## Error Handling and Edge Cases

- Unknown or inactive package detail routes return 404.
- Unknown, inactive, malformed, or incompatible booking query values are ignored or normalized to a safe incomplete configuration; they never alter server pricing.
- A package with no active variants is not publicly selectable.
- An unavailable extension disappears from choices and cannot be submitted.
- A stale deep link is re-resolved against current active database values.
- Missing package images fall back to an approved local spa image without exposing filesystem details.
- Indonesian and English contracts remain structurally identical.
- The hot-water policy is informational and never changes the displayed booking estimate automatically.

## Testing Strategy

Follow test-driven development: focused failing test, minimal implementation, refactor, then full verification.

### Laravel feature and persistence tests

- Exactly two active official packages after a fresh seed.
- Eight active base variants and four active extensions with exact official prices.
- Idempotent seeding and targeted retirement of known placeholders.
- Package, variant, and extension relationships and casts.
- Public ordering and active-scope filtering.
- Safe exact Inertia prop allowlists for home, detail, and booking pages.
- Inactive packages/options are not public.
- Valid and hostile booking deep-link handling.
- Authenticated and verified booking-page access.
- Safe intended redirect preservation for a configured booking URL.

### Vue component tests

- Both package panels and translated descriptions render.
- Locale switching changes copy without changing prices or package names.
- Mode and duration selection states.
- Extensions appear only for their matching mode.
- Package/mode changes clear incompatible selections.
- Correct total duration and price calculation.
- Configured detail and booking links.
- Keyboard interaction, accessible names, error associations, and processing states.
- Sticky mobile CTA state is tied to a valid selection.

### Playwright flow

- Homepage to Nezuko selection to configured booking.
- Homepage to Nezuko VIP/double-therapist selection to configured booking.
- Guest selection redirects to login and returns to the same safe configuration after authentication/verification.
- Indonesian/English switching preserves the selected package options.
- Invalid or hostile configuration values cannot produce an unauthorized price or external redirect.

### Final gates

- `php artisan test`
- `php artisan test --coverage --min=80` when a PHP coverage driver is available
- `npm run test:frontend`
- `npm run test:e2e`
- `npm run types:check`
- `npm run build`
- route/config cache and clear checks
- Composer and npm security audits
- forbidden-runtime and secret scans
- fresh code-quality and security reviews

A missing PHP coverage driver is reported as an environment blocker, never as a passing coverage result.

## Scope Boundaries

Included:

- official catalog replacement;
- public layout redesign;
- normalized package option data;
- bilingual copy;
- configured authenticated booking handoff;
- tests and production safeguards directly affected by the redesign.

Excluded:

- payment gateway;
- admin CRUD screens;
- therapist schedules, room capacity, cancellation, or rescheduling;
- notifications;
- broader authorization redesign;
- rewriting the legacy standalone `src/` application;
- modifying unrelated dirty workspace files.

## Acceptance Criteria

The redesign is complete when the public application presents only the two official packages and their exact valid prices, communicates regular and double-therapist choices without ambiguity, works bilingually and responsively, hands a server-validated configuration into verified-account booking, uses the official address and policy, passes the relevant automated gates, and leaves payment/admin work cleanly deferred.
