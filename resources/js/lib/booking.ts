import type { PackageData } from "@/types";
import { isValidPhoneNumber } from "./phone";
import {
  normalizeSelection,
  selectionTotal,
  type ServiceSelection,
} from "./serviceSelection";

export interface BookingFormInput extends ServiceSelection {
  name: string;
  phone: string;
  date: string;
  time: string;
  guests: number;
  notes: string;
}

export type BookingErrors = Partial<Record<keyof BookingFormInput, string>>;

export interface BookingSubmissionInput extends BookingFormInput {
  submission_key: string;
  expected_total_price_idr: number;
}

export interface BookingSubmissionPayload {
  name: string;
  phone: string;
  service: string;
  mode: string;
  duration: number | null;
  extension: string;
  date: string;
  time: string;
  guests: number;
  notes: string;
  submission_key: string;
  expected_total_price_idr: number;
}

export const timeSlots = Object.freeze(
  Array.from({ length: 23 }, (_, index) => {
    const minutes = 9 * 60 + index * 30;
    return `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
  }),
);

export function treatmentFitsBusinessHours(
  time: string,
  durationMinutes: number,
): boolean {
  if (
    !timeSlots.includes(time) ||
    !Number.isFinite(durationMinutes) ||
    durationMinutes <= 0
  )
    return false;

  const [hour, minute] = time.split(":").map(Number);
  return hour * 60 + minute + durationMinutes <= 21 * 60;
}

const jakartaFormatter = new Intl.DateTimeFormat("en-GB", {
  timeZone: "Asia/Jakarta",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});

function jakartaParts(date: Date): Record<string, string> {
  return Object.fromEntries(
    jakartaFormatter
      .formatToParts(date)
      .map(({ type, value }) => [type, value]),
  );
}

export function todayIso(date = new Date()): string {
  const { year, month, day } = jakartaParts(date);
  return `${year}-${month}-${day}`;
}

export function jakartaTimeIso(date = new Date()): string {
  const { hour, minute } = jakartaParts(date);
  return `${hour}:${minute}`;
}

function isCalendarDate(value: unknown): value is string {
  if (typeof value !== "string") return false;
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) return false;

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  if (month < 1 || month > 12 || day < 1) return false;

  return day <= new Date(Date.UTC(year, month, 0)).getUTCDate();
}

export function validateBooking(
  form: BookingFormInput,
  packages: readonly PackageData[],
  today = todayIso(),
  currentTime = jakartaTimeIso(),
): BookingErrors {
  const errors: BookingErrors = {};
  if (!form.name.trim()) errors.name = "b.err.name";
  if (form.phone.trim() && !isValidPhoneNumber(form.phone))
    errors.phone = "b.err.phone";

  const servicePackage = packages.find(
    (item) => typeof form.service === "string" && item.slug === form.service,
  );
  if (!servicePackage) errors.service = "b.err.service";
  if (form.mode !== "regular" && form.mode !== "double_therapist")
    errors.mode = "b.err.mode";
  if (!Number.isInteger(form.duration)) errors.duration = "b.err.duration";
  if (typeof form.extension !== "string") errors.extension = "b.err.extension";
  if (
    typeof form.extension === "string" &&
    form.extension !== "" &&
    (!servicePackage ||
      (form.mode !== "regular" && form.mode !== "double_therapist") ||
      !Number.isInteger(form.duration))
  )
    errors.extension = "b.err.extension";
  if (
    servicePackage &&
    (form.mode === "regular" || form.mode === "double_therapist") &&
    Number.isInteger(form.duration) &&
    typeof form.extension === "string"
  ) {
    const normalized = normalizeSelection(servicePackage, form);
    if (normalized.mode !== form.mode) errors.mode = "b.err.mode";
    if (normalized.duration !== form.duration)
      errors.duration = "b.err.duration";
    if (normalized.extension !== form.extension)
      errors.extension = "b.err.extension";
  }

  if (!isCalendarDate(form.date) || form.date < today)
    errors.date = "b.err.date";
  if (!timeSlots.includes(form.time)) errors.time = "b.err.time";
  if (form.date === today && form.time <= currentTime)
    errors.time = "b.err.time";
  if (
    servicePackage &&
    !errors.mode &&
    !errors.duration &&
    !errors.extension &&
    timeSlots.includes(form.time)
  ) {
    if (
      !treatmentFitsBusinessHours(
        form.time,
        selectionTotal(servicePackage, form).duration,
      )
    )
      errors.time = "b.err.time";
  }
  return errors;
}

export function buildBookingSubmissionPayload(
  input: BookingSubmissionInput,
): BookingSubmissionPayload {
  return {
    name: input.name,
    phone: input.phone,
    service: input.service,
    mode: input.mode,
    duration: input.duration,
    extension: input.extension,
    date: input.date,
    time: input.time,
    guests: input.guests,
    notes: input.notes,
    submission_key: input.submission_key,
    expected_total_price_idr: input.expected_total_price_idr,
  };
}
