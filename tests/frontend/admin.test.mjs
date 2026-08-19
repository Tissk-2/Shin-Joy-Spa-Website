import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import test from 'node:test';

const projectRoot = fileURLToPath(new URL('../..', import.meta.url));
const routes = (await import(pathToFileURL(resolve(projectRoot, 'src/routes.js')).href)).default;
const translations = (await import(pathToFileURL(resolve(projectRoot, 'src/Data/translations.js')).href)).default;

test('admin routes are declared with lazy-loaded components', () => {
  const adminLoginRoute = routes.find(({ name }) => name === 'admin-login');
  const adminRoute = routes.find(({ name }) => name === 'admin');

  assert.ok(adminLoginRoute, '/admin-login route must be declared');
  assert.ok(adminRoute, '/admin route must be declared');

  assert.equal(typeof adminLoginRoute.component, 'function');
  assert.equal(typeof adminRoute.component, 'function');

  assert.equal(existsSync(resolve(projectRoot, 'src/Pages/AdminLogin.vue')), true);
  assert.equal(existsSync(resolve(projectRoot, 'src/Pages/Admin.vue')), true);
});

test('admin translations are complete and symmetric in id and en', () => {
  assert.deepEqual(
    Object.keys(translations.id).sort(),
    Object.keys(translations.en).sort(),
    'All translation keys must match in ID and EN dictionaries'
  );

  const expectedAdminKeys = [
    'ad.panel',
    'ad.logout',
    'ad.viewsite',
    'ad.greeting',
    'ad.nav.overview',
    'ad.nav.services',
    'ad.nav.categories',
    'ad.nav.gallery',
    'ad.nav.bookings',
    'ad.nav.content',
    'ad.ov.title',
    'ad.ov.services',
    'ad.ov.bookings',
    'ad.ov.today',
    'ad.ov.revenue',
    'ad.sv.title',
    'ad.sv.add',
    'ad.cat.title',
    'ad.gal.title',
    'ad.bk.title',
    'ad.ct.title',
    'auth.admin.badge',
    'auth.admin.title',
    'auth.admin.submit',
  ];

  for (const key of expectedAdminKeys) {
    assert.equal(typeof translations.id[key], 'string', `Missing ID translation for ${key}`);
    assert.equal(typeof translations.en[key], 'string', `Missing EN translation for ${key}`);
  }
});

test('admin view components expose rich frontend management controls', async () => {
  const adminSource = await readFile(resolve(projectRoot, 'src/Pages/Admin.vue'), 'utf8');

  // Verify all 6 view tabs are present
  assert.match(adminSource, /currentView === 'overview'/);
  assert.match(adminSource, /currentView === 'services'/);
  assert.match(adminSource, /currentView === 'categories'/);
  assert.match(adminSource, /currentView === 'gallery'/);
  assert.match(adminSource, /currentView === 'bookings'/);
  assert.match(adminSource, /currentView === 'content'/);

  // Verify dummy action helpers exist
  assert.match(adminSource, /openAddService/);
  assert.match(adminSource, /openEditService/);
  assert.match(adminSource, /deleteService/);
  assert.match(adminSource, /openAddCategory/);
  assert.match(adminSource, /updateBookingStatus/);
  assert.match(adminSource, /deleteBooking/);
  assert.match(adminSource, /saveAllContent/);
});
