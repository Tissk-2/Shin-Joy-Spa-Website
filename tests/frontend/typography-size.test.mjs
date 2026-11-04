import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("compact controls keep the reference visual sizes and 44px hit areas", async () => {
  const css = await readFile(
    new URL("../../resources/css/legacy.css", import.meta.url),
    "utf8",
  );
  const languageRule =
    css.match(/\.lang-toggle button,\s*\.lang-toggle a\s*\{([^}]*)\}/)?.[1] ??
    "";
  const pillRule = css.match(/\.pill \{([^}]*)\}/)?.[1] ?? "";

  assert.match(languageRule, /padding:\s*0\.3em 0\.7em/);
  assert.doesNotMatch(languageRule, /min-(width|height)/);
  assert.match(pillRule, /padding:\s*0\.55em 1\.05em/);
  assert.doesNotMatch(pillRule, /min-(width|height)/);
  const languageHitArea =
    css.match(
      /\.lang-toggle button::before,\s*\.lang-toggle a::before\s*\{([^}]*)\}/,
    )?.[1] ?? "";
  const pillHitArea = css.match(/\.pill::before\s*\{([^}]*)\}/)?.[1] ?? "";

  for (const rule of [languageHitArea, pillHitArea]) {
    assert.match(rule, /content:\s*""/);
    assert.match(rule, /position:\s*absolute/);
    assert.match(rule, /width:\s*44px/);
    assert.match(rule, /height:\s*44px/);
  }
});
