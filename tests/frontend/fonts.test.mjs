import assert from "node:assert/strict";
import { readFile, stat } from "node:fs/promises";
import test from "node:test";

test("spa fonts are served locally without a Google Fonts runtime dependency", async () => {
  const [head, css, marcellus, mulish] = await Promise.all([
    readFile("index.html", "utf8"),
    readFile("src/css/app.css", "utf8"),
    stat("public/fonts/marcellus-latin.woff2"),
    stat("public/fonts/mulish-latin.woff2"),
  ]);

  assert.doesNotMatch(head, /fonts\.googleapis\.com/);
  assert.match(css, /url\(['"]?\/fonts\/marcellus-latin\.woff2/);
  assert.match(css, /url\(['"]?\/fonts\/mulish-latin\.woff2/);
  assert.ok(marcellus.size > 10_000);
  assert.ok(mulish.size > 10_000);
});
