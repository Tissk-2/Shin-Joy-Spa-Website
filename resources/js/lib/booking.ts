import type { PackageData } from "@/types";
import { isValidPhoneNumber } from "./phone";
import { normalizeSelection, type ServiceSelection } from "./serviceSelection";

export interface BookingFormInput extends ServiceSelection {
  name: string;
  phone: string;
  date: string;
  time: string;
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
  packages: readonly PackageData[],
  today = todayIso(),
): BookingErrors {
  const errors: BookingErrors = {};
  if (!form.name.trim()) errors.name = "b.err.name";
  if (!isValidPhoneNumber(form.phone)) errors.phone = "b.err.phone";

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

  if (!form.date || form.date < today) errors.date = "b.err.date";
  if (!timeSlots.includes(form.time)) errors.time = "b.err.time";
  return errors;
}
