import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("home hero statistics match the supplied reference", async () => {
  const [home, translations, css] = await Promise.all([
    readFile("src/Pages/Home.vue", "utf8"),
    readFile("src/Data/translations.js", "utf8"),
    readFile("src/css/legacy.css", "utf8"),
  ]);

  assert.match(home, /const statRating = '4\.9'/);
  assert.match(home, /const statGuests = 2000/);
  assert.match(home, /\{\{ statServices \}\}\+/);
  assert.match(home, /\{\{ statRating \}\}/);
  assert.match(home, /\{\{ formattedGuests \}\}\+/);
  assert.match(translations, /'hero\.m2l': 'Rata-rata ulasan'/);
  assert.match(translations, /'hero\.m2l': 'Average rating'/);
  assert.match(
    css,
    /grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\)/,
  );
});
