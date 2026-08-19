import test from 'node:test';
import assert from 'node:assert/strict';
import {
  timeSlots,
  todayIso,
  validateBooking,
} from '../../src/booking.js';

const services = [{ slug: 'javanese-massage' }];

test('booking validation rejects missing and malformed required values', () => {
  const errors = validateBooking({
    name: '',
    phone: '123',
    service: 'missing-service',
    date: '2026-07-27',
    time: '08:00',
  }, services, '2026-07-28');

  assert.deepEqual(Object.keys(errors).sort(), [
    'date',
    'name',
    'phone',
    'service',
    'time',
  ]);
});

test('booking validation accepts a complete future booking', () => {
  assert.deepEqual(validateBooking({
    name: 'Ayu Lestari',
    phone: '+62 812 3456 7890',
    service: 'javanese-massage',
    date: '2026-07-29',
    time: '09:30',
  }, services, '2026-07-28'), {});

  assert.equal(timeSlots[0], '09:00');
  assert.equal(timeSlots.at(-1), '20:00');
  assert.equal(todayIso(new Date(2026, 6, 28)), '2026-07-28');
});

test('booking validation rejects parenthesized phone numbers', () => {
  const errors = validateBooking({
    name: 'Ayu Lestari',
    phone: '+62 (812) 3456 7890',
    service: 'javanese-massage',
    date: '2026-07-29',
    time: '09:30',
  }, services, '2026-07-28');

  assert.equal(errors.phone, 'b.err.phone');
});
