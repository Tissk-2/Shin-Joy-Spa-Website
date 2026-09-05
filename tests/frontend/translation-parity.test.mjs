import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const source = readFileSync(
  new URL("../../resources/js/data/translations.ts", import.meta.url),
  "utf8",
);
const translations = new Function(
  source.replace(/^export default\s+/, "return "),
)();

function recursiveKeys(value, prefix = "") {
  return Object.entries(value)
    .flatMap(([key, child]) => {
      const path = prefix ? `${prefix}.${key}` : key;
      return child && typeof child === "object" && !Array.isArray(child)
        ? recursiveKeys(child, path)
        : [path];
    })
    .sort();
}

test("active Indonesian and English UI dictionaries have recursive key parity", () => {
  assert.deepEqual(
    recursiveKeys(translations.id),
    recursiveKeys(translations.en),
  );
});
