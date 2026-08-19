import { isValidPhoneNumber } from './phone.js';

export const AUTH_STORAGE_KEY = 'shinjoy-auth-v1';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PASSWORD_ALGORITHM = 'PBKDF2-SHA-256';
const PASSWORD_ITERATIONS = 210000;

export function normalizeEmail(email) {
  return typeof email === 'string' ? email.trim().toLowerCase() : '';
}

export function validateRegistration(input = {}) {
  const values = isRecord(input) ? input : {};
  const name = typeof values.name === 'string' ? values.name.trim() : '';
  const email = normalizeEmail(values.email);
  const phone = typeof values.phone === 'string' ? values.phone.trim() : '';
  const password = typeof values.password === 'string' ? values.password : '';
  const confirmPassword = typeof values.confirmPassword === 'string' ? values.confirmPassword : '';
  const errors = {};

  if (name.length < 2) errors.name = 'auth.err.name';
  if (!EMAIL_PATTERN.test(email)) errors.email = 'auth.err.email';
  if (!isValidPhoneNumber(phone)) errors.phone = 'auth.err.phone';
  if (password.length < 8 || !/[A-Za-z]/.test(password) || !/\d/.test(password)) {
    errors.password = 'auth.err.password';
  }
  if (password !== confirmPassword) errors.confirmPassword = 'auth.err.confirm';

  return errors;
}

export function validateLogin(input = {}) {
  const values = isRecord(input) ? input : {};
  const errors = {};

  if (!EMAIL_PATTERN.test(normalizeEmail(values.email))) errors.email = 'auth.err.email';
  if (typeof values.password !== 'string' || values.password.length === 0) {
    errors.password = 'auth.err.passwordRequired';
  }

  return errors;
}

export function sanitizeRedirect(value, routes) {
  if (typeof value !== 'string' || !/^\/(?!\/)/.test(value) || value.includes('\\')) {
    return '/profile';
  }

  try {
    const url = new URL(value, 'https://shinjoy.local');
    const knownRoutes = new Set(Array.isArray(routes)
      ? routes.map((route) => typeof route === 'string' ? route : route?.path)
      : []);

    if (!knownRoutes.has(url.pathname) || url.pathname === '/login' || url.pathname === '/register') {
      return '/profile';
    }

    return value;
  } catch {
    return '/profile';
  }
}

export function createAuthStore(options = {}) {
  const storage = options.storage ?? getDefaultStorage();
  const webCrypto = options.crypto ?? globalThis.crypto;
  const now = options.now ?? (() => new Date());
  const randomBytes = options.randomBytes ?? ((length) => {
    const bytes = new Uint8Array(length);
    webCrypto.getRandomValues(bytes);
    return bytes;
  });
  const identifier = options.identifier ?? options.createId ?? (() => {
    if (typeof webCrypto?.randomUUID === 'function') return webCrypto.randomUUID();
    return bytesToBase64(randomBytes(16)).replace(/[+/=]/g, '');
  });

  function readState() {
    if (!hasStorageInterface(storage)) return null;

    try {
      const serialized = storage.getItem(AUTH_STORAGE_KEY);
      if (serialized === null) return emptyState();
      return parseState(serialized);
    } catch {
      return null;
    }
  }

  function writeState(state) {
    if (!hasStorageInterface(storage)) return false;

    try {
      storage.setItem(AUTH_STORAGE_KEY, JSON.stringify(cloneState(state)));
      return true;
    } catch {
      return false;
    }
  }

  async function register(input) {
    const fields = validateRegistration(input);
    if (Object.keys(fields).length > 0) return { ok: false, code: 'validation', fields };

    const state = readState();
    if (state === null) return { ok: false, code: 'storage-unavailable' };

    const email = normalizeEmail(input.email);
    if (state.accounts.some((account) => account.email === email)) {
      return { ok: false, code: 'duplicate-email' };
    }

    try {
      const salt = toByteArray(randomBytes(16));
      if (salt.length !== 16) throw new Error('Invalid random bytes');

      const password = await createPasswordRecord(input.password, salt, webCrypto);
      const id = typeof identifier === 'function' ? identifier() : identifier;
      if (typeof id !== 'string' || id.length === 0) throw new Error('Invalid account identifier');

      const account = {
        id,
        name: input.name.trim(),
        email,
        phone: input.phone.trim(),
        createdAt: toIsoTimestamp(now),
        password,
      };
      const nextState = {
        ...state,
        accounts: [...state.accounts, account],
        session: { accountId: account.id, createdAt: toIsoTimestamp(now) },
      };

      if (!writeState(nextState)) return { ok: false, code: 'storage-unavailable' };
      return { ok: true, user: publicUser(account) };
    } catch {
      return { ok: false, code: 'crypto-unavailable' };
    }
  }

  async function login(credentials) {
    const fields = validateLogin(credentials);
    if (Object.keys(fields).length > 0) return { ok: false, code: 'validation', fields };

    const state = readState();
    if (state === null) return { ok: false, code: 'storage-unavailable' };

    const account = state.accounts.find((candidate) => candidate.email === normalizeEmail(credentials.email));
    if (!account) return { ok: false, code: 'invalid-credentials' };

    try {
      const verified = await verifyPassword(credentials.password, account.password, webCrypto);
      if (!verified) return { ok: false, code: 'invalid-credentials' };
    } catch {
      return { ok: false, code: 'crypto-unavailable' };
    }

    const nextState = {
      ...state,
      session: { accountId: account.id, createdAt: toIsoTimestamp(now) },
    };
    if (!writeState(nextState)) return { ok: false, code: 'storage-unavailable' };
    return { ok: true, user: publicUser(account) };
  }

  function getCurrentUser() {
    const state = readState();
    if (state === null || state.session === null) return null;

    const account = state.accounts.find((candidate) => candidate.id === state.session.accountId);
    return account ? publicUser(account) : null;
  }

  function logout() {
    const state = readState();
    if (state === null || !writeState({ ...state, session: null })) {
      return { ok: false, code: 'storage-unavailable' };
    }
    return { ok: true };
  }

  return { register, login, getCurrentUser, logout };
}

function emptyState() {
  return { version: 1, accounts: [], session: null };
}

function getDefaultStorage() {
  try {
    return globalThis.localStorage;
  } catch {
    return null;
  }
}

function hasStorageInterface(storage) {
  return storage
    && typeof storage.getItem === 'function'
    && typeof storage.setItem === 'function'
    && typeof storage.removeItem === 'function';
}

function parseState(serialized) {
  try {
    const parsed = JSON.parse(serialized);
    if (!isRecord(parsed) || parsed.version !== 1 || !Array.isArray(parsed.accounts)) return emptyState();

    const emailAddresses = new Set();
    const accounts = parsed.accounts
      .filter(isStoredAccount)
      .filter((account) => {
        if (emailAddresses.has(account.email)) return false;
        emailAddresses.add(account.email);
        return true;
      })
      .map(cloneAccount);

    return {
      version: 1,
      accounts,
      session: isStoredSession(parsed.session) ? { ...parsed.session } : null,
    };
  } catch {
    return emptyState();
  }
}

function isStoredAccount(account) {
  return isRecord(account)
    && typeof account.id === 'string' && account.id.length > 0
    && typeof account.name === 'string' && account.name.trim().length >= 2
    && typeof account.email === 'string' && account.email === normalizeEmail(account.email) && EMAIL_PATTERN.test(account.email)
    && typeof account.phone === 'string'
    && isValidPhoneNumber(account.phone)
    && typeof account.createdAt === 'string' && Number.isFinite(Date.parse(account.createdAt))
    && isStoredPassword(account.password);
}

function isStoredPassword(password) {
  return isRecord(password)
    && password.algorithm === PASSWORD_ALGORITHM
    && password.iterations === PASSWORD_ITERATIONS
    && isBase64(password.salt)
    && isBase64(password.hash);
}

function isStoredSession(session) {
  return session === null || (isRecord(session)
    && typeof session.accountId === 'string' && session.accountId.length > 0
    && typeof session.createdAt === 'string' && Number.isFinite(Date.parse(session.createdAt)));
}

function isRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isBase64(value) {
  if (typeof value !== 'string' || value.length === 0 || !/^[A-Za-z0-9+/]*={0,2}$/.test(value)) return false;
  return fromBase64(value) !== null;
}

function cloneState(state) {
  return {
    version: 1,
    accounts: state.accounts.map(cloneAccount),
    session: state.session ? { ...state.session } : null,
  };
}

function cloneAccount(account) {
  return {
    id: account.id,
    name: account.name,
    email: account.email,
    phone: account.phone,
    createdAt: account.createdAt,
    password: { ...account.password },
  };
}

function publicUser(account) {
  return {
    id: account.id,
    name: account.name,
    email: account.email,
    phone: account.phone,
    createdAt: account.createdAt,
  };
}

function toIsoTimestamp(now) {
  const value = typeof now === 'function' ? now() : now;
  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.valueOf()) ? new Date().toISOString() : date.toISOString();
}

async function createPasswordRecord(password, salt, webCrypto) {
  const hash = await derivePasswordHash(password, salt, webCrypto);
  return {
    algorithm: PASSWORD_ALGORITHM,
    iterations: PASSWORD_ITERATIONS,
    salt: bytesToBase64(salt),
    hash: bytesToBase64(hash),
  };
}

async function verifyPassword(password, storedPassword, webCrypto) {
  const salt = fromBase64(storedPassword.salt);
  const expectedHash = fromBase64(storedPassword.hash);
  if (salt === null || expectedHash === null) return false;

  const actualHash = await derivePasswordHash(password, salt, webCrypto);
  return equalBytes(actualHash, expectedHash);
}

async function derivePasswordHash(password, salt, webCrypto) {
  if (!webCrypto?.subtle || typeof TextEncoder === 'undefined') throw new Error('Web Crypto unavailable');

  const key = await webCrypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(password),
    'PBKDF2',
    false,
    ['deriveBits'],
  );
  const hash = await webCrypto.subtle.deriveBits({
    name: 'PBKDF2',
    salt,
    iterations: PASSWORD_ITERATIONS,
    hash: 'SHA-256',
  }, key, 256);

  return new Uint8Array(hash);
}

function equalBytes(left, right) {
  if (left.length !== right.length) return false;
  let difference = 0;
  for (let index = 0; index < left.length; index += 1) difference |= left[index] ^ right[index];
  return difference === 0;
}

function toByteArray(value) {
  if (!ArrayBuffer.isView(value) && !(value instanceof ArrayBuffer)) throw new Error('Invalid bytes');
  return value instanceof Uint8Array ? new Uint8Array(value) : new Uint8Array(value.buffer ?? value);
}

function bytesToBase64(bytes) {
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return globalThis.btoa(binary);
}

function fromBase64(value) {
  try {
    const binary = globalThis.atob(value);
    return Uint8Array.from(binary, (character) => character.charCodeAt(0));
  } catch {
    return null;
  }
}
