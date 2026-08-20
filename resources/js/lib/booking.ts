import { isValidPhoneNumber } from "./phone";

export interface BookingFormInput {
  name: string;
  phone: string;
  service: string;
  date: string;
  time: string;
}

export interface ServiceReference {
  slug: string;
}

export type BookingErrors = Partial<Record<keyof BookingFormInput, string>>;

export const timeSlots = Object.freeze(
  Array.from({ length: 23 }, (_, index) => {
    const minutes = 9 * 60 + index * 30;

    return `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
  }),
);

export function todayIso(date = new Date()): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

export function validateBooking(
  form: BookingFormInput,
  services: readonly ServiceReference[],
  today = todayIso(),
): BookingErrors {
  const errors: BookingErrors = {};

  if (!form.name.trim()) errors.name = "b.err.name";
  if (!isValidPhoneNumber(form.phone)) errors.phone = "b.err.phone";
  if (!services.some(({ slug }) => slug === form.service))
    errors.service = "b.err.service";
  if (!form.date || form.date < today) errors.date = "b.err.date";
  if (!timeSlots.includes(form.time)) errors.time = "b.err.time";

  return errors;
}
