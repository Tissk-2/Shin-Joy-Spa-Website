import assert from "node:assert/strict";
import {
  mkdirSync,
  mkdtempSync,
  rmSync,
  utimesSync,
  writeFileSync,
} from "node:fs";
import { readFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { assertBuildIsFresh } from "../e2e/build-freshness.ts";

const older = new Date("2026-01-01T00:00:00Z");
const built = new Date("2026-01-02T00:00:00Z");

function write(root, relative, mtime) {
  const target = path.join(root, relative);

  mkdirSync(path.dirname(target), { recursive: true });
  writeFileSync(target, `${relative}\n`);
  utimesSync(target, mtime, mtime);
}

function withFixture(run) {
  const root = mkdtempSync(path.join(os.tmpdir(), "e2e-build-"));

  try {
    run(root);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}

test("a manifest newer than every input passes", () => {
  withFixture((root) => {
    write(root, "public/build/manifest.json", built);

    for (const input of [
      "resources/js/app.ts",
      "resources/js/pages/a.vue",
      "resources/css/app.css",
      "resources/views/welcome.blade.php",
      "vite.config.ts",
      "tailwind.config.js",
      "tsconfig.json",
      "package-lock.json",
    ]) {
      write(root, input, older);
    }

    assert.doesNotThrow(() => assertBuildIsFresh(root));
  });
});

test("a missing manifest asks for a build", () => {
  withFixture((root) => {
    assert.throws(() => assertBuildIsFresh(root), {
      message:
        "E2E needs built assets: public/build/manifest.json is missing. Run `npm run build` before `npm run test:e2e`.",
    });
  });
});

test("a newer input is named in the stale message", () => {
  withFixture((root) => {
    write(root, "public/build/manifest.json", built);
    write(root, "resources/js/app.ts", older);
    write(root, "resources/js/pages/a.vue", new Date("2026-01-03T00:00:00Z"));

    assert.throws(() => assertBuildIsFresh(root), {
      message:
        "E2E assets are stale: resources/js/pages/a.vue changed after public/build/manifest.json was built. Run `npm run build` before `npm run test:e2e`.",
    });
  });
});

test("a newer single-file input such as vite.config.ts is stale", () => {
  withFixture((root) => {
    write(root, "public/build/manifest.json", built);
    write(root, "resources/js/app.ts", older);
    write(root, "vite.config.ts", new Date("2026-01-03T00:00:00Z"));

    assert.throws(() => assertBuildIsFresh(root), {
      message:
        "E2E assets are stale: vite.config.ts changed after public/build/manifest.json was built. Run `npm run build` before `npm run test:e2e`.",
    });
  });
});

test("several newer inputs name the newest file and count the rest", () => {
  withFixture((root) => {
    write(root, "public/build/manifest.json", built);
    write(root, "resources/js/app.ts", new Date("2026-01-03T00:00:00Z"));
    write(root, "resources/css/app.css", new Date("2026-01-04T00:00:00Z"));
    write(
      root,
      "resources/views/welcome.blade.php",
      new Date("2026-01-05T00:00:00Z"),
    );

    assert.throws(() => assertBuildIsFresh(root), {
      message:
        "E2E assets are stale: resources/views/welcome.blade.php changed after public/build/manifest.json was built (and 2 more). Run `npm run build` before `npm run test:e2e`.",
    });
  });
});

test("missing optional inputs are skipped", () => {
  withFixture((root) => {
    write(root, "public/build/manifest.json", built);
    write(root, "vite.config.ts", older);

    assert.doesNotThrow(() => assertBuildIsFresh(root));
  });
});

test("newer files outside the inputs are ignored", () => {
  withFixture((root) => {
    write(root, "public/build/manifest.json", built);
    write(root, "resources/js/app.ts", older);
    write(root, "README.md", new Date("2026-01-05T00:00:00Z"));

    assert.doesNotThrow(() => assertBuildIsFresh(root));
  });
});

test("global setup checks freshness before deleting or migrating", async () => {
  const source = await readFile(
    new URL("../e2e/global-setup.ts", import.meta.url),
    "utf8",
  );
  const importIndex = source.indexOf(
    'import { assertBuildIsFresh } from "./build-freshness";',
  );
  const callIndex = source.indexOf("assertBuildIsFresh(projectRoot)");
  const removeIndex = source.indexOf("removeTestOwnedPath(");
  const migrateIndex = source.indexOf("migrate:fresh");

  assert.ok(importIndex >= 0, "global setup must import assertBuildIsFresh");
  assert.ok(callIndex > importIndex, "global setup must call it after import");
  assert.ok(removeIndex >= 0, "global setup must delete test-owned paths");
  assert.ok(migrateIndex >= 0, "global setup must migrate");
  assert.ok(
    callIndex < removeIndex,
    "the freshness check must run before the first deletion",
  );
  assert.ok(
    callIndex < migrateIndex,
    "the freshness check must run before migrate:fresh",
  );
});
