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

test("customer booking pages keep paired keys and avoid out-of-scope promises", () => {
  for (const locale of ["id", "en"]) {
    for (const key of [
      "nav.bookings",
      "profile.bookingsAction",
      "bookings.title",
      "bookings.status.pending",
      "bookings.status.confirmed",
      "bookings.status.completed",
      "bookings.status.cancelled",
      "bookings.noExtension",
      "bookings.noNotes",
    ]) {
      assert.equal(typeof translations[locale][key], "string");
      assert.notEqual(translations[locale][key].trim(), "");
    }

    const bookingCopy = Object.entries(translations[locale])
      .filter(([key]) => key.startsWith("bookings."))
      .map(([, value]) => value)
      .join("\n")
      .toLowerCase();

    assert.doesNotMatch(
      bookingCopy,
      /admin|payment|deposit|gateway|cancel request|request cancellation|reschedule|notification|pembayaran|deposit|ajukan pembatalan|minta pembatalan|ubah jadwal|notifikasi/,
    );
  }
});
