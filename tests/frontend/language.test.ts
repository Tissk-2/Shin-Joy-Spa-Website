import { describe, expect, it } from "vitest";

import translations from "../../resources/js/data/translations";
import { translate } from "../../resources/js/composables/useLanguage";

describe("spa translations", () => {
  it("falls back to English and then the requested key", () => {
    const dictionary = {
      id: {},
      en: { greeting: "Hello, {name}!" },
    };

    expect(translate("id", "greeting", { name: "Ayu" }, dictionary)).toBe(
      "Hello, Ayu!",
    );
    expect(translate("id", "missing", {}, dictionary)).toBe("missing");
  });

  it("keeps the Indonesian and English dictionaries in exact key parity", () => {
    expect(Object.keys(translations.id).sort()).toEqual(
      Object.keys(translations.en).sort(),
    );
  });
});
