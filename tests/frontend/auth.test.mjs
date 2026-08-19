import test from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import {
  AUTH_STORAGE_KEY,
  createAuthStore,
  normalizeEmail,
  sanitizeRedirect,
  validateLogin,
  validateRegistration,
} from '../../src/auth.js';

class MemoryStorage {
  #entries = new Map();

  getItem(key) {
    return this.#entries.has(key) ? this.#entries.get(key) : null;
  }

  setItem(key, value) {
    this.#entries.set(key, String(value));
  }

  removeItem(key) {
    this.#entries.delete(key);
  }

  dump() {
    return Object.fromEntries(this.#entries);
  }

  replace(key, value) {
    this.#entries.set(key, value);
  }
}

const validRegistration = Object.freeze({
  name: 'Ayu Lestari',
  email: 'ayu@example.com',
  phone: '+62 812 3456 7890',
  password: 'calmspa9',
  confirmPassword: 'calmspa9',
});

function createTestStore(storage = new MemoryStorage()) {
  return {
    storage,
    store: createAuthStore({
      storage,
      crypto: webcrypto,
      now: () => new Date('2026-08-13T08:30:00.000Z'),
      randomBytes: (length) => new Uint8Array(length).fill(7),
      identifier: () => 'account-ayu',
    }),
  };
}

test('normalizes email addresses for account lookup', () => {
  assert.equal(normalizeEmail('  AYU@Example.COM '), 'ayu@example.com');
});

test('registration validation returns field errors for invalid input', () => {
  assert.deepEqual(validateRegistration({
    name: 'A',
    email: 'bad',
    phone: '123',
    password: 'short',
    confirmPassword: 'different',
  }), {
    name: 'auth.err.name',
    email: 'auth.err.email',
    phone: 'auth.err.phone',
    password: 'auth.err.password',
    confirmPassword: 'auth.err.confirm',
  });
});

test('registration validation accepts complete registration input', () => {
  assert.deepEqual(validateRegistration({
    name: 'Ayu Lestari',
    email: 'ayu@example.com',
    phone: '+62 812 3456 7890',
    password: 'calmspa9',
    confirmPassword: 'calmspa9',
  }), {});
});

test('registration rejects parenthesized phone numbers without rewriting valid display numbers', async () => {
  assert.equal(validateRegistration({
    ...validRegistration,
    phone: '+62 (812) 3456 7890',
  }).phone, 'auth.err.phone');

  const { store } = createTestStore();
  const result = await store.register(validRegistration);

  assert.equal(result.ok, true);
  assert.equal(result.user.phone, '+62 812 3456 7890');
});

test('registration validation requires passwords with both letters and digits', () => {
  const baseInput = {
    name: 'Ayu Lestari',
    email: 'ayu@example.com',
    phone: '+62 812 3456 7890',
  };

  for (const password of ['onlyletters', '1234567890']) {
    assert.equal(validateRegistration({
      ...baseInput,
      password,
      confirmPassword: password,
    }).password, 'auth.err.password');
  }

  assert.equal(validateRegistration({
    ...baseInput,
    password: 'calmspa9',
    confirmPassword: 'calmspa9',
  }).password, undefined);
});

test('login validation requires an email and password', () => {
  assert.deepEqual(validateLogin({ email: '', password: '' }), {
    email: 'auth.err.email',
    password: 'auth.err.passwordRequired',
  });
});

test('authentication actions return validation results for non-record input', async () => {
  const { store } = createTestStore();

  assert.equal((await store.register(null)).code, 'validation');
  assert.equal((await store.login(null)).code, 'validation');
});

test('redirect sanitization permits only known internal destinations', () => {
  const known = ['/', '/services', '/booking', '/about', '/profile'];

  assert.equal(sanitizeRedirect('/booking?service=facial', known), '/booking?service=facial');
  assert.equal(sanitizeRedirect('//evil.example', known), '/profile');
  assert.equal(sanitizeRedirect('https://evil.example', known), '/profile');
  assert.equal(sanitizeRedirect('/missing', known), '/profile');
});

test('registers an account, stores only a password hash, and creates a public session', async () => {
  const { storage, store } = createTestStore();
  const input = { ...validRegistration };

  const registered = await store.register(input);

  assert.equal(registered.ok, true);
  assert.deepEqual(store.getCurrentUser(), registered.user);
  assert.equal(JSON.stringify(storage.dump()).includes('calmspa9'), false);
  assert.deepEqual(Object.keys(registered.user).sort(), ['createdAt', 'email', 'id', 'name', 'phone']);
  assert.deepEqual(input, validRegistration);
});

test('rejects duplicate normalized emails and authenticates only the matching password', async () => {
  const { store } = createTestStore();
  await store.register(validRegistration);

  const duplicate = await store.register({ ...validRegistration, email: ' AYU@EXAMPLE.COM ' });
  assert.deepEqual(duplicate, { ok: false, code: 'duplicate-email' });

  store.logout();
  assert.equal(store.getCurrentUser(), null);
  assert.equal((await store.login({ email: 'ayu@example.com', password: 'wrongpass9' })).code, 'invalid-credentials');
  assert.equal((await store.login({ email: 'ayu@example.com', password: 'calmspa9' })).ok, true);
});

test('does not mutate or leak credentials through returned public users', async () => {
  const { store } = createTestStore();
  const result = await store.register(validRegistration);

  result.user.name = 'Changed locally';

  assert.equal(store.getCurrentUser().name, 'Ayu Lestari');
  assert.equal('password' in result.user, false);
  assert.equal('hash' in result.user, false);
  assert.equal('salt' in result.user, false);
});

test('defensively handles malformed and structurally invalid stored records', async () => {
  const storage = new MemoryStorage();
  storage.replace(AUTH_STORAGE_KEY, '{not json');
  const { store } = createTestStore(storage);

  assert.equal(store.getCurrentUser(), null);
  assert.equal((await store.login({ email: 'ayu@example.com', password: 'calmspa9' })).code, 'invalid-credentials');

  storage.replace(AUTH_STORAGE_KEY, JSON.stringify({
    version: 1,
    accounts: [{ id: 'bad', email: 'ayu@example.com' }],
    session: { accountId: 'bad', createdAt: '2026-08-13T08:30:00.000Z' },
  }));
  assert.equal(store.getCurrentUser(), null);

  storage.replace(AUTH_STORAGE_KEY, JSON.stringify({
    version: 1,
    accounts: [{
      id: 'bad-phone',
      name: 'Ayu Lestari',
      email: 'ayu@example.com',
      phone: '--------',
      createdAt: '2026-08-13T08:30:00.000Z',
      password: {
        algorithm: 'PBKDF2-SHA-256',
        iterations: 210000,
        salt: 'AQ==',
        hash: 'Ag==',
      },
    }],
    session: { accountId: 'bad-phone', createdAt: '2026-08-13T08:30:00.000Z' },
  }));
  assert.equal(store.getCurrentUser(), null);
});

test('treats sessions for deleted accounts as signed out', async () => {
  const { storage, store } = createTestStore();
  await store.register(validRegistration);
  const saved = JSON.parse(storage.dump()[AUTH_STORAGE_KEY]);
  saved.accounts = [];
  storage.replace(AUTH_STORAGE_KEY, JSON.stringify(saved));

  assert.equal(store.getCurrentUser(), null);
});

test('returns storage-unavailable when required storage reads or writes fail', async () => {
  const readFailure = {
    getItem() { throw new Error('read blocked'); },
    setItem() {},
    removeItem() {},
  };
  const writeFailure = {
    getItem() { return null; },
    setItem() { throw new Error('write blocked'); },
    removeItem() {},
  };

  const unreadable = createTestStore(readFailure).store;
  const unwritable = createTestStore(writeFailure).store;

  assert.equal((await unreadable.register(validRegistration)).code, 'storage-unavailable');
  assert.equal(unreadable.getCurrentUser(), null);
  assert.equal((await unwritable.register(validRegistration)).code, 'storage-unavailable');
});

test('login reports storage-unavailable when the session write fails', async () => {
  const seeded = createTestStore();
  await seeded.store.register(validRegistration);
  const savedState = JSON.parse(seeded.storage.dump()[AUTH_STORAGE_KEY]);
  const signedOutState = JSON.stringify({ ...savedState, session: null });
  const { store } = createTestStore({
    getItem() { return signedOutState; },
    setItem() { throw new Error('session write blocked'); },
    removeItem() {},
  });

  assert.deepEqual(await store.login({
    email: validRegistration.email,
    password: validRegistration.password,
  }), { ok: false, code: 'storage-unavailable' });
  assert.equal(store.getCurrentUser(), null);
});

test('logout reports storage-unavailable for read and write failures without clearing the stored session', async () => {
  const seeded = createTestStore();
  await seeded.store.register(validRegistration);
  const activeState = seeded.storage.dump()[AUTH_STORAGE_KEY];
  const unreadable = createTestStore({
    getItem() { throw new Error('session read blocked'); },
    setItem() {},
    removeItem() {},
  }).store;
  const unwritable = createTestStore({
    getItem() { return activeState; },
    setItem() { throw new Error('session write blocked'); },
    removeItem() {},
  }).store;

  assert.deepEqual(unreadable.logout(), { ok: false, code: 'storage-unavailable' });
  assert.deepEqual(unwritable.logout(), { ok: false, code: 'storage-unavailable' });
  assert.equal(unwritable.getCurrentUser()?.id, 'account-ayu');
});

test('returns storage-unavailable when default storage resolution throws', async () => {
  const originalLocalStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage');
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    get() {
      throw new Error('storage access blocked');
    },
  });

  try {
    const store = createAuthStore({ crypto: webcrypto });

    assert.equal((await store.register(validRegistration)).code, 'storage-unavailable');
  } finally {
    if (originalLocalStorage) {
      Object.defineProperty(globalThis, 'localStorage', originalLocalStorage);
    } else {
      delete globalThis.localStorage;
    }
  }
});
