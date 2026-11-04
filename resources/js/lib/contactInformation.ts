import { translate } from "@/composables/useLanguage";
import type { Locale } from "@/types";

export type ContactContent = Readonly<
  Record<string, Readonly<Partial<Record<Locale, string>>>>
>;

export interface ContactInformation {
  address: string;
  hours: string;
  phone: string;
  whatsappUrl: string | null;
  mapEmbedUrl: string;
  directionsUrl: string;
}

function localizedValue(
  content: ContactContent,
  locale: Locale,
  key: string,
): string {
  const override = content[key]?.[locale]?.trim();
  return override || translate(locale, key);
}

export function normalizeContactPhone(value: string): string | null {
  const input = value.trim();
  const plusCount = [...input].filter((character) => character === "+").length;
  if (
    !input ||
    !/^[+\d().\s-]+$/.test(input) ||
    (plusCount > 0 && (!input.startsWith("+") || plusCount !== 1))
  ) {
    return null;
  }

  let digits = input.replace(/\D/g, "");
  const hasPlusPrefix = input.startsWith("+");
  const has00Prefix = !hasPlusPrefix && digits.startsWith("00");

  if (hasPlusPrefix) {
    if (digits.startsWith("0")) return null;
  } else if (has00Prefix) {
    digits = digits.slice(2);
    if (digits.startsWith("0")) return null;
  } else if (digits.startsWith("0")) {
    digits = `62${digits.slice(1)}`;
  } else if (!digits.startsWith("62")) {
    return null;
  }

  // The domestic trunk zero cannot follow Indonesia's country code.
  if (digits.startsWith("620")) return null;

  if (digits.length < 8 || digits.length > 15) return null;
  if (digits.startsWith("62") && digits.length < 11) return null;

  return digits;
}

function mapUrl(address: string, embed: boolean): string {
  const url = new URL("https://www.google.com/maps");
  url.searchParams.set("q", address);
  if (embed) url.searchParams.set("output", "embed");
  return url.toString();
}

export function resolveContactInformation(
  locale: Locale,
  content: ContactContent = {},
): ContactInformation {
  const address = localizedValue(content, locale, "loc.address");
  const phone = localizedValue(content, locale, "loc.phone");
  const normalizedPhone = normalizeContactPhone(phone);

  return {
    address,
    hours: localizedValue(content, locale, "loc.hours"),
    phone,
    whatsappUrl: normalizedPhone
      ? `https://wa.me/${normalizedPhone}`
      : null,
    mapEmbedUrl: mapUrl(address, true),
    directionsUrl: mapUrl(address, false),
  };
}
