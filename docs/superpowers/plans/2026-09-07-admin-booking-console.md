# Admin Booking Console Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a secure, bilingual administrator console where verified staff can find persisted bookings, inspect their operational details, and apply audited forward-only status changes.

**Architecture:** Keep Fortify and the existing `web` session as the only authentication system. Add an explicit role boundary and a second policy check, then place all status changes behind one transactional action that locks the booking and inserts an immutable audit row. Inertia controllers return allow-listed presenter output to two new Vue pages under a dedicated admin layout.

**Tech Stack:** PHP 8.3+, Laravel 13, Fortify, Eloquent, Inertia 3, Vue 3, TypeScript, Vitest with Vue Test Utils, Playwright, SQLite for automated tests, and MySQL-compatible migrations.

**Spec:** `docs/superpowers/specs/2026-09-07-admin-booking-console-design.md`

## Global Constraints

- Keep Fortify as the only owner of credential login, logout, registration, verification, and password recovery.
- Use the existing `users` table with exactly two backed roles: `customer` and `admin`.
- Public registration and profile requests must never accept or change `role`.
- Require `auth`, `verified`, and `admin` middleware for every admin content or mutation route. Keep only `GET /admin/login` outside the admin group.
- Enforce `BookingPolicy` inside controllers or form requests even though routes already use admin middleware.
- Allow only `pending -> confirmed`, `pending -> cancelled`, `confirmed -> completed`, and `confirmed -> cancelled`.
- Treat `completed` and `cancelled` as terminal states.
- Update a booking and insert its audit record in one database transaction after locking the booking row.
- Do not expose database IDs, idempotency data, request fingerprints, audit IP addresses, audit user agents, exception text, or configuration in Inertia props.
- Keep the appointment timezone fixed to `Asia/Jakarta` for Phase 2A queries and counts.
- Return 20 bookings per index page and preserve active filters in pagination links.
- Keep Indonesian and English translation keys in recursive parity.
- Do not use Vue Router, `localStorage`, `sessionStorage`, a client auth store, `v-html`, or `window.confirm` in Laravel runtime admin files.
- Do not add booking deletion, booking field editing, catalog CRUD, scheduling capacity, notifications, customer management, or payment behavior.
- Do not modify the developer's real `.env`.
- Keep the existing retired files under `src/` untouched. They are reference material, not Laravel runtime code.
- Start each behavior with a focused failing test. Run a fresh code-quality review after every task and a security review after Tasks 3, 5, and 11.
- Require at least 80 percent PHP coverage when Xdebug or PCOV is available. Report a missing coverage driver as an environment blocker.

---

## File map

### Domain and persistence

- Create `app/Enums/UserRole.php` for the `customer` and `admin` backed values.
- Modify `app/Enums/BookingStatus.php` to own the transition allow-list.
- Modify `app/Models/User.php` to cast `role` and expose `isAdmin()`.
- Modify `app/Models/Booking.php` to guard all attributes and expose ordered status changes.
- Create `app/Models/BookingStatusChange.php` as the guarded, create-only audit model.
- Modify `app/Actions/CreateBooking.php` to use `forceCreate()` with its existing server-built payload.
- Create `app/Actions/TransitionBookingStatus.php` as the sole status mutation boundary.
- Create `database/migrations/2026_09_07_000000_add_role_to_users_table.php`.
- Create `database/migrations/2026_09_07_000001_create_booking_status_changes_table.php`.
- Modify `database/factories/UserFactory.php` and `database/factories/BookingFactory.php`.
- Create `database/factories/BookingStatusChangeFactory.php`.

### Administrator access and HTTP boundary

- Create `app/Console/Commands/GrantAdmin.php` and `app/Console/Commands/RevokeAdmin.php`.
- Create `app/Exceptions/FinalAdministratorException.php` for the one expected revocation refusal.
- Create `app/Http/Middleware/EnsureUserIsAdmin.php`.
- Create `app/Policies/BookingPolicy.php`.
- Create `app/Http/Controllers/Admin/AdminLoginController.php`.
- Create `app/Http/Controllers/Admin/AdminBookingController.php`.
- Create `app/Http/Requests/Admin/AdminBookingIndexRequest.php`.
- Create `app/Http/Requests/Admin/UpdateBookingStatusRequest.php`.
- Create `app/Queries/AdminBookingIndexQuery.php`.
- Create `app/Support/AdminBookingPresenter.php`.
- Create `routes/admin.php` and modify `routes/web.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, and `app/Http/Middleware/HandleInertiaRequests.php`.
- Create `lang/en/admin.php` and `lang/id/admin.php`.

### Inertia and Vue

- Modify `resources/js/types/index.ts` with the exact admin prop contracts.
- Modify `resources/js/data/translations.ts` with paired `ad.*` operational copy.
- Modify `resources/js/layouts/MainLayout.vue` to show an Admin Panel link only when the server shares `auth.canAccessAdmin`.
- Create `resources/js/layouts/admin/AdminLayout.vue`.
- Create `resources/js/pages/admin/bookings/Index.vue`.
- Create `resources/js/pages/admin/bookings/Show.vue`.
- Replace the prototype-only rules in `resources/css/admin.css` with focused runtime admin styles while retaining the plum, rose, gold, and warm-surface direction.

### Tests

- Modify `tests/Feature/UserSchemaTest.php`, `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`, `tests/Feature/InertiaSharedDataTest.php`, and `tests/Feature/PublicPagesTest.php`.
- Create `tests/Feature/Admin/AdminProvisioningTest.php`.
- Create `tests/Feature/Admin/AdminAuthorizationTest.php`.
- Create `tests/Feature/Admin/AdminBookingIndexTest.php`.
- Create `tests/Feature/Admin/AdminBookingDetailTest.php`.
- Create `tests/Feature/Admin/AdminBookingStatusTest.php`.
- Create `tests/frontend/adminLayout.test.ts`.
- Create `tests/frontend/adminBookingIndex.test.ts`.
- Create `tests/frontend/adminBookingShow.test.ts`.
- Modify `tests/frontend/publicPages.test.ts` and `tests/frontend/translation-parity.test.mjs`.
- Modify `tests/frontend/bookingPages.test.ts`, `tests/frontend/bookingSubmission.test.ts`, and `tests/frontend/serviceCatalog.test.ts` so typed shared-prop fixtures include `auth.canAccessAdmin`.
- Create `tests/e2e/admin-bookings.spec.ts` and extend `tests/e2e/helpers/auth.ts` only if a shared logout helper removes duplication.

---

### Task 1: Add the user role boundary

Completed on 2026-09-07. Focused tests passed after observed failures; the full Laravel suite passed with 178 tests and 1,496 assertions. Scoped Pint and independent code/security review passed. PHP coverage remains blocked by the missing Xdebug/PCOV driver. Migration verification used isolated in-memory SQLite, not the developer database.

**Files:**

- Create: `app/Enums/UserRole.php`
- Create: `database/migrations/2026_09_07_000000_add_role_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `tests/Feature/UserSchemaTest.php`
- Modify: `tests/Feature/Auth/RegistrationTest.php`
- Modify: `tests/Feature/Settings/ProfileUpdateTest.php`

**Interfaces:**

- Produces: `UserRole::Customer`, `UserRole::Admin`, `User::isAdmin(): bool`, and `UserFactory::admin(): static`.
- Preserves: `User::$fillable` contains only `name`, `email`, `phone`, and `password`.
- Database contract: `users.role` is required, indexed, and defaults to `customer`.

- [ ] **Step 1: Write failing schema, cast, registration, and profile tests**

Add focused assertions like these before changing production code:

```php
public function test_role_defaults_to_customer_and_casts_to_the_enum(): void
{
    $user = User::factory()->create();

    $this->assertSame(UserRole::Customer, $user->role);
    $this->assertFalse($user->isAdmin());
    $this->assertTrue(Schema::hasColumn('users', 'role'));
}

public function test_public_registration_cannot_assign_an_admin_role(): void
{
    $this->post('/register', [
        'name' => 'Ayu Lestari',
        'email' => 'ayu@example.com',
        'phone' => '+6281234567890',
        'password' => 'calmspa9',
        'password_confirmation' => 'calmspa9',
        'role' => 'admin',
    ])->assertRedirect('/verify-email');

    $this->assertSame(UserRole::Customer, User::where('email', 'ayu@example.com')->firstOrFail()->role);
}

public function test_profile_update_cannot_change_the_role(): void
{
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'role' => 'customer',
    ])->assertSessionHasNoErrors();

    $this->assertSame(UserRole::Admin, $user->refresh()->role);
}
```

- [ ] **Step 2: Run the focused tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/UserSchemaTest.php tests/Feature/Auth/RegistrationTest.php tests/Feature/Settings/ProfileUpdateTest.php
```

Expected: FAIL because `UserRole`, `users.role`, and the `admin()` factory state do not exist.

- [ ] **Step 3: Add the enum, migration, model cast, and factory states**

Create the enum and use scalar backed values in the migration:

```php
enum UserRole: string
{
    case Customer = 'customer';
    case Admin = 'admin';
}
```

```php
Schema::table('users', function (Blueprint $table): void {
    $table->enum('role', array_column(UserRole::cases(), 'value'))
        ->default(UserRole::Customer->value)
        ->index();
});
```

Add the cast and helper without adding `role` to `$fillable`:

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
    ];
}

public function isAdmin(): bool
{
    return $this->role === UserRole::Admin;
}
```

Set the factory default explicitly and return a new state for administrators:

```php
'role' => UserRole::Customer,

public function admin(): static
{
    return $this->state(fn (): array => ['role' => UserRole::Admin]);
}
```

- [ ] **Step 4: Run the focused tests and the migration cycle**

Run:

```bash
php artisan test tests/Feature/UserSchemaTest.php tests/Feature/Auth/RegistrationTest.php tests/Feature/Settings/ProfileUpdateTest.php
```

Expected: all focused tests pass. Exercise the migration down/up cycle inside `UserSchemaTest` using its isolated in-memory connection. Never run destructive migration commands against a connection selected only by `--env=testing`; that flag alone does not guarantee database isolation.

- [ ] **Step 5: Review and commit the role boundary**

Check that no public validator or `$fillable` array contains `role`, then run the code-quality reviewer. Commit only Task 1 files:

```bash
git add app/Enums/UserRole.php app/Models/User.php database/factories/UserFactory.php database/migrations/2026_09_07_000000_add_role_to_users_table.php tests/Feature/UserSchemaTest.php tests/Feature/Auth/RegistrationTest.php tests/Feature/Settings/ProfileUpdateTest.php
git commit -m "feat: add explicit user roles"
```

---

### Task 2: Add safe administrator provisioning commands

Completed on 2026-09-07. The command-focused suite passed with 12 tests and 54 assertions; the full Laravel suite passed with 190 tests and 1,550 assertions. Scoped Pint, command discovery, `git diff --check`, and independent code/security review passed. The review-driven concurrency regression test covers the transactional update order and preserves guards for the lock/retry contract that SQLite cannot expose in generated SQL. PHP coverage remains blocked because this environment has no Xdebug or PCOV driver.

**Files:**

- Create: `app/Console/Commands/GrantAdmin.php`
- Create: `app/Console/Commands/RevokeAdmin.php`
- Create: `app/Exceptions/FinalAdministratorException.php`
- Create: `tests/Feature/Admin/AdminProvisioningTest.php`

**Interfaces:**

- Consumes: `CustomerIdentity::email(string): string`, `UserRole`, and `User::isAdmin()`.
- Produces: `php artisan admin:grant {email} {--yes}` and `php artisan admin:revoke {email} {--yes}`.
- Produces: `FinalAdministratorException`, caught only by `RevokeAdmin` to report the protected final-admin case.
- Exit contract: invalid target or final-admin revocation returns `Command::FAILURE`; a completed change or idempotent no-op returns `Command::SUCCESS`.

- [ ] **Step 1: Write failing command tests**

Cover normalization, verification, confirmation, idempotency, password preservation, and final-admin protection:

```php
public function test_grant_normalizes_email_and_never_changes_the_password(): void
{
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $passwordHash = $user->password;

    $this->artisan('admin:grant', ['email' => ' OWNER@EXAMPLE.COM ', '--yes' => true])
        ->assertSuccessful();

    $this->assertSame(UserRole::Admin, $user->refresh()->role);
    $this->assertSame($passwordHash, $user->password);
}

public function test_grant_refuses_an_unverified_account(): void
{
    User::factory()->unverified()->create(['email' => 'staff@example.com']);

    $this->artisan('admin:grant', ['email' => 'staff@example.com', '--yes' => true])
        ->assertFailed();
}

public function test_revoke_refuses_to_remove_the_final_administrator(): void
{
    $admin = User::factory()->admin()->create();

    $this->artisan('admin:revoke', ['email' => $admin->email, '--yes' => true])
        ->assertFailed();

    $this->assertSame(UserRole::Admin, $admin->refresh()->role);
}
```

Also test a declined confirmation, repeated grant, repeated revoke, a missing user, and successful revocation when another admin exists.

- [ ] **Step 2: Run the command tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Admin/AdminProvisioningTest.php
```

Expected: FAIL because the two command names are not registered.

- [ ] **Step 3: Implement `GrantAdmin`**

Use an internal assignment after the operator confirms the exact normalized target:

```php
protected $signature = 'admin:grant {email} {--yes : Skip the confirmation prompt}';

public function handle(): int
{
    $email = CustomerIdentity::email((string) $this->argument('email'));
    $user = User::query()->where('email', $email)->first();

    if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
        $this->error('A verified user with that email address was not found.');
        return self::FAILURE;
    }

    $this->line("Target account: {$user->name} <{$user->email}>");
    if (! $this->option('yes') && ! $this->confirm('Grant administrator access to this account?')) {
        $this->warn('No changes were made.');
        return self::SUCCESS;
    }

    if ($user->isAdmin()) {
        $this->info('This account already has administrator access.');
        return self::SUCCESS;
    }

    $user->forceFill(['role' => UserRole::Admin])->save();
    $this->info('Administrator access granted.');

    return self::SUCCESS;
}
```

- [ ] **Step 4: Implement `RevokeAdmin` with a transaction lock**

Confirm first, then re-read the target and every admin row under one transaction:

```php
$changed = DB::transaction(function () use ($user): bool {
    $adminIds = User::query()
        ->where('role', UserRole::Admin->value)
        ->orderBy('id')
        ->lockForUpdate()
        ->pluck('id');

    $lockedTarget = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

    if (! $lockedTarget->isAdmin()) {
        return false;
    }

    if ($adminIds->count() <= 1) {
        throw new FinalAdministratorException;
    }

    $lockedTarget->forceFill(['role' => UserRole::Customer])->save();

    return true;
}, 3);
```

Define `final class FinalAdministratorException extends RuntimeException {}`. Catch only that type to print a safe operator message. Let unexpected database failures retain a non-zero exit status. `--yes` must skip only the prompt, never the row locks or final-admin check.

- [ ] **Step 5: Run command tests and inspect command registration**

Run:

```bash
php artisan test tests/Feature/Admin/AdminProvisioningTest.php
php artisan list --raw
```

Expected: the test file passes and the list contains both `admin:grant` and `admin:revoke`.

- [ ] **Step 6: Review and commit provisioning**

Run the code-quality reviewer, verify that neither command accepts or prints a password, then commit:

```bash
git add app/Console/Commands/GrantAdmin.php app/Console/Commands/RevokeAdmin.php app/Exceptions/FinalAdministratorException.php tests/Feature/Admin/AdminProvisioningTest.php
git commit -m "feat: add admin provisioning commands"
```

---

### Task 3: Protect the administrator route boundary

Completed on 2026-09-07. The focused authorization suite passed with 24 tests and 236 assertions; the full Laravel suite passed with 205 tests and 1,693 assertions. TypeScript checking, scoped Pint, route inspection, `git diff --check`, and independent security review passed. Review-driven fixes ensure authentication, verification, and current-role checks run before booking model binding and that shared administrator access is derived from a fresh database identity. PHP coverage remains blocked because this environment has no Xdebug or PCOV driver.

**Files:**

- Create: `app/Http/Middleware/EnsureUserIsAdmin.php`
- Create: `app/Policies/BookingPolicy.php`
- Create: `app/Http/Controllers/Admin/AdminLoginController.php`
- Create: `app/Http/Controllers/Admin/AdminBookingController.php`
- Create: `routes/admin.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/types/index.ts`
- Create: `tests/Feature/Admin/AdminAuthorizationTest.php`
- Modify: `tests/Feature/InertiaSharedDataTest.php`
- Modify: `tests/Feature/PublicPagesTest.php`

**Interfaces:**

- Consumes: `User::isAdmin()` and `BookingPolicy` auto-discovery at `app/Policies/BookingPolicy.php`.
- Produces: middleware alias `admin`, route names `admin.login`, `admin.index`, `admin.bookings.index`, and `admin.bookings.show`.
- Produces: shared prop `auth.canAccessAdmin: bool` beside `auth.user`.
- Temporary controller behavior: index and show may return minimal Inertia responses until Tasks 6 and 7 replace their props.

- [ ] **Step 1: Write failing authorization and shared-prop tests**

Test every route as guest, unverified admin, verified customer, verified admin, and revoked admin. Assert no response body for a denied role contains a booking canary:

```php
public function test_verified_customer_receives_forbidden_for_every_admin_content_route(): void
{
    $customer = User::factory()->create();
    $booking = Booking::factory()->create(['customer_name' => 'PRIVATE-CANARY']);

    foreach ([
        ['GET', '/admin'],
        ['GET', '/admin/bookings'],
        ['GET', "/admin/bookings/{$booking->reference}"],
    ] as [$method, $uri]) {
        $response = $this->actingAs($customer)->call($method, $uri);
        $response->assertForbidden();
        $this->assertStringNotContainsString('PRIVATE-CANARY', $response->getContent());
    }
}

public function test_admin_login_uses_only_the_fixed_internal_destination(): void
{
    $this->get('/admin/login?redirect=https://attacker.example')
        ->assertRedirect(route('login', absolute: false));

    $this->assertSame('/admin/bookings', session('url.intended'));
}
```

For the policy, assert `viewAny`, `view`, and `updateStatus` all return true only for a verified admin. For shared data, assert `auth.canAccessAdmin` is false for guests, customers, unverified admins, and revoked admins, then true for a verified admin.

- [ ] **Step 2: Run the authorization tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Admin/AdminAuthorizationTest.php tests/Feature/InertiaSharedDataTest.php tests/Feature/PublicPagesTest.php
```

Expected: FAIL because admin routes, middleware, policy, and the shared boolean do not exist.

- [ ] **Step 3: Implement middleware and policy**

The route boundary must re-read the authenticated user's current role on each request:

```php
public function handle(Request $request, Closure $next): Response
{
    abort_unless($request->user()?->isAdmin(), 403);

    return $next($request);
}
```

Keep the policy explicit:

```php
final class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->isAdmin();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->viewAny($user);
    }

    public function updateStatus(User $user, Booking $booking): bool
    {
        return $this->viewAny($user);
    }
}
```

Register the middleware alias in `bootstrap/app.php`:

```php
$middleware->alias([
    'admin' => EnsureUserIsAdmin::class,
]);
```

- [ ] **Step 4: Implement the fixed admin login entry and protected routes**

`AdminLoginController` must ignore all destination input:

```php
public function __invoke(Request $request): RedirectResponse
{
    if (! $request->user()) {
        $request->session()->put('url.intended', '/admin/bookings');

        return to_route('login');
    }

    return to_route('admin.bookings.index');
}
```

Create `routes/admin.php` with this shape:

```php
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', AdminLoginController::class)->name('login');

    Route::middleware(['auth', 'verified', 'admin'])->group(function (): void {
        Route::redirect('/', '/admin/bookings')->name('index');
        Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking:reference}', [AdminBookingController::class, 'show'])->name('bookings.show');
    });
});
```

Require it from `routes/web.php`. The initial controller actions must call `Gate::authorize()` before returning `inertia('admin/bookings/Index', ...)` or `inertia('admin/bookings/Show', ...)`.

- [ ] **Step 5: Share the server-derived admin permission**

Add only the boolean, never the role string:

```php
'auth' => [
    'user' => $user ? [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'phone' => $user->phone,
        'email_verified_at' => $user->email_verified_at?->toISOString(),
    ] : null,
    'canAccessAdmin' => $user?->hasVerifiedEmail() === true && $user->isAdmin(),
],
```

Update `AppPageProps` to `auth: { user: PublicUser | null; canAccessAdmin: boolean }` and update existing frontend test fixtures that construct `auth`.

- [ ] **Step 6: Run the tests and inspect the route table**

Run:

```bash
php artisan test tests/Feature/Admin/AdminAuthorizationTest.php tests/Feature/InertiaSharedDataTest.php tests/Feature/PublicPagesTest.php
php artisan route:list --path=admin
```

Expected: authorization tests pass; `/admin/login` lacks the admin middleware; all content routes list `auth`, `verified`, and `admin`.

- [ ] **Step 7: Run security review and commit**

Ask the security reviewer to check authentication, verification, role revocation, intended-path handling, policy duplication, and prop leakage. Address all Critical and High findings, rerun the focused tests, then commit:

```bash
git add app/Http/Middleware/EnsureUserIsAdmin.php app/Policies/BookingPolicy.php app/Http/Controllers/Admin/AdminLoginController.php app/Http/Controllers/Admin/AdminBookingController.php routes/admin.php routes/web.php bootstrap/app.php app/Http/Middleware/HandleInertiaRequests.php resources/js/types/index.ts tests/Feature/Admin/AdminAuthorizationTest.php tests/Feature/InertiaSharedDataTest.php tests/Feature/PublicPagesTest.php
git commit -m "feat: protect admin booking routes"
```

---

### Task 4: Add the append-only booking status lifecycle

Completed on 2026-09-07. The required booking/lifecycle suites passed with 56 tests and 472 assertions; the full Laravel suite passed with 234 tests and 1,784 assertions. Scoped Pint, migration cycling, `git diff --check`, and independent code/security review passed. Review-driven coverage now proves that the real transition query requests a row lock while retaining sequential stale-write and atomic rollback tests. SQLite cannot prove the production database engine's lock semantics. PHP coverage remains blocked because this environment has no Xdebug or PCOV driver.

**Files:**

- Modify: `app/Enums/BookingStatus.php`
- Modify: `app/Models/Booking.php`
- Modify: `app/Actions/CreateBooking.php`
- Create: `app/Models/BookingStatusChange.php`
- Create: `app/Actions/TransitionBookingStatus.php`
- Create: `database/migrations/2026_09_07_000001_create_booking_status_changes_table.php`
- Create: `database/factories/BookingStatusChangeFactory.php`
- Modify: `database/factories/BookingFactory.php`
- Create: `lang/en/admin.php`
- Create: `lang/id/admin.php`
- Modify: `tests/Feature/BookingSchemaTest.php`
- Create: `tests/Feature/Admin/AdminBookingStatusTest.php`

**Interfaces:**

- Produces: `BookingStatus::allowedTransitions(): array` and `BookingStatus::canTransitionTo(BookingStatus): bool`.
- Produces: `Booking::statusChanges(): HasMany` ordered by `created_at`, then `id`.
- Produces: `Booking::getRouteKeyName(): string`, returning `reference` for admin route generation and model binding.
- Produces: `TransitionBookingStatus::__invoke(User, Booking, BookingStatus, BookingStatus, ?string, ?string, ?string): BookingStatusChange`.
- Persistence contract: audit rows have `created_at` and no `updated_at`; ordinary mass assignment is blocked on both booking models.

- [ ] **Step 1: Write failing lifecycle, schema, and rollback tests**

Use a data provider for the four valid transitions and every invalid transition. Include stale-state and rollback tests:

```php
#[DataProvider('allowedTransitions')]
public function test_allowed_transition_updates_booking_and_writes_one_audit_row(
    BookingStatus $from,
    BookingStatus $to,
): void {
    $admin = User::factory()->admin()->create();
    $booking = Booking::factory()->create(['status' => $from]);

    $change = app(TransitionBookingStatus::class)(
        $admin,
        $booking,
        $from,
        $to,
        'Guest confirmed by phone.',
        '127.0.0.1',
        'Admin status test',
    );

    $this->assertSame($to, $booking->fresh()->status);
    $this->assertSame($from, $change->from_status);
    $this->assertSame($to, $change->to_status);
    $this->assertDatabaseCount('booking_status_changes', 1);
}

public function test_stale_transition_changes_nothing(): void
{
    $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

    try {
        app(TransitionBookingStatus::class)(
            User::factory()->admin()->create(),
            $booking,
            BookingStatus::Pending,
            BookingStatus::Cancelled,
            null,
            null,
            null,
        );
        $this->fail('A stale transition should fail validation.');
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey('currentStatus', $exception->errors());
    }

    $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    $this->assertDatabaseCount('booking_status_changes', 0);
}
```

In the rollback test, register a temporary `BookingStatusChange::creating` listener that throws `RuntimeException`, invoke the action, then assert the booking still has its old status and no audit row exists. Also prove a second request with the old status cannot create a second row.

- [ ] **Step 2: Run the focused domain tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/BookingSchemaTest.php tests/Feature/Admin/AdminBookingStatusTest.php
```

Expected: FAIL because transition methods, the audit table, model, and action do not exist.

- [ ] **Step 3: Centralize the transition graph in `BookingStatus`**

```php
/** @return list<self> */
public function allowedTransitions(): array
{
    return match ($this) {
        self::Pending => [self::Confirmed, self::Cancelled],
        self::Confirmed => [self::Completed, self::Cancelled],
        self::Completed, self::Cancelled => [],
    };
}

public function canTransitionTo(self $next): bool
{
    return in_array($next, $this->allowedTransitions(), true);
}
```

- [ ] **Step 4: Create the audit migration and guarded model**

Use scalar enum values and explicit foreign-key behavior:

```php
Schema::create('booking_status_changes', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('booking_id')->constrained()->restrictOnDelete();
    $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('actor_name');
    $table->string('actor_email', 254);
    $table->enum('from_status', array_column(BookingStatus::cases(), 'value'));
    $table->enum('to_status', array_column(BookingStatus::cases(), 'value'));
    $table->string('reason', 500)->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->string('user_agent', 1024)->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->index(['booking_id', 'created_at']);
    $table->index(['admin_user_id', 'created_at']);
});
```

In `BookingStatusChange`, set `$guarded = ['*']`, `public const UPDATED_AT = null`, cast both status fields to `BookingStatus`, cast `created_at` to `immutable_datetime`, and add `booking()` and `admin()` relationships.

- [ ] **Step 5: Harden booking creation against mass assignment**

Replace `Booking::$fillable` with `protected $guarded = ['*'];`. Change only the internal creation call:

```php
return Booking::query()->forceCreate([
    'user_id' => $user->id,
    'reference' => $this->reference(),
    'submission_key' => (string) $data['submission_key'],
    'request_fingerprint' => $fingerprint,
    'status' => BookingStatus::Pending,
    ...$bookingAttributes,
]);
```

Add the explicit route key and ordered audit relationship:

```php
public function getRouteKeyName(): string
{
    return 'reference';
}

public function statusChanges(): HasMany
{
    return $this->hasMany(BookingStatusChange::class)
        ->orderBy('created_at')
        ->orderBy('id');
}
```

The request must still have no way to assign reference, status, identity snapshots, service IDs, or prices.

- [ ] **Step 6: Implement the transactional transition action**

Build the audit payload from trusted objects and capped request metadata:

```php
public function __invoke(
    User $admin,
    Booking $booking,
    BookingStatus $currentStatus,
    BookingStatus $nextStatus,
    ?string $reason,
    ?string $ipAddress,
    ?string $userAgent,
): BookingStatusChange {
    return DB::transaction(function () use (
        $admin, $booking, $currentStatus, $nextStatus, $reason, $ipAddress, $userAgent
    ): BookingStatusChange {
        $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== $currentStatus) {
            throw ValidationException::withMessages([
                'currentStatus' => __('admin.status.stale'),
            ]);
        }

        if (! $currentStatus->canTransitionTo($nextStatus)) {
            throw ValidationException::withMessages([
                'nextStatus' => __('admin.status.invalid'),
            ]);
        }

        $locked->status = $nextStatus;
        $locked->save();

        return BookingStatusChange::query()->forceCreate([
            'booking_id' => $locked->getKey(),
            'admin_user_id' => $admin->getKey(),
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'from_status' => $currentStatus,
            'to_status' => $nextStatus,
            'reason' => $reason,
            'ip_address' => $ipAddress === null ? null : mb_substr($ipAddress, 0, 45),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 1024),
        ]);
    });
}
```

Add exact `admin.status.stale` and `admin.status.invalid` messages in both Laravel locale files.

- [ ] **Step 7: Run domain, existing booking, and migration tests**

Run:

```bash
php artisan test tests/Feature/BookingSchemaTest.php tests/Feature/BookingSubmissionTest.php tests/Feature/BookingPagesTest.php tests/Feature/Admin/AdminBookingStatusTest.php
```

Expected: all tests pass, including existing booking creation after `$guarded = ['*']`.

- [ ] **Step 8: Review and commit the lifecycle**

Run the code-quality reviewer. Check that no route can update or delete an audit row and no controller writes `Booking::status` directly. Commit:

```bash
git add app/Enums/BookingStatus.php app/Models/Booking.php app/Models/BookingStatusChange.php app/Actions/CreateBooking.php app/Actions/TransitionBookingStatus.php database/migrations/2026_09_07_000001_create_booking_status_changes_table.php database/factories/BookingFactory.php database/factories/BookingStatusChangeFactory.php lang/en/admin.php lang/id/admin.php tests/Feature/BookingSchemaTest.php tests/Feature/Admin/AdminBookingStatusTest.php
git commit -m "feat: audit booking status transitions"
```

---

### Task 5: Add the validated and throttled status endpoint

**Files:**

- Create: `app/Http/Requests/Admin/UpdateBookingStatusRequest.php`
- Modify: `app/Http/Controllers/Admin/AdminBookingController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/admin.php`
- Modify: `lang/en/admin.php`
- Modify: `lang/id/admin.php`
- Modify: `tests/Feature/Admin/AdminBookingStatusTest.php`

**Interfaces:**

- Produces: route `PATCH /admin/bookings/{booking:reference}/status`, named `admin.bookings.status.update`.
- Produces: limiter `admin-booking-status` with 30 attempts per minute per admin and IP, plus 100 attempts per hour per IP.
- Request contract: `currentStatus`, `nextStatus`, and nullable `reason`, with all other request fields ignored and never forwarded.
- Success contract: HTTP 303 to the detail route with translated `flash.status`.

- [ ] **Step 1: Write failing HTTP and limiter tests**

Test authorized success, all invalid enum strings, invalid lifecycle changes, reason trimming, reason length 501, stale status, role injection, unauthenticated access, unverified access, customer access, 31st minute request, and 101st hourly IP request.

```php
public function test_admin_can_apply_an_allowed_transition_through_the_http_boundary(): void
{
    $admin = User::factory()->admin()->create();
    $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

    $this->actingAs($admin)->patch(route('admin.bookings.status.update', $booking), [
        'currentStatus' => 'pending',
        'nextStatus' => 'confirmed',
        'reason' => '  Confirmed by WhatsApp.  ',
        'total_price_idr' => 1,
    ])->assertRedirect(route('admin.bookings.show', $booking, absolute: false))
      ->assertStatus(303)
      ->assertSessionHas('status');

    $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    $this->assertDatabaseHas('booking_status_changes', [
        'booking_id' => $booking->id,
        'reason' => 'Confirmed by WhatsApp.',
    ]);
    $this->assertNotSame(1, $booking->fresh()->total_price_idr);
}
```

- [ ] **Step 2: Run the HTTP tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Admin/AdminBookingStatusTest.php
```

Expected: FAIL because the request, route, and limiter do not exist.

- [ ] **Step 3: Implement the form request**

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'reason' => is_string($this->input('reason'))
            ? trim($this->input('reason')) ?: null
            : $this->input('reason'),
    ]);
}

public function authorize(): bool
{
    $booking = $this->route('booking');

    return $booking instanceof Booking && $this->user()?->can('updateStatus', $booking) === true;
}

public function rules(): array
{
    return [
        'currentStatus' => ['required', Rule::enum(BookingStatus::class)],
        'nextStatus' => ['required', Rule::enum(BookingStatus::class)],
        'reason' => ['nullable', 'string', 'max:500'],
    ];
}
```

Add translated attribute names for `currentStatus`, `nextStatus`, and `reason` in both Laravel validation dictionaries.

- [ ] **Step 4: Register the limiter and route**

```php
RateLimiter::for('admin-booking-status', fn (Request $request): array => [
    Limit::perMinute(30)->by('admin:'.$request->user()?->getAuthIdentifier().'|ip:'.$request->ip()),
    Limit::perHour(100)->by('ip:'.$request->ip()),
]);
```

Place the PATCH route inside the protected group and attach only `throttle:admin-booking-status` to this route.

- [ ] **Step 5: Connect the controller to the action**

```php
public function updateStatus(
    UpdateBookingStatusRequest $request,
    Booking $booking,
    TransitionBookingStatus $transition,
): RedirectResponse {
    $validated = $request->validated();

    $transition(
        $request->user(),
        $booking,
        BookingStatus::from($validated['currentStatus']),
        BookingStatus::from($validated['nextStatus']),
        $validated['reason'] ?? null,
        $request->ip(),
        $request->userAgent(),
    );

    return to_route('admin.bookings.show', $booking, 303)
        ->with('status', __('admin.status.updated'));
}
```

Do not catch `ValidationException`. Laravel must return its standard field errors. Let the global production-safe exception renderer handle unexpected database failures while Laravel logs the server exception.

- [ ] **Step 6: Run endpoint and regression tests**

Run:

```bash
php artisan test tests/Feature/Admin/AdminBookingStatusTest.php tests/Feature/BookingPagesTest.php tests/Feature/InertiaSharedDataTest.php
```

Expected: all tests pass; customer booking presenters show the new status but never include status history or audit metadata.

- [ ] **Step 7: Run security review and commit**

Ask the security reviewer to inspect CSRF, role checks, policy authorization, enum validation, stale writes, row locking, rollback, rate-limit keys, error disclosure, and mass assignment. Address all Critical and High findings, rerun the focused tests, then commit:

```bash
git add app/Http/Requests/Admin/UpdateBookingStatusRequest.php app/Http/Controllers/Admin/AdminBookingController.php app/Providers/AppServiceProvider.php routes/admin.php lang/en/admin.php lang/id/admin.php tests/Feature/Admin/AdminBookingStatusTest.php
git commit -m "feat: add secure booking status endpoint"
```

---

### Task 6: Build the admin booking index backend

**Files:**

- Create: `app/Http/Requests/Admin/AdminBookingIndexRequest.php`
- Create: `app/Queries/AdminBookingIndexQuery.php`
- Create: `app/Support/AdminBookingPresenter.php`
- Modify: `app/Http/Controllers/Admin/AdminBookingController.php`
- Create: `tests/Feature/Admin/AdminBookingIndexTest.php`

**Interfaces:**

- Produces: `AdminBookingIndexRequest::filters(): array{scope: string, status: ?string, q: string, dateFrom: ?string, dateTo: ?string}`.
- Produces: `AdminBookingIndexQuery::paginate(array $filters, CarbonImmutable $now): LengthAwarePaginator` and `counts(CarbonImmutable $now): array{today: int, pending: int, confirmed: int, completed: int}`.
- Produces: `AdminBookingPresenter::summary(Booking): array` and `pagination(LengthAwarePaginator): array`.
- Index Inertia contract: exact `counts`, `filters`, and `bookings` keys from the approved spec.

- [ ] **Step 1: Write failing validation, query, ordering, escaping, pagination, and prop tests**

Freeze time in Jakarta and create bookings on both sides of the boundary:

```php
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00', 'Asia/Jakarta'));

Booking::factory()->create([
    'reference' => 'SJ-LITERAL-PERCENT',
    'customer_name' => 'Ayu 100% Relax',
    'appointment_date' => '2026-09-07',
    'appointment_time' => '13:00',
]);
Booking::factory()->create([
    'customer_name' => 'Ayu 100X Relax',
    'appointment_date' => '2026-09-07',
    'appointment_time' => '11:00',
]);

$response = $this->actingAs(User::factory()->admin()->create())
    ->get('/admin/bookings?scope=all&q=100%25');

$response->assertOk()->assertInertia(fn (Assert $page) => $page
    ->component('admin/bookings/Index')
    ->where('filters.q', '100%')
    ->has('bookings.data', 1)
    ->where('bookings.data.0.reference', 'SJ-LITERAL-PERCENT'));
```

Add separate tests for `%`, `_`, and `\` literal searches; reference, name, lowercase/uppercase email, and phone matches; scope ordering at the current minute; status and date filter composition; filter-independent counts; invalid scope/status/date/page values; 20-row pages; preserved query strings; deterministic date/time/id ordering; and exact allow-listed item keys.

- [ ] **Step 2: Run the index tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Admin/AdminBookingIndexTest.php
```

Expected: FAIL because the request, query, and presenter do not exist and the temporary controller has no approved props.

- [ ] **Step 3: Implement validated filters**

```php
public function rules(): array
{
    return [
        'scope' => ['sometimes', Rule::in(['upcoming', 'past', 'all'])],
        'status' => ['sometimes', 'nullable', Rule::enum(BookingStatus::class)],
        'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        'dateFrom' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'dateTo' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($this->filled('dateFrom') ? ['after_or_equal:dateFrom'] : [])],
        'page' => ['sometimes', 'integer', 'min:1'],
    ];
}

public function filters(): array
{
    $validated = $this->validated();

    return [
        'scope' => $validated['scope'] ?? 'upcoming',
        'status' => $validated['status'] ?? null,
        'q' => trim((string) ($validated['q'] ?? '')),
        'dateFrom' => $validated['dateFrom'] ?? null,
        'dateTo' => $validated['dateTo'] ?? null,
    ];
}
```

Authorize with `$this->user()?->can('viewAny', Booking::class) === true`. Keep `page` out of the returned filters because the paginator owns it.

- [ ] **Step 4: Implement the query object**

Use separate bound comparisons for date and time instead of database-specific datetime concatenation. Escape search wildcards with `!`:

```php
private function escapedPattern(string $value): string
{
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value));

    return "%{$escaped}%";
}

private function applySearch(Builder $query, string $search): void
{
    if ($search === '') return;

    $pattern = $this->escapedPattern($search);
    $query->where(function (Builder $nested) use ($pattern): void {
        foreach (['reference', 'customer_name', 'customer_email', 'customer_phone'] as $column) {
            $method = $column === 'reference' ? 'whereRaw' : 'orWhereRaw';
            $nested->{$method}("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
        }
    });
}
```

Start the first expression with `whereRaw` and every remaining expression with `orWhereRaw`. Apply `upcoming` as dates after today or today at/after the current `H:i`; apply `past` as dates before today or today before the current `H:i`. Order upcoming ascending and past/all descending by appointment date, appointment time, then `id`. Finish with `paginate(20)->withQueryString()`.

Calculate the four unfiltered counts from fresh `Booking::query()` instances. `today` uses `whereDate('appointment_date', $now->toDateString())`; the other three use the exact status enum values.

- [ ] **Step 5: Implement allow-listed index presentation**

The summary method must return exactly:

```php
return [
    'reference' => $booking->reference,
    'status' => $booking->status->value,
    'package' => [
        'nameId' => $booking->package_name_id,
        'nameEn' => $booking->package_name_en,
        'isVip' => $booking->package_is_vip,
    ],
    'treatmentMode' => $booking->treatment_mode,
    'appointment' => [
        'date' => $booking->appointment_date->format('Y-m-d'),
        'time' => $booking->appointment_time,
        'timezone' => $booking->appointment_timezone,
    ],
    'customer' => [
        'name' => $booking->customer_name,
        'email' => $booking->customer_email,
        'phone' => $booking->customer_phone,
    ],
    'guests' => $booking->guests,
    'totalDurationMinutes' => $booking->total_duration_minutes,
    'totalPriceIdr' => $booking->total_price_idr,
    'allowedTransitions' => array_map(
        fn (BookingStatus $status): string => $status->value,
        $booking->status->allowedTransitions(),
    ),
];
```

The pagination method must return only `data`, `links`, `currentPage`, `lastPage`, and `total`. Normalize Laravel's page labels to plain strings and never require `v-html` on the client.

- [ ] **Step 6: Connect controller and store a server-built safe back URL**

```php
public function index(
    AdminBookingIndexRequest $request,
    AdminBookingIndexQuery $query,
    AdminBookingPresenter $presenter,
): Response {
    $filters = $request->filters();
    $now = CarbonImmutable::now('Asia/Jakarta');
    $paginator = $query->paginate($filters, $now);

    $request->session()->put(
        'admin.bookings.index_url',
        route('admin.bookings.index', array_filter($filters, fn ($value) => $value !== null && $value !== ''), false),
    );

    return inertia('admin/bookings/Index', [
        'counts' => $query->counts($now),
        'filters' => $filters,
        'bookings' => $presenter->pagination($paginator),
    ]);
}
```

The session value is assembled from validated filters and a named internal route. Never store the raw `Referer` header or a user-supplied redirect URL.

- [ ] **Step 7: Run index and authorization tests**

Run:

```bash
php artisan test tests/Feature/Admin/AdminBookingIndexTest.php tests/Feature/Admin/AdminAuthorizationTest.php
```

Expected: all tests pass and denied users receive no props.

- [ ] **Step 8: Review and commit the index backend**

Run the code-quality reviewer with special attention to SQL portability, wildcard escaping, deterministic ordering, query bounds, and prop allow-lists. Commit:

```bash
git add app/Http/Requests/Admin/AdminBookingIndexRequest.php app/Queries/AdminBookingIndexQuery.php app/Support/AdminBookingPresenter.php app/Http/Controllers/Admin/AdminBookingController.php tests/Feature/Admin/AdminBookingIndexTest.php
git commit -m "feat: add admin booking index backend"
```

---

### Task 7: Build the admin booking detail backend

**Files:**

- Modify: `app/Support/AdminBookingPresenter.php`
- Modify: `app/Http/Controllers/Admin/AdminBookingController.php`
- Create: `tests/Feature/Admin/AdminBookingDetailTest.php`

**Interfaces:**

- Produces: `AdminBookingPresenter::detail(Booking): array`.
- Detail Inertia props: `booking` and `backUrl` only.
- `backUrl` comes only from the server-built `admin.bookings.index_url` session value or defaults to `/admin/bookings`.

- [ ] **Step 1: Write failing detail allow-list, history-order, and privacy tests**

```php
public function test_detail_exposes_only_operational_fields_and_ordered_history(): void
{
    $admin = User::factory()->admin()->create();
    $booking = Booking::factory()->create([
        'notes' => 'Shoulders only',
        'request_fingerprint' => 'SECRET-FINGERPRINT-CANARY',
    ]);
    BookingStatusChange::factory()->for($booking)->create([
        'created_at' => '2026-09-07 10:00:00',
        'ip_address' => 'PRIVATE-IP-CANARY',
        'user_agent' => 'PRIVATE-UA-CANARY',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.bookings.show', $booking));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/bookings/Show')
        ->has('booking.history', 1)
        ->where('booking.notes', 'Shoulders only')
        ->where('backUrl', '/admin/bookings'));

    $serialized = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
    foreach (['SECRET-FINGERPRINT-CANARY', 'PRIVATE-IP-CANARY', 'PRIVATE-UA-CANARY', 'submission_key', 'booking_id', 'admin_user_id'] as $forbidden) {
        $this->assertStringNotContainsString($forbidden, $serialized);
    }
}
```

Also test a missing reference returns 404, an extension may be null, history sorts by `created_at` and then `id`, deleted admin accounts retain actor snapshots, and visiting a filtered index before detail returns the validated filtered `backUrl`.

- [ ] **Step 2: Run the detail tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Admin/AdminBookingDetailTest.php
```

Expected: FAIL because detail and history presentation are incomplete.

- [ ] **Step 3: Implement detail and history presentation**

Build on `summary()` and add only the approved fields:

```php
return [
    ...$this->summary($booking),
    'base' => [
        'choiceCode' => $booking->base_choice_code,
        'durationMinutes' => $booking->base_duration_minutes,
        'priceIdr' => $booking->base_price_idr,
    ],
    'extension' => $booking->extension_choice_code === null ? null : [
        'choiceCode' => $booking->extension_choice_code,
        'durationMinutes' => $booking->extension_duration_minutes,
        'priceIdr' => $booking->extension_price_idr,
    ],
    'notes' => $booking->notes,
    'history' => $booking->statusChanges->map(fn (BookingStatusChange $change): array => [
        'actor' => ['name' => $change->actor_name, 'email' => $change->actor_email],
        'fromStatus' => $change->from_status->value,
        'toStatus' => $change->to_status->value,
        'reason' => $change->reason,
        'createdAt' => $change->created_at->toISOString(),
    ])->values()->all(),
];
```

Load only `statusChanges` and do not load or serialize the actor relationship.

- [ ] **Step 4: Connect the show controller**

```php
public function show(Request $request, Booking $booking, AdminBookingPresenter $presenter): Response
{
    Gate::authorize('view', $booking);
    $booking->load('statusChanges');

    return inertia('admin/bookings/Show', [
        'booking' => $presenter->detail($booking),
        'backUrl' => $request->session()->get('admin.bookings.index_url', '/admin/bookings'),
    ]);
}
```

Because only the validated index controller writes the session key, never accept `backUrl`, `returnTo`, or `redirect` from the detail request.

- [ ] **Step 5: Run detail, index, and customer presenter tests**

Run:

```bash
php artisan test tests/Feature/Admin/AdminBookingDetailTest.php tests/Feature/Admin/AdminBookingIndexTest.php tests/Feature/BookingPagesTest.php
```

Expected: all tests pass and customer props remain unchanged.

- [ ] **Step 6: Review and commit the detail backend**

Run the code-quality reviewer with a privacy-focused prop diff, then commit:

```bash
git add app/Support/AdminBookingPresenter.php app/Http/Controllers/Admin/AdminBookingController.php tests/Feature/Admin/AdminBookingDetailTest.php
git commit -m "feat: add admin booking detail backend"
```

---

### Task 8: Add the admin TypeScript contracts, translations, and layout

**Files:**

- Modify: `resources/js/types/index.ts`
- Modify: `resources/js/data/translations.ts`
- Create: `resources/js/layouts/admin/AdminLayout.vue`
- Modify: `resources/js/layouts/MainLayout.vue`
- Modify: `tests/frontend/publicPages.test.ts`
- Modify: `tests/frontend/translation-parity.test.mjs`
- Modify: `tests/frontend/bookingPages.test.ts`
- Modify: `tests/frontend/bookingSubmission.test.ts`
- Modify: `tests/frontend/serviceCatalog.test.ts`
- Create: `tests/frontend/adminLayout.test.ts`

**Interfaces:**

- Produces: `AdminBookingSummaryData`, `AdminBookingDetailData`, `AdminBookingFilters`, `AdminBookingCounts`, and `AdminBookingPagination`.
- Produces: `AdminLayout` props `{ title: string; subtitle?: string }` and a default slot.
- Consumes: `AppPageProps.auth.canAccessAdmin`, `/logout`, `/locale`, `/admin/bookings`, and `/`.

- [ ] **Step 1: Write failing mounted layout and translation tests**

Mock `usePage` and Inertia navigation at the boundary. Verify admin identity, active Bookings link, website link, both locale buttons, exact logout POST, processing disablement, focus-visible controls, and the absence of browser storage and Vue Router imports.

```ts
it("submits logout through Fortify and disables repeat submission", async () => {
  const wrapper = mount(AdminLayout, {
    props: { title: "Bookings" },
    slots: { default: "<p>content</p>" },
  });

  await wrapper.get("button[data-admin-logout]").trigger("click");

  expect(inertia.posts).toEqual([{ url: "/logout", data: {} }]);
  expect(
    wrapper.get("button[data-admin-logout]").attributes("disabled"),
  ).toBeDefined();
});
```

Update translation parity to require all new `ad.booking.*`, `ad.status.*`, `ad.filter.*`, `ad.history.*`, and `ad.confirm.*` keys in both locales, and reject delete, payment, refund, reschedule, or notification promises in the new operational copy.

- [ ] **Step 2: Run frontend tests and confirm RED**

Run:

```bash
npm run test:frontend -- tests/frontend/adminLayout.test.ts tests/frontend/publicPages.test.ts
node --test --test-name-pattern="translation" tests/frontend/translation-parity.test.mjs
```

Expected: the mounted layout test fails because the runtime layout does not exist; the public-shell expectation fails until it becomes conditional.

- [ ] **Step 3: Add immutable TypeScript contracts**

Use readonly properties so pages cannot mutate server props:

```ts
export interface AdminBookingSummaryData {
  readonly reference: string;
  readonly status: BookingStatus;
  readonly package: Readonly<
    Pick<PackageLinkData, "nameId" | "nameEn" | "isVip">
  >;
  readonly treatmentMode: TreatmentMode;
  readonly appointment: Readonly<BookingAppointmentData>;
  readonly customer: Readonly<BookingCustomerData>;
  readonly guests: number;
  readonly totalDurationMinutes: number;
  readonly totalPriceIdr: number;
  readonly allowedTransitions: readonly BookingStatus[];
}

export interface AdminBookingStatusHistoryData {
  readonly actor: Readonly<{ name: string; email: string }>;
  readonly fromStatus: BookingStatus;
  readonly toStatus: BookingStatus;
  readonly reason: string | null;
  readonly createdAt: string;
}

export interface AdminBookingDetailData extends AdminBookingSummaryData {
  readonly base: Readonly<BookingChoiceData>;
  readonly extension: Readonly<BookingChoiceData> | null;
  readonly notes: string | null;
  readonly history: readonly AdminBookingStatusHistoryData[];
}
```

Define filters, counts, and pagination with the exact server property names. Page-link labels are plain text and URLs are `string | null`.

- [ ] **Step 4: Replace unsupported admin copy with operational copy**

Remove runtime-facing `done`, delete, services, categories, gallery, content, revenue, and dummy chart strings from `resources/js/data/translations.ts`. Add paired keys for:

```text
ad.panel, ad.logout, ad.viewsite, ad.nav.bookings
ad.booking.title, ad.booking.subtitle, ad.booking.reference, ad.booking.details
ad.count.today, ad.count.pending, ad.count.confirmed, ad.count.completed
ad.filter.search, ad.filter.status, ad.filter.dateFrom, ad.filter.dateTo
ad.filter.apply, ad.filter.clear, ad.scope.upcoming, ad.scope.past, ad.scope.all
ad.status.pending, ad.status.confirmed, ad.status.completed, ad.status.cancelled
ad.table.customer, ad.table.treatment, ad.table.schedule, ad.table.guests
ad.table.status, ad.table.action, ad.table.open
ad.empty.all, ad.empty.filtered, ad.pagination.previous, ad.pagination.next
ad.detail.appointment, ad.detail.customer, ad.detail.treatment, ad.detail.notes
ad.detail.noNotes, ad.detail.noExtension, ad.detail.back
ad.history.title, ad.history.empty, ad.history.reason, ad.history.noReason
ad.confirm.title, ad.confirm.body, ad.confirm.reason, ad.confirm.submit
ad.confirm.cancel, ad.confirm.processing, ad.confirm.error
ad.flash.statusUpdated
```

Use natural Indonesian and English text. Do not rename the established public `bookings.status.*` keys.

- [ ] **Step 5: Implement `AdminLayout` and the conditional public link**

The layout uses `useLanguage()`, a server-shared identity, and Inertia actions only:

```ts
const page = usePage<AppPageProps>();
const loggingOut = ref(false);

function logout(): void {
  if (loggingOut.value) return;
  loggingOut.value = true;
  router.post("/logout", {}, { onFinish: () => (loggingOut.value = false) });
}
```

Render a skip link, plum sidebar, current Bookings link, View Website link, locale group, admin name/email, secure logout button, and `<main id="main"><slot /></main>`. In `MainLayout.vue`, render `/admin/bookings` in the authenticated account menu only when `page.props.auth.canAccessAdmin` is true. Never infer permission from URL, email, or a client role string.

- [ ] **Step 6: Run layout, translation, type, and public-shell tests**

Run:

```bash
npm run test:frontend -- tests/frontend/adminLayout.test.ts tests/frontend/publicPages.test.ts
node --test tests/frontend/translation-parity.test.mjs
npm run types:check
```

Expected: all commands pass.

- [ ] **Step 7: Review and commit the UI foundation**

Run the code-quality reviewer with focus on keyboard navigation, server-derived permissions, logout behavior, translation parity, and prop immutability. Commit:

```bash
git add resources/js/types/index.ts resources/js/data/translations.ts resources/js/layouts/admin/AdminLayout.vue resources/js/layouts/MainLayout.vue tests/frontend/adminLayout.test.ts tests/frontend/publicPages.test.ts tests/frontend/translation-parity.test.mjs tests/frontend/bookingPages.test.ts tests/frontend/bookingSubmission.test.ts tests/frontend/serviceCatalog.test.ts
git commit -m "feat: add admin console layout"
```

---

### Task 9: Build the booking console page

**Files:**

- Create: `resources/js/pages/admin/bookings/Index.vue`
- Modify: `resources/css/admin.css`
- Create: `tests/frontend/adminBookingIndex.test.ts`

**Interfaces:**

- Consumes: `counts: AdminBookingCounts`, `filters: AdminBookingFilters`, and `bookings: AdminBookingPagination`.
- Produces: exact GET visits to `/admin/bookings`, detail links by reference, desktop table and mobile cards from the same immutable prop array.

- [ ] **Step 1: Write failing mounted index tests**

Mount realistic props and verify four counts, current filter values, localized statuses, table headers, mobile cards, plain pagination labels, safe detail URLs, and both empty states. Exercise a real form submit:

```ts
await wrapper.get("input[name='q']").setValue(" AYU% ");
await wrapper.get("select[name='status']").setValue("pending");
await wrapper.get("form[data-admin-filters]").trigger("submit");

expect(inertia.gets).toEqual([
  {
    url: "/admin/bookings",
    data: {
      scope: "upcoming",
      status: "pending",
      q: "AYU%",
      dateFrom: "2026-09-07",
      dateTo: "2026-09-30",
    },
  },
]);
```

Assert blank optional values are omitted from the request, processing controls are disabled until `onFinish`, filters are never written to browser storage, and locale switching changes every visible status and label.

- [ ] **Step 2: Run the mounted page tests and confirm RED**

Run:

```bash
npm run test:frontend -- tests/frontend/adminBookingIndex.test.ts
```

Expected: FAIL because the page does not exist.

- [ ] **Step 3: Implement immutable filter submission and formatters**

Copy props into a local reactive form and construct a new query object on every submit:

```ts
const filterForm = reactive({ ...props.filters });
const loading = ref(false);

function submitFilters(): void {
  if (loading.value) return;

  const query = Object.fromEntries(
    Object.entries({ ...filterForm, q: filterForm.q.trim() }).filter(
      ([, value]) => value !== "" && value !== null,
    ),
  );

  router.get("/admin/bookings", query, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onStart: () => {
      loading.value = true;
    },
    onFinish: () => {
      loading.value = false;
    },
  });
}
```

`clearFilters()` submits `{ scope: 'upcoming' }`. Format price with `Intl.NumberFormat('id-ID')`; format appointment dates with the active locale; derive package and treatment labels from props and translation keys.

- [ ] **Step 4: Render the responsive console**

Use `AdminLayout` and render:

- four count cards with text labels;
- three scope buttons implemented as real buttons inside the GET filter form;
- visible search, status, date-from, and date-to labels;
- an Apply button and conditional Clear Filters button;
- one semantic desktop table with `<caption class="sr-only">` and scoped headers;
- one mobile `<ul>` using the same `bookings.data` array and field order;
- filtered-empty copy when any optional filter is active, otherwise no-bookings copy;
- pagination links with `preserve-state`, `preserve-scroll`, and disabled text when `url` is null.

Use `:href="`/admin/bookings/${encodeURIComponent(booking.reference)}`"`. Never render server link labels with `v-html`.

- [ ] **Step 5: Replace prototype CSS with focused runtime styles**

Keep existing public tokens and provide concrete admin selectors for `.admin-shell`, `.admin-sidebar`, `.admin-topbar`, `.admin-content`, `.admin-count-grid`, `.admin-filter-panel`, `.admin-booking-table`, `.admin-booking-cards`, `.admin-status`, and `.admin-pagination`. Use these required behaviors:

```css
.admin-control,
.admin-button,
.admin-nav-link {
  min-height: 44px;
}

.admin-booking-cards {
  display: none;
}

@media (max-width: 760px) {
  .admin-shell {
    grid-template-columns: 1fr;
  }
  .admin-sidebar {
    position: static;
    min-height: auto;
  }
  .admin-booking-table-wrap {
    display: none;
  }
  .admin-booking-cards {
    display: grid;
  }
}

@media (prefers-reduced-motion: reduce) {
  .admin-shell *,
  .admin-shell *::before,
  .admin-shell *::after {
    scroll-behavior: auto;
    transition-duration: 0.01ms;
    animation-duration: 0.01ms;
    animation-iteration-count: 1;
  }
}
```

Keep visible `:focus-visible` outlines and text labels on every status chip. Remove CSS for charts, service CRUD, category CRUD, gallery CRUD, content editing, delete controls, and the unsupported `status-done` class.

- [ ] **Step 6: Run page, layout, type, and format tests**

Run:

```bash
npm run test:frontend -- tests/frontend/adminBookingIndex.test.ts tests/frontend/adminLayout.test.ts
npm run types:check
npx prettier --check resources/js/pages/admin/bookings/Index.vue resources/css/admin.css tests/frontend/adminBookingIndex.test.ts
```

Expected: all commands pass.

- [ ] **Step 7: Review and commit the booking console**

Run the code-quality reviewer. Check desktop and 375 px layouts, 200 percent zoom, keyboard focus order, empty states, and the exact GET query. Commit:

```bash
git add resources/js/pages/admin/bookings/Index.vue resources/css/admin.css tests/frontend/adminBookingIndex.test.ts
git commit -m "feat: add admin booking console"
```

---

### Task 10: Build booking detail and accessible status confirmation

**Files:**

- Create: `resources/js/pages/admin/bookings/Show.vue`
- Modify: `resources/css/admin.css`
- Create: `tests/frontend/adminBookingShow.test.ts`

**Interfaces:**

- Consumes: `booking: AdminBookingDetailData` and `backUrl: string`.
- Produces: PATCH `/admin/bookings/{reference}/status` with exactly `{ currentStatus, nextStatus, reason }`.
- Confirmation UI consumes only a status supplied by `booking.allowedTransitions`.

- [ ] **Step 1: Write failing mounted detail and confirmation tests**

Verify customer, appointment, package, base, extension, notes, total, history order, terminal state, and translated labels. Exercise confirmation with the real form boundary:

```ts
await wrapper.get("button[data-next-status='confirmed']").trigger("click");
expect(wrapper.get("[role='alertdialog']").text()).toContain("Pending");
expect(wrapper.get("[role='alertdialog']").text()).toContain("Confirmed");

await wrapper.get("textarea[name='reason']").setValue("Confirmed by phone");
await wrapper.get("form[data-status-confirmation]").trigger("submit");

expect(inertia.patches).toEqual([
  {
    url: `/admin/bookings/${booking.reference}/status`,
    data: {
      currentStatus: "pending",
      nextStatus: "confirmed",
      reason: "Confirmed by phone",
    },
  },
]);
```

Assert only server-approved transitions render; Escape and Cancel close the confirmation and restore focus to the trigger; opening focuses the dialog heading or first control; validation errors connect with `aria-describedby`; processing disables all actions; a terminal booking renders no action form; and no call uses `window.confirm`.

- [ ] **Step 2: Run mounted detail tests and confirm RED**

Run:

```bash
npm run test:frontend -- tests/frontend/adminBookingShow.test.ts
```

Expected: FAIL because the detail page does not exist.

- [ ] **Step 3: Implement detail rendering and status helpers**

Use the shared locale and immutable server props. Render customer details with interpolation only. Build `mailto:` and `tel:` links from the already displayed values, and never use `v-html`.

```ts
const selectedNextStatus = ref<BookingStatus | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const form = useForm({
  currentStatus: props.booking.status,
  nextStatus: "" as BookingStatus | "",
  reason: "",
});

function openConfirmation(
  nextStatus: BookingStatus,
  source: HTMLButtonElement,
): void {
  if (!props.booking.allowedTransitions.includes(nextStatus)) return;
  trigger.value = source;
  form.currentStatus = props.booking.status;
  form.nextStatus = nextStatus;
  form.reason = "";
  form.clearErrors();
  selectedNextStatus.value = nextStatus;
}
```

Use `Intl.NumberFormat('id-ID')` for rupiah and locale-aware date formatting. Show `ad.detail.noExtension`, `ad.detail.noNotes`, and `ad.history.noReason` rather than blank output.

- [ ] **Step 4: Implement the accessible confirmation and exact PATCH**

Use the existing Radix Vue dialog wrappers in `resources/js/components/ui/dialog`. They provide modal focus management and Escape handling without `window.confirm`.

```ts
function confirmTransition(): void {
  if (form.processing || selectedNextStatus.value === null) return;

  form
    .transform(() => ({
      currentStatus: form.currentStatus,
      nextStatus: selectedNextStatus.value,
      reason: form.reason.trim() || null,
    }))
    .patch(
      `/admin/bookings/${encodeURIComponent(props.booking.reference)}/status`,
      {
        preserveScroll: true,
        onSuccess: () => {
          selectedNextStatus.value = null;
        },
        onFinish: () => {
          form.transform((data) => data);
        },
      },
    );
}
```

Render the dialog content with `role="alertdialog"`, an old-to-new status sentence, a visible optional reason label, `maxlength="500"`, error elements for `currentStatus`, `nextStatus`, and `reason`, Cancel, and Confirm. Keep the trigger reference so focus returns when the dialog closes.

- [ ] **Step 5: Add detail and dialog CSS**

Add focused rules for `.admin-detail-grid`, `.admin-detail-card`, `.admin-status-actions`, `.admin-history`, `.admin-history-item`, `.admin-dialog-overlay`, and `.admin-dialog-content`. The overlay must not be the sole close control. Buttons remain at least 44 px tall; status changes are described in text; the layout becomes one column below 760 px.

- [ ] **Step 6: Run detail, index, type, and build tests**

Run:

```bash
npm run test:frontend -- tests/frontend/adminBookingShow.test.ts tests/frontend/adminBookingIndex.test.ts tests/frontend/adminLayout.test.ts
npm run types:check
npm run build
```

Expected: all commands pass and Vite resolves both admin pages.

- [ ] **Step 7: Review and commit booking detail**

Run the code-quality reviewer. Inspect the dialog with keyboard-only navigation, terminal bookings, long customer content, empty notes, errors, processing, narrow screens, and reduced motion. Commit:

```bash
git add resources/js/pages/admin/bookings/Show.vue resources/css/admin.css tests/frontend/adminBookingShow.test.ts
git commit -m "feat: add admin booking status interface"
```

---

### Task 11: Add the real browser journey and run release gates

**Files:**

- Create: `tests/e2e/admin-bookings.spec.ts`
- Modify: `tests/e2e/helpers/auth.ts` only if the helper is shared by more than one spec
- Modify: implementation files only when a failing gate identifies a real defect

**Interfaces:**

- Consumes: the existing isolated Playwright SQLite database and testing-only verification link helper.
- Uses: `php artisan admin:grant {email} --yes` against the Playwright environment.
- Proves: intended login, real persisted search/detail/status/history, customer status visibility, stale-write handling, and customer denial.

- [ ] **Step 1: Write the failing Playwright administrator journey**

Use two verified accounts so the booking owner and administrator remain separate:

```ts
async function createNezukoBooking(
  page: Page,
  details: { name: string; phone: string; date: string; time: string },
): Promise<void> {
  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
  );
  await page.getByLabel(/nama lengkap|full name/i).fill(details.name);
  await page.getByLabel(/nomor whatsapp|whatsapp number/i).fill(details.phone);
  await page.getByLabel(/^tanggal$|^date$/i).fill(details.date);
  await page.getByLabel(/^waktu$|^time$/i).selectOption(details.time);
  await page
    .getByRole("button", { name: /tinjau reservasi|review booking/i })
    .click();
  await page
    .getByRole("button", { name: /konfirmasi reservasi|confirm booking/i })
    .click();
  await expect(page).toHaveURL(/\/bookings\/SJ-/);
}

async function logout(page: Page): Promise<void> {
  await page.locator('button[aria-controls="account-menu"]').click();
  await page.getByRole("button", { name: /keluar|log out/i }).click();
  await expect(page).toHaveURL("/");
}

test("administrator finds and confirms a real customer booking", async ({
  page,
}) => {
  const customerEmail = "admin-flow-customer@example.com";
  const adminEmail = "admin-flow-staff@example.com";

  await registerCustomer(page, customerEmail);
  await verifyCustomer(page, customerEmail);
  await createNezukoBooking(page, {
    name: "Browser Booking Customer",
    phone: "+628122223333",
    date: "2099-09-06",
    time: "10:30",
  });
  const reference = new URL(page.url()).pathname.split("/").at(-1) ?? "";
  await logout(page);

  await registerCustomer(page, adminEmail);
  await verifyCustomer(page, adminEmail);
  execFileSync("php", ["artisan", "admin:grant", adminEmail, "--yes"], {
    cwd: projectRoot,
    env: { ...process.env, ...testingEnvironment },
    stdio: "pipe",
  });
  await logout(page);

  await page.goto("/admin/login?redirect=https://attacker.example");
  await expect(page).toHaveURL("/login");
  await page.getByLabel(/^email$/i).fill(adminEmail);
  await page.getByLabel(/^kata sandi$|^password$/i).fill(customerPassword);
  await page.getByRole("button", { name: /^masuk$|^log in$/i }).click();
  await expect(page).toHaveURL("/admin/bookings");
  expect(new URL(page.url()).origin).toBe("http://127.0.0.1:8000");

  await page.getByLabel(/search|cari/i).fill(customerEmail);
  await page.getByRole("button", { name: /apply|terapkan/i }).click();
  await page.getByRole("link", { name: new RegExp(reference) }).click();

  const patch = page.waitForResponse(
    (response) =>
      response.request().method() === "PATCH" &&
      response.url().endsWith("/status"),
  );
  await page
    .getByRole("button", { name: /confirm booking|konfirmasi reservasi/i })
    .click();
  await page.getByRole("button", { name: /^confirm$|^konfirmasi$/i }).click();
  expect((await patch).status()).toBe(303);

  await expect(
    page.getByText(/pending.*confirmed|menunggu.*terkonfirmasi/i),
  ).toBeVisible();
});
```

Keep these two helpers local unless an identical helper already exists in another maintained Playwright spec. The implementation may move an identical helper to `tests/e2e/helpers/auth.ts`, but its signature and behavior must remain the same.

Before the successful transition, open the same detail URL in a second page from the same browser context so both pages hold `currentStatus: pending`. Apply the confirmed transition in the first page, then submit the still-open confirmation in the second page. Assert the second page announces the translated stale-form error. Reload the first page and assert its history contains exactly one pending-to-confirmed item. Add a separate test that logs in as a verified customer, requests `/admin/bookings` with redirects disabled, asserts 403, and proves a seeded contact canary is absent from the response.

- [ ] **Step 2: Run the new browser spec and confirm RED before final fixes**

Run:

```bash
npx playwright test tests/e2e/admin-bookings.spec.ts
```

Expected before any browser-driven correction: at least one assertion fails if selectors, focus behavior, test command environment, or Inertia redirects do not match the real application.

- [ ] **Step 3: Make only evidence-backed browser corrections**

Use the systematic-debugging workflow for each failure. Fix production code when the behavior violates the approved spec. Fix the test only when its setup or selector does not match an accessible user action. Rerun the single failed test after each correction.

- [ ] **Step 4: Run all Laravel, Vue, browser, type, and build gates**

Run:

```bash
php artisan test
npm run test:frontend
npm run test:e2e
npm run types:check
npm run build
```

Expected: every maintained suite passes. Record the existing retired standalone suite separately if `npm run test:legacy` still reports its two known baseline failures.

- [ ] **Step 5: Run coverage, formatting, cache, audit, and security gates**

Run:

```bash
php artisan test --coverage --min=80
vendor/bin/pint --test
npx prettier --check resources/js resources/css tests/frontend tests/e2e
php artisan route:cache
php artisan config:cache
php artisan route:clear
php artisan config:clear
composer audit
npm audit
```

Expected: coverage is at least 80 percent when a driver exists; otherwise record the missing Xdebug or PCOV driver as an environment blocker. Formatting, cache build/clear, and both audits pass.

- [ ] **Step 6: Run explicit secret and forbidden-runtime scans**

Run:

```bash
rg -n --hidden -g '!vendor/**' -g '!node_modules/**' -g '!.git/**' '(AKIA[0-9A-Z]{16}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|ghp_[A-Za-z0-9]{36}|sk-[A-Za-z0-9]{20,})' .
rg -n 'localStorage|sessionStorage|vue-router|useAuth|bookingHistory|v-html|window\.confirm' resources/js/pages/admin resources/js/layouts/admin
rg -n 'delete|destroy|payment|gateway|refund|reschedule|notification' routes/admin.php app/Http/Controllers/Admin resources/js/pages/admin
```

Expected: no secrets, forbidden client runtime dependencies, destructive booking route, or deferred Phase 2 behavior. Human-readable translation copy may contain words such as "cancel" only for the approved booking status and dialog close action.

- [ ] **Step 7: Run final reviews and inspect the complete diff**

Run a fresh code-quality reviewer and security reviewer over the complete Phase 2A diff. Address every Critical and High finding and rerun the affected focused tests plus the full maintained gates. Then inspect:

```bash
git status --short
git diff --check
git diff --stat 39faff5..HEAD
git log --oneline 39faff5..HEAD
```

Expected: only Phase 2A paths are changed; the retired `src/` prototype and real `.env` are untouched.

- [ ] **Step 8: Commit browser coverage and any verified final corrections**

```bash
git add tests/e2e/admin-bookings.spec.ts tests/e2e/helpers/auth.ts
git commit -m "test: cover admin booking operations"
```

If final corrections touched implementation files, stage each inspected file explicitly and use a separate `fix:` commit whose message names the corrected behavior.

---

## Completion checklist

- [ ] Existing and newly registered users default to `customer`.
- [ ] Public requests cannot grant or revoke roles.
- [ ] Grant and revoke commands normalize email, preserve passwords, and protect the final administrator.
- [ ] Guests, unverified users, customers, and revoked admins cannot read or mutate admin booking data.
- [ ] Admin routes use middleware and policy checks.
- [ ] Search, filters, counts, ordering, literal wildcard handling, and 20-row pagination match the spec.
- [ ] Index and detail props contain only approved fields.
- [ ] Status transitions are forward-only, stale-safe, row-locked, and audited atomically.
- [ ] Audit actor snapshots survive account deletion while audit request metadata remains server-only.
- [ ] Customer booking pages reflect updated statuses without receiving admin history.
- [ ] The admin console works in Indonesian and English with keyboard, focus, touch, zoom, narrow-screen, and reduced-motion support.
- [ ] No payment, deletion, catalog CRUD, availability, notifications, or customer-management behavior was added.
- [ ] Laravel, Vue, Playwright, TypeScript, build, cache, format, audit, secret, and forbidden-runtime gates pass.
- [ ] PHP coverage reaches 80 percent when a coverage driver is available, or the missing driver is reported as a blocker.
