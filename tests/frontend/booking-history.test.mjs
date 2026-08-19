import assert from 'node:assert/strict';
import test from 'node:test';
import {
  BOOKING_STORAGE_KEY,
  createBookingStore,
  partitionBookings,
} from '../../src/bookingHistory.js';

function createUser(id = 'account-ayu') {
  return { id };
}

function createForm(overrides = {}) {
  return {
    name: 'Ayu Lestari',
    email: 'ayu@example.com',
    phone: '+62 812 3456 7890',
    date: '2026-08-13',
    time: '10:30',
    guests: 2,
    notes: 'Please prepare warm tea.',
    ...overrides,
  };
}

function createService(overrides = {}) {
  return {
    slug: 'javanese-massage',
    nameId: 'Pijat Tradisional Jawa',
    nameEn: 'Traditional Javanese Massage',
    duration: 90,
    price: 185000,
    ...overrides,
  };
}

function createMemoryStorage(initialValue = null) {
  let value = initialValue;
  return {
    getItem(key) {
      assert.equal(key, BOOKING_STORAGE_KEY);
      return value;
    },
    setItem(key, nextValue) {
      assert.equal(key, BOOKING_STORAGE_KEY);
      value = nextValue;
    },
    read() {
      return value;
    },
  };
}

test('adds an immutable, account-owned snapshot and lists only that account', () => {
  const user = createUser();
  const otherUser = createUser('account-bima');
  const form = createForm();
  const service = createService();
  const storage = createMemoryStorage();
  const store = createBookingStore({
    storage,
    now: () => new Date('2026-08-13T10:00:00+07:00'),
    createId: () => 'booking-ayu',
  });

  const result = store.addForUser(user, form, service);

  assert.deepEqual(result, {
    ok: true,
    booking: {
      id: 'booking-ayu',
      accountId: 'account-ayu',
      createdAt: '2026-08-13T03:00:00.000Z',
      name: 'Ayu Lestari',
      email: 'ayu@example.com',
      phone: '+62 812 3456 7890',
      service: {
        slug: 'javanese-massage',
        nameId: 'Pijat Tradisional Jawa',
        nameEn: 'Traditional Javanese Massage',
        duration: 90,
        price: 185000,
      },
      date: '2026-08-13',
      time: '10:30',
      guests: 2,
      notes: 'Please prepare warm tea.',
    },
  });
  assert.deepEqual(JSON.parse(storage.read()), {
    version: 1,
    bookings: [result.booking],
  });

  form.name = 'Changed form name';
  service.nameEn = 'Changed service name';
  result.booking.service.nameId = 'Changed result service name';

  const firstResult = store.listForUser(user.id);
  const secondResult = store.listForUser(user.id);
  const firstList = firstResult.bookings;
  const secondList = secondResult.bookings;
  assert.equal(firstResult.ok, true);
  assert.equal(secondResult.ok, true);
  assert.notEqual(firstList, secondList);
  assert.notEqual(firstList[0], secondList[0]);
  assert.equal(firstList[0].name, 'Ayu Lestari');
  assert.equal(firstList[0].service.nameEn, 'Traditional Javanese Massage');
  assert.equal(firstList[0].service.nameId, 'Pijat Tradisional Jawa');
  assert.deepEqual(store.listForUser(otherUser.id), { ok: true, bookings: [] });
});

test('partitions bookings by local appointment date and time without mutating records', () => {
  const bookings = [
    { id: 'later', date: '2026-08-14', time: '09:00' },
    { id: 'old-past', date: '2026-08-12', time: '20:00' },
    { id: 'soon', date: '2026-08-13', time: '10:30' },
    { id: 'recent-past', date: '2026-08-13', time: '09:30' },
  ];
  const original = structuredClone(bookings);

  const result = partitionBookings(bookings, new Date('2026-08-13T10:00:00+07:00'));

  assert.deepEqual(result.upcoming.map(({ id }) => id), ['soon', 'later']);
  assert.deepEqual(result.past.map(({ id }) => id), ['recent-past', 'old-past']);
  assert.deepEqual(bookings, original);
  assert.notEqual(result.upcoming[0], bookings[2]);
});

test('handles corrupt storage and reports unavailable storage writes', () => {
  const user = createUser();
  const form = createForm();
  const service = createService();
  const corruptStore = createBookingStore({ storage: createMemoryStorage('{bad json') });
  assert.deepEqual(corruptStore.listForUser(user.id), { ok: true, bookings: [] });

  const failingStore = createBookingStore({
    storage: {
      getItem: () => null,
      setItem: () => {
        throw new Error('quota exceeded');
      },
    },
  });
  assert.deepEqual(failingStore.addForUser(user, form, service), {
    ok: false,
    code: 'storage-unavailable',
  });
});

test('does not overwrite history when browser storage cannot be read', () => {
  const user = createUser();
  const form = createForm();
  const service = createService();
  const store = createBookingStore({
    storage: {
      getItem: () => {
        throw new Error('storage blocked');
      },
      setItem: () => {},
    },
  });

  assert.deepEqual(store.addForUser(user, form, service), {
    ok: false,
    code: 'storage-unavailable',
  });
});

test('reports booking-history read failures distinctly from an empty history', () => {
  const store = createBookingStore({
    storage: {
      getItem() {
        throw new Error('history read blocked');
      },
      setItem() {},
    },
  });

  assert.deepEqual(store.listForUser(createUser().id), {
    ok: false,
    code: 'storage-unavailable',
    bookings: [],
  });
});

test('rejects invalid users, services, dates, and appointment times', () => {
  const user = createUser();
  const form = createForm();
  const service = createService();
  const store = createBookingStore({ storage: createMemoryStorage() });

  for (const [invalidUser, invalidForm, invalidService] of [
    [{}, form, service],
    [user, form, createService({ price: -1 })],
    [user, createForm({ date: '2026-02-30' }), service],
    [user, createForm({ time: '08:00' }), service],
    [user, createForm({ phone: '+62 (812) 3456 7890' }), service],
  ]) {
    assert.deepEqual(store.addForUser(invalidUser, invalidForm, invalidService), {
      ok: false,
      code: 'invalid-booking',
    });
  }
});
