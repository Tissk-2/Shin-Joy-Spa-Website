import { describe, expect, it } from "vitest";

import translations from "../../resources/js/data/translations";
import { translate } from "../../resources/js/composables/useLanguage";

describe("official menu translations", () => {
  it("falls back to English and then the requested key", () => {
    expect(
      translate("id", "greeting", { name: "Ayu" }, { id: {}, en: { greeting: "Hello, {name}!" } }),
    ).toBe("Hello, Ayu!");
  });

  it("keeps paired official-menu copy in both dictionaries", () => {
    const keys = ["menu.eyebrow", "menu.title", "menu.sub", "menu.hotWaterPolicy", "menu.regular", "menu.doubleTherapist", "menu.duration", "menu.extension", "menu.total", "menu.comparison.title", "menu.comparison.standard", "menu.comparison.vip", "menu.unavailable"];
    for (const key of keys) {
      expect(translations.id[key]).toEqual(expect.any(String));
      expect(translations.en[key]).toEqual(expect.any(String));
    }
  });
});
