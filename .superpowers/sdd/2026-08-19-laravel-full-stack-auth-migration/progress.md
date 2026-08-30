# SDD ledger — plan: docs/superpowers/plans/2026-08-19-laravel-full-stack-auth-migration.md

Execution consent: User explicitly approved working in the current checkout on `main` on 2026-08-19 because the uncommitted frontend is the migration source of truth.
Baseline: HEAD `1ffb2e05d07e1165c6f16e8cb1e8237e92f13bad`; legacy frontend tests 41/42 pass with the known typography hit-area assertion failing; Vite production build passes.

## Preflight task consistency

| Task | Internal agreement check | Finding / resolution |
| --- | --- | --- |
| 1 | Characterization test, scaffold files, environment config, and verification commands align. | Official starter generation is interactive and may change small generated filenames. Ruling recorded below. |
| 2 | Normalizer signatures match schema/model tests and downstream consumers. | Clean. |
| 3 | Registration tests match normalization, validation, hashing, session, and notification behavior. | Verification notification class changes in Task 5. Ruling recorded below. |
| 4 | Login tests match normalized lookup, session behavior, intended redirects, logout, and throttle. | `/profile` page is not implemented until Task 9. Tests may assert redirects without rendering it. |
| 5 | Signed-link tests match custom notification and verified access. | `/profile` route precedes the Vue page. Ruling recorded below. |
| 6 | Neutral response contracts, reset notification, and reset action match tests. | Clean. |
| 7 | Locale route/middleware, dictionaries, and safe shared props match tests. | Clean. |
| 8 | Inertia shell, TypeScript helpers, CSS, and tests match the target structure. | Vitest/Vue Test Utils installation is omitted from earlier tasks. Ruling recorded below. |
| 9 | Laravel routes and public page ports match backend/frontend tests. | Browser-local booking history is intentionally omitted per spec non-goal. |
| 10 | Fortify views and form endpoints match server-backed auth pages. | Clean. |
| 11 | Security tests, Playwright flows, audits, and production checks agree. | Known legacy typography failure must be resolved before this full matrix. Ruling recorded below. |
| 12 | Inventory test, verified removal, documentation, and final matrix agree. | Removal is recoverable from Git and occurs only after equivalence tests. |

## Preflight shared-file and interface map

| Producer -> consumer | Shared file / interface | Finding |
| --- | --- | --- |
| 1 -> 2 | Generated `User` model and users migration | Task 2 owns customer-specific changes; clean. |
| 1 -> 3 | Generated Fortify actions/tests | Task 3 customizes generated registration; clean. |
| 1 -> 4 | `config/fortify.php`, provider, routes | Task 4 owns login-specific customization; clean. |
| 1 -> 5 | Fortify verification routes and generated user behavior | Task 5 replaces defaults; clean. |
| 1 -> 6 | Fortify reset routes/actions and response contracts | Task 6 replaces defaults; clean. |
| 1 -> 7 | Inertia middleware and `bootstrap/app.php` | Task 7 extends generated middleware stack; clean. |
| 1 -> 8 | `resources/js/app.ts`, package scripts, Vite config | Task 8 replaces starter UI while retaining runtime; dependency ruling applies. |
| 1 -> 11 | `composer.json`, `package.json`, `.env.example` | Task 11 extends scripts/dependencies/security settings; clean. |
| 2 -> 3 | `CustomerIdentity`, `users.phone`, fillable fields | Exact signatures match Task 3; clean. |
| 2 -> 4 | Normalized stored email and password hash | Task 4 queries exact normalized email; clean. |
| 2 -> 5 | `MustVerifyEmail` user contract | Task 5 consumes it directly; clean. |
| 2 -> 6 | User notification hooks and password fields | Task 6 consumes them directly; clean. |
| 2 -> 7 | Safe public user fields | Shared prop field list matches schema; clean. |
| 3 -> 5 | Registration-triggered verification notification | Built-in notification first, branded notification later; ruling applies. |
| 3 -> 6 | Shared password policy | Reset action consumes the same rules; clean. |
| 3 -> 10 | Registration fields/endpoints/errors | Vue form field names match Fortify inputs; clean. |
| 4 -> 5 | Authenticated unverified session | Verification notice consumes session; clean. |
| 4 -> 9 | `/profile` redirect target | Route/page ordering ruling applies. |
| 4 -> 10 | `/login` and `/logout` endpoints | Vue forms match exact endpoints; clean. |
| 4 -> 11 | Throttling, redirect, and session E2E cases | Clean. |
| 5 -> 10 | Verification notice/resend/logout UI | Exact Fortify endpoints match; clean. |
| 5 -> 11 | Signed verification E2E flow | Testing-only URL helper preserves production boundary; clean. |
| 6 -> 7 | Reset/recovery translation keys | Dictionaries provide exact keys; clean. |
| 6 -> 10 | Forgot/reset form endpoints and props | Exact fields/routes match; clean. |
| 6 -> 11 | Reset token E2E flow | Testing-only URL helper preserves production boundary; clean. |
| 7 -> 8 | `locale` and safe `auth.user` Inertia props | TypeScript interface matches exact PHP shape; clean. |
| 7 -> 10 | Locale and status props for auth pages | Clean. |
| 7 -> 11 | Environment and shared-prop security checks | Clean. |
| 8 -> 9 | Main layout, components, data, helpers, styles | Task 9 ports pages onto this shell; clean. |
| 8 -> 10 | Auth CSS, language composable, Inertia app | Auth pages consume the same design system; clean. |
| 8 -> 12 | Old `src` runtime equivalence | Task 12 removes only after Tasks 8–11 verify; clean. |
| 9 -> 11 | Public/profile routes and browser flows | Clean. |
| 9 -> 12 | Ported pages versus old `src/Pages` | Removal gate is explicit; clean. |
| 10 -> 11 | Auth UI browser flows | Clean. |
| 10 -> 12 | Ported auth pages versus old Vue pages | Removal gate is explicit; clean. |
| 11 -> 12 | Full verification matrix and security evidence | Task 12 repeats the final matrix after cleanup; clean. |

## Preflight rulings

Ruling: Execute on `main` in the current checkout — the user explicitly consented and the dirty working tree contains required frontend work that a clean worktree would omit — cost if wrong: feature commits share the main branch and require normal Git reversal rather than worktree disposal.
Ruling: Treat the uncommitted plan file as the controlling artifact and never include it accidentally in a task commit — two narrowly scoped commit attempts were denied by approval-review timeouts — cost if wrong: the plan remains a working-tree file until a later approved documentation commit.
Ruling: Task 8 installs `vitest`, `@vue/test-utils`, and `jsdom` before its RED tests — the plan specifies these tests but omits their installation — cost if wrong: three development dependencies are added earlier than Task 11.
Ruling: Task 3 asserts Laravel's built-in `Illuminate\Auth\Notifications\VerifyEmail`; Task 5 switches the assertion and user hook to `App\Notifications\VerifyEmail` — this preserves TDD sequencing — cost if wrong: Task 5 must update an import and test expectation.
Ruling: Task 5 may register the verified `/profile` Inertia route before `Profile.vue` exists; backend access tests can verify middleware/response metadata and Task 9 supplies the component — cost if wrong: direct browser rendering of `/profile` remains unavailable between Tasks 5 and 9.
Ruling: The known legacy typography hit-area failure must be corrected while porting the design system in Task 8 and must pass by Task 11 — it is a real accessibility regression, not a tolerated final baseline — cost if wrong: Task 8 includes one CSS/test correction beyond mechanical porting.
Ruling: The Task 1 implementer may answer current official starter-kit feature prompts or adapt generated-file names while preserving Laravel 13, Vue, Inertia, built-in auth, and the explicit exclusions — current starter scaffolding is interactive and versioned — cost if wrong: review may require removing unwanted generated packages or files.

Task 1: dispatched at base `1ffb2e05d07e1165c6f16e8cb1e8237e92f13bad` to `/root/task_1_laravel_scaffold` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-1-brief.md`.
Task 1: complete (commits `2e08fef`, `efd8e20`; independent review clean after one blocking Vitest-isolation fix).
Task 2: dispatched at base `efd8e2009ba5de84d93f1126af3c3325a79720af` to `/root/task_2_customer_identity` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-2-brief.md`.
Task 2: complete (commit `aebdc11`; independent review clean, with the plan-explicit factory/registration RED state assigned to Task 3).
Task 3: dispatched at base `aebdc11` to `/root/task_3_registration` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-3-brief.md`.
Task 3: complete (commits `08a969a`, `5959d15`; independent review clean after adding the required phone field to the live registration form).
Task 4: dispatched at base `5959d15` to `/root/task_4_sessions` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-4-brief.md`.
Task 4: complete (commit `92ec29f`; independent review clean, with fail-closed default-port canonicalization noted as non-blocking).
Task 5: dispatched at base `92ec29f` to `/root/task_5_verification` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-5-brief.md`.
Task 5: complete (commits `d6e4841`, `ab0a03e`; security re-review clean after pinning signed verification URLs to trusted `APP_URL`).
Task 6: dispatched at base `ab0a03e` to `/root/task_6_password_recovery` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-6-brief.md`.
Task 6: complete (commit `b1e03ab`; independent privacy/security review clean, with IP-only throttle cross-user coupling noted as non-blocking).
Task 7: dispatched at base `b1e03ab` to `/root/task_7_locale_props` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-7-brief.md`.
Task 7: complete (commits `f8097a9`, `0f8eb77`; independent re-review clean after aligning verification mail with the validated Indonesian default).
Task 8: dispatched at base `0f8eb77` to `/root/task_8_inertia_shell` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-8-brief.md`.
Task 8: complete (commits `5e03d12`, `6e0bf08`; independent re-review clean after correcting compiled CSS order and Linux-safe Vitest discovery).
Task 9: dispatched at base `6e0bf08` to `/root/task_9_public_pages` using `.superpowers/sdd/2026-08-19-laravel-full-stack-auth-migration/task-9-brief.md`.
Task 9: complete (commits `0868b05`, `d180b0f`; re-review clean after removing the unfinished public admin entry point and adding `/admin`/`/admin-login` route coverage).

## 2026-08-28 auth completion preflight

| Task / interface pair | Agreement check | Finding / resolution |
| --- | --- | --- |
| Task 10 | Six auth pages, endpoints, locale, and accessibility against current files | Original brief omitted the live Fortify Confirm Password page and assumed test dependencies existed. Binding addendum includes ConfirmPassword and installs Vue Test Utils/jsdom. |
| Task 10 -> Task 10b | Register form payload feeds normalized server registration | Clean; UI retains exact `name`, `email`, `phone`, `password`, `password_confirmation` fields before backend limiter work. |
| Task 10b | Dual limiter, shared phone, email collision behavior | Clean after explicit user decision that phone numbers may be shared. |
| Task 10/10b -> Task 11 | Completed auth UI/backend consumed by Playwright and security gates | Clean; Task 11 starts only after both task reviews are clean. |
| Task 11 -> original Task 12 | Production/E2E work versus old runtime cleanup | User's new approved boundary excludes deletion of the old dirty `src/` tree, so execution stops after auth safeguards and final auth review. |

Ruling: Continue in the current `main` checkout — the user re-approved implementation and the dirty branded auth plus `src/` files are authoritative inputs — cost if wrong: reversal uses Git commits rather than worktree disposal.
Ruling: The approved 2026-08-28 Client Authentication Completion Plan supplements original Tasks 10–11 and excludes original Task 12 cleanup — it is the latest user intent — cost if wrong: obsolete runtime cleanup remains for a later task.
Ruling: Install Vue Test Utils and jsdom in Task 10, correcting the stale earlier ledger assumption that Task 8 installed them — current package manifests prove they are absent — cost if wrong: Task 10 owns two additional development dependencies.
Ruling: Phone numbers remain non-unique and receive an explicit shared-phone regression test — the user selected shared phones — cost if wrong: multiple accounts can intentionally share contact data.
Ruling: Registration uses dual throttles of 3/minute per normalized email+IP and 20/hour per IP — the user selected the balanced policy — cost if wrong: high-volume legitimate signups behind one IP may receive HTTP 429.

Task 10: dispatched from base `d180b0f` using the binding addendum in `task-10-brief.md`.
Task 10: fix round 1/5 (2 findings addressed, 1 open — Login cleanup still asserted internal double state; commits `38228a6`..`ec77f52`).
Task 10: fix round 2/5 (remaining Login DOM-cleanup finding addressed; commit `d85128e`).
Task 10: complete (commits `38228a6`, `ec77f52`, `d85128e`; spec PASS and quality APPROVED after scoped re-review).
Task 10b: dispatched from base `d85128e` using `task-10b-brief.md`.
Task 10b: fix round 1/5 (overbroad collision mapping and generic direct-action test addressed; commit `bcc66bf`).
Task 10b: complete (commits `10ad3f5`, `bcc66bf`; spec PASS and quality APPROVED after security-focused re-review).
Task 11: dispatched from base `bcc66bf` using the binding addendum in `task-11-brief.md`.
Task 11: complete (generated hot/database/runtime artifacts now cleaned and ignored; marker/SIGINT/SIGTERM/exit cleanup stops only wrapper-owned children; focused E2E 1/1 and exact full E2E 6/6 both returned natural exit 0; independent re-review clean; scoped commit hash reported in handoff).
