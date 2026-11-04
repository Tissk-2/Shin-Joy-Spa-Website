import test from "node:test";
import assert from "node:assert/strict";
import { language, setLanguage, tr } from "../../src/useLanguage.js";

test("language selection translates, interpolates, and ignores unsupported values", () => {
  setLanguage("en");
  assert.equal(language.value, "en");
  assert.equal(tr("nav.home"), "Home");
  assert.equal(tr("sp.results", { n: 3 }), "Showing 3 treatments");
  assert.equal(tr("missing.key"), "missing.key");

  setLanguage("xx");
  assert.equal(language.value, "en");

  setLanguage("id");
});
