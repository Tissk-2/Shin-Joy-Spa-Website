import { isValidPhoneNumber } from './phone.js';

export const timeSlots = Array.from({ length: 23 }, (_, index) => {
  const minutes = 9 * 60 + index * 30;
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
});

export function todayIso(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export function validateBooking(form, services, today = todayIso()) {
  const errors = {};
  if (!form.name.trim()) errors.name = 'b.err.name';
  if (!isValidPhoneNumber(form.phone)) errors.phone = 'b.err.phone';
  if (!services.some(({ slug }) => slug === form.service)) errors.service = 'b.err.service';
  if (!form.date || form.date < today) errors.date = 'b.err.date';
  if (!timeSlots.includes(form.time)) errors.time = 'b.err.time';
  return errors;
}
