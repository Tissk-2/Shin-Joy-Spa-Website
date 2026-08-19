import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import test from 'node:test';

const projectRoot = fileURLToPath(new URL('../..', import.meta.url));
const routes = (await import(pathToFileURL(resolve(projectRoot, 'src/routes.js')).href)).default;
const authModule = await import(pathToFileURL(resolve(projectRoot, 'src/useAuth.js')).href);
const translations = (await import(pathToFileURL(resolve(projectRoot, 'src/Data/translations.js')).href)).default;
const routerSource = await readFile(resolve(projectRoot, 'src/router.js'), 'utf8');
const useAuthSource = await readFile(resolve(projectRoot, 'src/useAuth.js'), 'utf8');

test('auth routes are lazy-loaded and profile is protected', () => {
  assert.equal(routes.find(({ name }) => name === 'profile')?.meta?.requiresAuth, true);

  for (const [name, path] of [
    ['login', './Pages/Login.vue'],
    ['register', './Pages/Register.vue'],
    ['profile', './Pages/Profile.vue'],
  ]) {
    const route = routes.find((candidate) => candidate.name === name);
    assert.equal(typeof route?.component, 'function');
    assert.match(route.component.toString(), new RegExp(`import\\(['"]${path.replace('.', '\\.') }['"]\\)`));
  }
});

test('router wires one global auth guard with the required redirects', () => {
  assert.match(routerSource, /import\s*\{\s*initializeAuth\s*,\s*isAuthenticated\s*\}\s*from\s*['"]\.\/useAuth\.js['"]/);
  assert.match(routerSource, /router\.beforeEach\(/);
  assert.match(routerSource, /name:\s*['"]login['"],\s*query:\s*\{\s*redirect:\s*to\.fullPath\s*\}/s);
  assert.match(routerSource, /name:\s*['"]profile['"]/, 'authenticated auth-page visits redirect to profile');
});

test('useAuth exposes only the public singleton session API', async () => {
  assert.deepEqual(Object.keys(authModule).sort(), [
    'currentUser',
    'initializeAuth',
    'isAuthenticated',
    'loginUser',
    'logoutUser',
    'registerUser',
  ]);
  assert.equal(authModule.currentUser.value, null);
  assert.equal(authModule.isAuthenticated.value, false);
  assert.equal(useAuthSource.match(/createAuthStore\(/g)?.length, 1);
  assert.match(useAuthSource, /const\s+currentUserRef\s*=\s*ref\(null\)/);
  assert.match(useAuthSource, /export\s+const\s+currentUser\s*=\s*readonly\(currentUserRef\)/);
  assert.match(useAuthSource, /export\s+function\s+logoutUser\(\)\s*\{[\s\S]*?const\s+result\s*=\s*authStore\.logout\(\);[\s\S]*?if\s*\(result\.ok\)\s*currentUserRef\.value\s*=\s*null;[\s\S]*?return\s+result;/);
  assert.doesNotMatch(useAuthSource, /export\s+const\s+authStore/);
  assert.equal(await authModule.initializeAuth(), null);
  assert.equal(await authModule.initializeAuth(), null);
});

test('auth pages expose localized, accessible form contracts', async () => {
  const loginPath = resolve(projectRoot, 'src/Pages/Login.vue');
  const registerPath = resolve(projectRoot, 'src/Pages/Register.vue');
  assert.equal(existsSync(loginPath), true, 'Login.vue must exist');
  assert.equal(existsSync(registerPath), true, 'Register.vue must exist');

  const [loginSource, registerSource] = await Promise.all([
    readFile(loginPath, 'utf8'),
    readFile(registerPath, 'utf8'),
  ]);

  assert.match(loginSource, /@submit\.prevent="submit"/);
  assert.match(loginSource, /autocomplete="current-password"/);
  assert.match(loginSource, /aria-live="polite"/);
  assert.match(loginSource, /<p\s+class="auth-error"\s+role="status"\s+aria-live="polite"\s+aria-atomic="true"\s+:class="\{ 'is-visible': submitError \}"/s);
  assert.doesNotMatch(loginSource, /<p\s+class="auth-error"[^>]*aria-hidden=/);
  assert.doesNotMatch(loginSource, /<p\s+v-if="submitError"\s+class="auth-error"/);
  assert.match(loginSource, /:aria-label="tr\('auth\.language'\)"/);
  assert.match(loginSource, /:aria-label="tr\('auth\.brand'\)"/);
  assert.match(loginSource, /pageTitle\s*=\s*computed\(\(\)\s*=>\s*`\$\{tr\('auth\.login\.title'\)\} · \$\{tr\('auth\.brand'\)\}`/);
  assert.doesNotMatch(loginSource, /aria-label="Language"|aria-label="Local demo notice"/);
  assert.match(loginSource, /loginUser/);
  assert.match(loginSource, /validateLogin/);
  assert.match(loginSource, /sanitizeRedirect/);
  assert.match(registerSource, /@submit\.prevent="submit"/);
  assert.match(registerSource, /autocomplete="new-password"/);
  assert.match(registerSource, /aria-live="polite"/);
  assert.match(registerSource, /<p\s+class="auth-error"\s+role="status"\s+aria-live="polite"\s+aria-atomic="true"\s+:class="\{ 'is-visible': submitError \}"/s);
  assert.doesNotMatch(registerSource, /<p\s+class="auth-error"[^>]*aria-hidden=/);
  assert.doesNotMatch(registerSource, /<p\s+v-if="submitError"\s+class="auth-error"/);
  assert.match(registerSource, /:aria-label="tr\('auth\.language'\)"/);
  assert.match(registerSource, /:aria-label="tr\('auth\.brand'\)"/);
  assert.match(registerSource, /pageTitle\s*=\s*computed\(\(\)\s*=>\s*`\$\{tr\('auth\.register\.title'\)\} · \$\{tr\('auth\.brand'\)\}`/);
  assert.doesNotMatch(registerSource, /\saria-label="[^"]+"/);
  assert.doesNotMatch(registerSource, /aria-label="Language"|aria-label="Local demo notice"/);
  assert.match(registerSource, /registerUser/);
  assert.match(registerSource, /validateRegistration/);
  assert.match(registerSource, /router\.replace\(\{ name: 'profile' \}\)/);

  assert.deepEqual(Object.keys(translations.id).sort(), Object.keys(translations.en).sort());
  for (const key of [
    'nav.login', 'nav.register', 'nav.profile', 'nav.logout',
    'auth.brand',
    'auth.login.title', 'auth.login.sub', 'auth.login.submit', 'auth.login.pending',
    'auth.register.title', 'auth.register.sub', 'auth.register.submit', 'auth.register.pending',
    'auth.name', 'auth.email', 'auth.phone', 'auth.password', 'auth.confirmPassword',
    'auth.showPassword', 'auth.hidePassword', 'auth.back', 'auth.newUser', 'auth.existingUser',
    'auth.goRegister', 'auth.goLogin', 'auth.language', 'auth.demo.title', 'auth.demo.body', 'auth.demo.asideQuote',
    'auth.demo.asideAlt', 'auth.error.credentials', 'auth.error.duplicate', 'auth.error.storage',
    'auth.err.name', 'auth.err.email', 'auth.err.phone', 'auth.err.password', 'auth.err.confirm',
    'auth.err.passwordRequired',
  ]) {
    assert.equal(typeof translations.id[key], 'string', `Indonesian translation missing: ${key}`);
    assert.equal(typeof translations.en[key], 'string', `English translation missing: ${key}`);
  }

  assert.equal(
    translations.id['auth.err.password'],
    'Kata sandi harus terdiri dari minimal 8 karakter serta mengandung huruf dan angka.',
  );
  assert.equal(
    translations.en['auth.err.password'],
    'Password must be at least 8 characters and include letters and numbers.',
  );
});

test('profile history and signed-in booking source contracts are present', async () => {
  const profilePath = resolve(projectRoot, 'src/Pages/Profile.vue');
  const bookingPath = resolve(projectRoot, 'src/Pages/Booking.vue');
  assert.equal(existsSync(profilePath), true, 'Profile.vue must exist');

  const [profileSource, bookingSource] = await Promise.all([
    readFile(profilePath, 'utf8'),
    readFile(bookingPath, 'utf8'),
  ]);

  assert.match(profileSource, /import\s*\{\s*partitionBookings\s*\}\s*from\s*['"]\.\.\/bookingHistory\.js['"]/);
  assert.match(profileSource, /listForUser\(currentUser\.value\.id\)/);
  assert.match(profileSource, /const\s+historyUnavailable\s*=\s*computed\(\(\)\s*=>\s*!bookingResult\.value\.ok\)/);
  assert.match(profileSource, /partitionBookings\(bookingResult\.value\.bookings,\s*new Date\(\)\)/);
  assert.match(profileSource, /<nav\s+class="breadcrumb"\s+:aria-label="tr\('nav\.breadcrumb'\)"/);
  assert.match(profileSource, /v-if="historyUnavailable"[\s\S]*?role="status"[\s\S]*?aria-live="polite"[\s\S]*?tr\('profile\.historyUnavailable'\)/);
  assert.match(profileSource, /<section[^>]*class="history-section"[^>]*aria-labelledby="upcoming-history-title">/);
  assert.match(profileSource, /<section[^>]*class="history-section"[^>]*aria-labelledby="past-history-title">/);
  assert.match(profileSource, /booking\.service\.nameId/);
  assert.match(profileSource, /booking\.service\.nameEn/);
  assert.match(profileSource, /<article[^>]*class="history-card"/);
  assert.match(profileSource, /<time\s+:datetime="booking\.date">/);
  assert.match(profileSource, /<RouterLink[^>]*to="\/booking"/);
  assert.doesNotMatch(profileSource, /cancel|delete/i);

  assert.match(bookingSource, /import\s*\{\s*currentUser\s*,\s*initializeAuth\s*\}\s*from\s*['"]\.\.\/useAuth\.js['"]/);
  assert.match(bookingSource, /initializeAuth\(\)/);
  assert.match(bookingSource, /if\s*\(!form\.name\)\s*form\.name\s*=\s*currentUser\.value\.name/);
  assert.match(bookingSource, /if\s*\(!form\.email\)\s*form\.email\s*=\s*currentUser\.value\.email/);
  assert.match(bookingSource, /if\s*\(!form\.phone\)\s*form\.phone\s*=\s*currentUser\.value\.phone/);
  assert.match(bookingSource, /currentUser\.value\s*&&\s*!savedBookingId\.value/);
  assert.match(bookingSource, /bookingStore\.addForUser\(currentUser\.value,\s*form,\s*selectedService\.value\)/);
  assert.match(bookingSource, /historyWarning\.value\s*=\s*true/);
  assert.match(bookingSource, /function\s+reset\(\)\s*\{[\s\S]*?savedBookingId\.value\s*=\s*'';[\s\S]*?service:\s*''[\s\S]*?date:\s*''[\s\S]*?time:\s*''[\s\S]*?guests:\s*1[\s\S]*?notes:\s*''[\s\S]*?prefilledSessionDetails\.value\s*=\s*false;[\s\S]*?prefillSessionDetails\(\);/);

  for (const key of ['nav.breadcrumb', 'profile.historyUnavailable']) {
    assert.equal(typeof translations.id[key], 'string', `Indonesian translation missing: ${key}`);
    assert.equal(typeof translations.en[key], 'string', `English translation missing: ${key}`);
  }
});

test('main layout provides safe, localized account controls for every session state', async () => {
  const layoutSource = await readFile(resolve(projectRoot, 'src/Layouts/MainLayout.vue'), 'utf8');

  assert.match(layoutSource, /import\s*\{\s*currentUser\s*,\s*isAuthenticated\s*,\s*logoutUser\s*\}\s*from\s*['"]\.\.\/useAuth\.js['"]/);
  assert.match(layoutSource, /import\s*\{\s*initialHeaderMenuState\s*,\s*transitionHeaderMenuState\s*\}\s*from\s*['"]\.\.\/headerMenuState\.js['"]/);
  assert.match(layoutSource, /const\s+accountInitial\s*=\s*computed\(/);
  assert.match(layoutSource, /currentUser\.value\?\.name\?\.trim\(\)\.charAt\(0\)\.toLocaleUpperCase\(\)\s*\|\|\s*['"]\?['"]/);
  assert.match(layoutSource, /const\s+accountLabel\s*=\s*computed\(/);
  assert.match(layoutSource, /tr\('nav\.account'\)/);
  assert.match(layoutSource, /:aria-label="tr\('nav\.primary'\)"/);
  assert.match(layoutSource, /:aria-label="tr\('nav\.language'\)"/);
  assert.doesNotMatch(layoutSource, /aria-label="Primary"|aria-label="Language"/);
  assert.match(layoutSource, /v-if="!isAuthenticated"/);
  assert.match(layoutSource, /<RouterLink[^>]*to="\/login"/);
  assert.match(layoutSource, /tr\('nav\.loginRegister'\)/);
  assert.match(layoutSource, /v-else[^>]*class="account-menu-wrapper"/);
  assert.match(layoutSource, /<button\s+ref="accountToggle"\s+type="button"\s+class="account-toggle"[\s\S]*?:aria-expanded="isAccountMenuOpen"[\s\S]*?aria-controls="account-menu"[\s\S]*?:aria-label="accountLabel"/);
  assert.match(layoutSource, /<div\s+v-if="isAccountMenuOpen"\s+id="account-menu"\s+ref="accountMenu"\s+class="account-menu"/);
  assert.match(layoutSource, /<RouterLink[^>]*to="\/profile"/);
  assert.match(layoutSource, /<button\s+type="button"\s+class="account-menu__logout"[^>]*@click="handleLogout"/);
  assert.match(layoutSource, /function\s+setAccountMenu\(/);
  assert.match(layoutSource, /function\s+handleOutsidePointer\(/);
  assert.match(layoutSource, /document\.addEventListener\('pointerdown',\s*handleOutsidePointer\)/);
  assert.match(layoutSource, /document\.removeEventListener\('pointerdown',\s*handleOutsidePointer\)/);
  assert.match(layoutSource, /if\s*\(event\.key\s*===\s*'Escape'\)\s*\{[\s\S]*?type:\s*'escape'/);
  assert.match(layoutSource, /transitionHeaderMenuState\(headerMenuState\.value,\s*event,/);
  assert.match(layoutSource, /type:\s*'route-change'/);
  assert.match(layoutSource, /type:\s*'outside-pointer'/);
  assert.match(layoutSource, /type:\s*'logout'/);
  assert.doesNotMatch(layoutSource, /if\s*\(open\)\s*setMenu\(false,\s*false\)/);
  assert.match(layoutSource, /const\s+logoutError\s*=\s*ref\(false\)/);
  assert.match(layoutSource, /async\s+function\s+handleLogout\(\)\s*\{[\s\S]*?logoutError\.value\s*=\s*false;[\s\S]*?const\s+result\s*=\s*await\s+logoutUser\(\);[\s\S]*?if\s*\(!result\.ok\)\s*\{[\s\S]*?logoutError\.value\s*=\s*true;[\s\S]*?return;[\s\S]*?\}[\s\S]*?type:\s*'logout'[\s\S]*?document\.body\.style\.overflow\s*=\s*'';[\s\S]*?await\s+router\.replace\('\/'\);/);
  assert.match(layoutSource, /<p\s+v-if="logoutError"\s+class="auth-notice account-menu__notice"\s+role="status"\s+aria-live="polite">\s*\{\{\s*tr\('auth\.error\.storage'\)\s*\}\}\s*<\/p>/s);

  for (const key of ['nav.account', 'nav.login', 'nav.register', 'nav.profile', 'nav.logout', 'nav.primary', 'nav.language', 'auth.error.storage']) {
    assert.equal(typeof translations.id[key], 'string', `Indonesian translation missing: ${key}`);
    assert.equal(typeof translations.en[key], 'string', `English translation missing: ${key}`);
  }
});

test('header menu state preserves an open account menu in desktop and mobile contexts', async () => {
  const statePath = resolve(projectRoot, 'src/headerMenuState.js');
  assert.equal(existsSync(statePath), true, 'headerMenuState.js must exist');

  const { initialHeaderMenuState, transitionHeaderMenuState } = await import(pathToFileURL(statePath).href);
  let restoredAccountFocus = 0;
  const restoreAccountFocus = () => {
    restoredAccountFocus += 1;
  };

  let state = transitionHeaderMenuState(initialHeaderMenuState, { type: 'set-account-menu', open: true }, restoreAccountFocus);
  assert.deepEqual(state, { mobileMenuOpen: false, accountMenuOpen: true });

  state = transitionHeaderMenuState(state, { type: 'outside-pointer' }, restoreAccountFocus);
  assert.deepEqual(state, { mobileMenuOpen: false, accountMenuOpen: false });
  assert.equal(restoredAccountFocus, 0);

  state = transitionHeaderMenuState(state, { type: 'set-mobile-menu', open: true }, restoreAccountFocus);
  state = transitionHeaderMenuState(state, { type: 'set-account-menu', open: true }, restoreAccountFocus);
  assert.deepEqual(state, { mobileMenuOpen: true, accountMenuOpen: true });

  state = transitionHeaderMenuState(state, { type: 'escape' }, restoreAccountFocus);
  assert.deepEqual(state, { mobileMenuOpen: true, accountMenuOpen: false });
  assert.equal(restoredAccountFocus, 1);

  state = transitionHeaderMenuState(state, { type: 'set-account-menu', open: true }, restoreAccountFocus);
  state = transitionHeaderMenuState(state, { type: 'set-mobile-menu', open: false }, restoreAccountFocus);
  assert.deepEqual(state, { mobileMenuOpen: false, accountMenuOpen: false });

  state = transitionHeaderMenuState(state, { type: 'set-mobile-menu', open: true }, restoreAccountFocus);
  state = transitionHeaderMenuState(state, { type: 'set-account-menu', open: true }, restoreAccountFocus);
  state = transitionHeaderMenuState(state, { type: 'route-change' }, restoreAccountFocus);
  assert.deepEqual(state, { mobileMenuOpen: false, accountMenuOpen: false });

  state = transitionHeaderMenuState(state, { type: 'set-account-menu', open: true }, restoreAccountFocus);
  state = transitionHeaderMenuState(state, { type: 'logout' }, restoreAccountFocus);
  assert.deepEqual(state, { mobileMenuOpen: false, accountMenuOpen: false });
});
