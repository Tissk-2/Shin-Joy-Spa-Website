import type {
  AdminBookingSummaryData,
  BookingAppointmentData,
  Locale,
} from "@/types";

/** Display helpers shared by the admin booking pages. */

const idrFormatter = new Intl.NumberFormat("id-ID");
const dayFormatters = new Map<string, Intl.DateTimeFormat>();
const relativeFormatters = new Map<Locale, Intl.RelativeTimeFormat>();

function intlLocale(language: Locale): string {
  return language === "id" ? "id-ID" : "en-GB";
}

type DayStyle = "long" | "medium" | "short" | "axis";

function dayFormatter(language: Locale, style: DayStyle): Intl.DateTimeFormat {
  const key = `${language}:${style}`;
  let formatter = dayFormatters.get(key);
  if (!formatter) {
    const options: Intl.DateTimeFormatOptions =
      style === "long"
        ? { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }
        : style === "medium"
          ? { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" }
          : style === "axis"
            ? { day: "numeric", month: "short", timeZone: "UTC" }
            : {
                weekday: "short",
                day: "numeric",
                month: "short",
                timeZone: "UTC",
              };
    formatter = new Intl.DateTimeFormat(intlLocale(language), options);
    dayFormatters.set(key, formatter);
  }
  return formatter;
}

function relativeFormatter(language: Locale): Intl.RelativeTimeFormat {
  let formatter = relativeFormatters.get(language);
  if (!formatter) {
    formatter = new Intl.RelativeTimeFormat(intlLocale(language), {
      numeric: "auto",
    });
    relativeFormatters.set(language, formatter);
  }
  return formatter;
}

export function timezoneLabel(timezone: string): string {
  return timezone === "Asia/Jakarta" ? "WIB" : timezone;
}

/** A stored calendar day ("2026-11-18"), never shifted by the viewer's timezone. */
export function formatDay(
  date: string,
  language: Locale,
  style: DayStyle = "long",
): string {
  const [year, month, day] = date.split("-").map(Number);
  return dayFormatter(language, style).format(
    new Date(Date.UTC(year, month - 1, day)),
  );
}

export function formatAppointment(
  appointment: BookingAppointmentData,
  language: Locale,
): string {
  return `${formatDay(appointment.date, language)}, ${appointment.time} ${timezoneLabel(appointment.timezone)}`;
}

export function formatPrice(amount: number): string {
  return `Rp ${idrFormatter.format(amount)}`;
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
  const format = relativeFormatter(language);
  if (minutes < 60) return format.format(-minutes, "minute");
  if (minutes < 60 * 24)
    return format.format(-Math.floor(minutes / 60), "hour");
  return format.format(-Math.floor(minutes / (60 * 24)), "day");
}
