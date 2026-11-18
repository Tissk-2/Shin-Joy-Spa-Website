import type {
  AdminBookingSummaryData,
  BookingAppointmentData,
  Locale,
} from "@/types";

/** Display helpers shared by the admin booking pages. */

function intlLocale(language: Locale): string {
  return language === "id" ? "id-ID" : "en-GB";
}

export function timezoneLabel(timezone: string): string {
  return timezone === "Asia/Jakarta" ? "WIB" : timezone;
}

/** A stored calendar day ("2026-11-18"), never shifted by the viewer's timezone. */
export function formatDay(
  date: string,
  language: Locale,
  style: "long" | "short" = "long",
): string {
  const [year, month, day] = date.split("-").map(Number);
  return new Intl.DateTimeFormat(
    intlLocale(language),
    style === "long"
      ? { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }
      : { weekday: "short", day: "numeric", month: "short", timeZone: "UTC" },
  ).format(new Date(Date.UTC(year, month - 1, day)));
}

export function formatAppointment(
  appointment: BookingAppointmentData,
  language: Locale,
): string {
  return `${formatDay(appointment.date, language)}, ${appointment.time} ${timezoneLabel(appointment.timezone)}`;
}

export function formatPrice(amount: number): string {
  return `Rp ${new Intl.NumberFormat("id-ID").format(amount)}`;
}

export function packageName(
  booking: Pick<AdminBookingSummaryData, "package">,
  language: Locale,
): string {
  return language === "id" ? booking.package.nameId : booking.package.nameEn;
}

/**
 * The last six characters tell bookings apart in a list; the full reference
 * stays one click away on the detail page, and search matches any part of it.
 */
export function shortReference(reference: string): string {
  return reference.length > 8 ? `…${reference.slice(-6)}` : reference;
}

/** "3 hours ago" / "3 jam yang lalu", rounded to the largest whole unit. */
export function timeAgo(
  iso: string,
  language: Locale,
  now: number = Date.now(),
): string {
  const minutes = Math.max(0, Math.round((now - Date.parse(iso)) / 60000));
  const format = new Intl.RelativeTimeFormat(intlLocale(language), {
    numeric: "auto",
  });
  if (minutes < 60) return format.format(-minutes, "minute");
  if (minutes < 60 * 24)
    return format.format(-Math.floor(minutes / 60), "hour");
  return format.format(-Math.floor(minutes / (60 * 24)), "day");
}
