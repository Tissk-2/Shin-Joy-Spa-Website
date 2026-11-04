import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("compact controls keep the reference visual sizes and 44px hit areas", async () => {
  const css = await readFile("src/css/legacy.css", "utf8");
  const languageRule =
    css.match(/\.lang-toggle button, \.lang-toggle a \{([^}]*)\}/)?.[1] ?? "";
  const pillRule = css.match(/\.pill \{([^}]*)\}/)?.[1] ?? "";

  assert.match(languageRule, /padding:\s*0\.3em 0\.7em/);
  assert.doesNotMatch(languageRule, /min-(width|height)/);
  assert.match(pillRule, /padding:\s*0\.55em 1\.05em/);
  assert.doesNotMatch(pillRule, /min-(width|height)/);
  assert.match(
    css,
    /\.lang-toggle (button|a)::before[\s\S]*width:\s*44px[\s\S]*height:\s*44px/,
  );
  assert.match(css, /\.pill::before[\s\S]*width:\s*44px[\s\S]*height:\s*44px/);
});
