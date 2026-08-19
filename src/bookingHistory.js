import { timeSlots } from './booking.js';
import { isValidPhoneNumber } from './phone.js';

export const BOOKING_STORAGE_KEY = 'shinjoy-bookings-v1';

const EMPTY_STORAGE = Object.freeze({ version: 1, bookings: [] });

export function createBookingStore(options = {}) {
  const storage = options.storage === undefined ? getBrowserStorage() : options.storage;
  const now = options.now ?? (() => new Date());
  const createId = options.createId ?? createBookingId;

  return {
    addForUser(user, form, service) {
      if (!isValidUser(user) || !isValidBooking(form, service)) {
        return { ok: false, code: 'invalid-booking' };
      }

      const stored = readStoredBookings(storage);
      if (!stored.ok) return { ok: false, code: 'storage-unavailable' };

      const createdAt = toValidDate(now());
      const booking = createBookingRecord(user, form, service, createdAt, createId);
      const nextValue = {
        version: 1,
        bookings: [...stored.bookings, booking],
      };

      try {
        storage.setItem(BOOKING_STORAGE_KEY, JSON.stringify(nextValue));
      } catch {
        return { ok: false, code: 'storage-unavailable' };
      }

      return { ok: true, booking: copyBooking(booking) };
    },

    listForUser(userId) {
      if (typeof userId !== 'string' || !userId.trim()) return { ok: true, bookings: [] };
      const stored = readStoredBookings(storage);
      if (!stored.ok) {
        return { ok: false, code: 'storage-unavailable', bookings: [] };
      }

      return {
        ok: true,
        bookings: stored.bookings
          .filter((booking) => booking.accountId === userId)
          .map(copyBooking),
      };
    },
  };
}

export function partitionBookings(bookings, now = new Date()) {
  const currentTime = toValidDate(now);
  const result = { upcoming: [], past: [] };

  for (const booking of Array.isArray(bookings) ? bookings : []) {
    const appointmentAt = appointmentDate(booking?.date, booking?.time);
    if (!appointmentAt) continue;

    const copiedBooking = copyBooking(booking);
    if (appointmentAt.getTime() >= currentTime.getTime()) {
      result.upcoming.push({ booking: copiedBooking, appointmentAt });
    } else {
      result.past.push({ booking: copiedBooking, appointmentAt });
    }
  }

  return {
    upcoming: result.upcoming
      .sort((left, right) => left.appointmentAt - right.appointmentAt)
      .map(({ booking }) => booking),
    past: result.past
      .sort((left, right) => right.appointmentAt - left.appointmentAt)
      .map(({ booking }) => booking),
  };
}

function getBrowserStorage() {
  try {
    return typeof window === 'undefined' ? null : window.localStorage;
  } catch {
    return null;
  }
}

function readStoredBookings(storage) {
  if (!storage || typeof storage.getItem !== 'function' || typeof storage.setItem !== 'function') {
    return { ok: false, bookings: [] };
  }

  let rawValue;
  try {
    rawValue = storage.getItem(BOOKING_STORAGE_KEY);
  } catch {
    return { ok: false, bookings: [] };
  }

  if (!rawValue) return { ok: true, bookings: [] };

  try {
    const parsed = JSON.parse(rawValue);
    if (parsed?.version !== EMPTY_STORAGE.version || !Array.isArray(parsed.bookings)) {
      return { ok: true, bookings: [] };
    }

    return {
      ok: true,
      bookings: parsed.bookings.filter(isStoredBooking).map(copyBooking),
    };
  } catch {
    return { ok: true, bookings: [] };
  }
}

function createBookingRecord(user, form, service, createdAt, createId) {
  return {
    id: String(createId()),
    accountId: user.id,
    createdAt: createdAt.toISOString(),
    name: form.name.trim(),
    email: form.email.trim(),
    phone: form.phone.trim(),
    service: {
      slug: service.slug,
      nameId: service.nameId,
      nameEn: service.nameEn,
      duration: service.duration,
      price: service.price,
    },
    date: form.date,
    time: form.time,
    guests: form.guests,
    notes: form.notes.trim(),
  };
}

function copyBooking(booking) {
  return {
    ...booking,
    service: booking?.service && typeof booking.service === 'object'
      ? { ...booking.service }
      : booking?.service,
  };
}

function isValidUser(user) {
  return Boolean(user && typeof user.id === 'string' && user.id.trim());
}

function isValidBooking(form, service) {
  return Boolean(
    form
      && typeof form.name === 'string'
      && form.name.trim()
      && typeof form.email === 'string'
      && typeof form.phone === 'string'
      && isValidPhoneNumber(form.phone)
      && typeof form.date === 'string'
      && isIsoDate(form.date)
      && typeof form.time === 'string'
      && timeSlots.includes(form.time)
      && Number.isInteger(form.guests)
      && form.guests >= 1
      && form.guests <= 4
      && typeof form.notes === 'string'
      && isValidService(service),
  );
}

function isStoredBooking(booking) {
  return Boolean(
    booking
      && typeof booking.id === 'string'
      && typeof booking.accountId === 'string'
      && isValidBooking(booking, booking.service)
      && toValidDate(booking.createdAt),
  );
}

function isValidService(service) {
  return Boolean(
    service
      && typeof service.slug === 'string'
      && service.slug.trim()
      && typeof service.nameId === 'string'
      && service.nameId.trim()
      && typeof service.nameEn === 'string'
      && service.nameEn.trim()
      && Number.isFinite(service.duration)
      && service.duration > 0
      && Number.isFinite(service.price)
      && service.price >= 0,
  );
}

function isIsoDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) return false;

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(Date.UTC(year, month - 1, day));
  return year > 0
    && date.getUTCFullYear() === year
    && date.getUTCMonth() === month - 1
    && date.getUTCDate() === day;
}

function appointmentDate(date, time) {
  if (!isIsoDate(date) || !timeSlots.includes(time)) return null;
  const [year, month, day] = date.split('-').map(Number);
  const [hours, minutes] = time.split(':').map(Number);
  return new Date(year, month - 1, day, hours, minutes);
}

function toValidDate(value) {
  const date = value instanceof Date ? new Date(value.getTime()) : new Date(value);
  return Number.isNaN(date.getTime()) ? new Date() : date;
}

function createBookingId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `booking-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}
