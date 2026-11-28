import { describe, expect, it } from "vitest";

import translations from "../../resources/js/data/translations";
import { translate } from "../../resources/js/composables/useLanguage";

describe("official menu translations", () => {
  it("falls back to English and then the requested key", () => {
    expect(
      translate(
        "id",
        "greeting",
        { name: "Ayu" },
        { id: {}, en: { greeting: "Hello, {name}!" } },
      ),
    ).toBe("Hello, Ayu!");
  });

  it("picks the singular or plural form from pipe-separated English strings", () => {
    const dictionary = {
      id: {
        guests: "{n} tamu",
        unit: "tamu",
      },
      en: {
        guests: "{n} guest|{n} guests",
        extensions: "{count} extension|{count} extensions",
        unit: "guest|guests",
        ratio: "{guests}/{capacity} guest|{guests}/{capacity} guests",
      },
    };

    expect(translate("en", "guests", { n: 1 }, dictionary)).toBe("1 guest");
    expect(translate("en", "guests", { n: 2 }, dictionary)).toBe("2 guests");
    expect(translate("en", "guests", { n: 0 }, dictionary)).toBe("0 guests");
    expect(translate("en", "extensions", { count: 1 }, dictionary)).toBe(
      "1 extension",
    );
    expect(translate("en", "extensions", { count: 3 }, dictionary)).toBe(
      "3 extensions",
    );
    expect(translate("en", "unit", { count: 1 }, dictionary)).toBe("guest");
    expect(translate("en", "unit", {}, dictionary)).toBe("guests");
    expect(
      translate(
        "en",
        "ratio",
        { guests: 1, capacity: 1, count: 1 },
        dictionary,
      ),
    ).toBe("1/1 guest");
    expect(
      translate(
        "en",
        "ratio",
        { guests: 1, capacity: 2, count: 2 },
        dictionary,
      ),
    ).toBe("1/2 guests");
    expect(translate("id", "guests", { n: 1 }, dictionary)).toBe("1 tamu");
  });

  it("keeps paired official-menu copy in both dictionaries", () => {
    const keys = [
      "menu.title",
      "menu.sub",
      "menu.hotWaterPolicy",
      "menu.regular",
      "menu.doubleTherapist",
      "menu.duration",
      "menu.extension",
      "menu.total",
      "menu.comparison.title",
      "menu.comparison.standard",
      "menu.comparison.vip",
      "menu.unavailable",
      "menu.bookingReview",
      "menu.unavailableSelection",
    ];
    for (const key of keys) {
      expect(translations.id[key]).toEqual(expect.any(String));
      expect(translations.en[key]).toEqual(expect.any(String));
    }
  });

  it("keeps package names out of active translation values", () => {
    for (const dictionary of [translations.id, translations.en]) {
      for (const value of Object.values(dictionary)) {
        expect(value).not.toMatch(/\bNezuko(?: VIP)?\b/i);
      }
    }
  });
});
