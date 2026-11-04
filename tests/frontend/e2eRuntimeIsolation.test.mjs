import assert from "node:assert/strict";
import { existsSync, mkdirSync, writeFileSync } from "node:fs";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import {
  developerViteHotFile,
  projectRoot,
  removeTestOwnedPath,
  testingEnvironment,
  viteHotFile,
} from "../e2e/testing-environment.ts";

const testingRoot = path.join(projectRoot, "storage/framework/testing");

test("the e2e runner uses a dedicated hot file under the testing directory", () => {
  assert.notEqual(viteHotFile, developerViteHotFile);

  const relative = path.relative(testingRoot, path.resolve(viteHotFile));
  assert.ok(
    relative !== "" && !relative.startsWith("..") && !path.isAbsolute(relative),
    `viteHotFile must be inside ${testingRoot}: ${viteHotFile}`,
  );
  assert.equal(path.basename(viteHotFile), "playwright-vite.hot");
  assert.equal(testingEnvironment.LARAVEL_VITE_HOT_FILE, viteHotFile);
});

test("removeTestOwnedPath refuses paths the e2e runner does not own", () => {
  const refused = [
    developerViteHotFile,
    projectRoot,
    testingRoot,
    path.join(testingRoot, "..", "outside-testing-root.txt"),
    path.join(path.dirname(projectRoot), "outside-project.txt"),
  ];

  for (const target of refused) {
    assert.throws(
      () => removeTestOwnedPath(target),
      /Refusing to delete a path that is not test-owned/,
      `Expected removeTestOwnedPath to refuse ${target}`,
    );
  }
});

test("removeTestOwnedPath deletes test-owned files and tolerates missing ones", () => {
  mkdirSync(testingRoot, { recursive: true });
  const temporary = path.join(
    testingRoot,
    `a5b-runtime-isolation-${process.pid}.tmp`,
  );

  writeFileSync(temporary, "temporary");

  removeTestOwnedPath(temporary);
  assert.equal(existsSync(temporary), false);

  assert.doesNotThrow(() => removeTestOwnedPath(temporary));
});

test("e2e setup and teardown never delete public/hot or call rmSync directly", async () => {
  const [setupSource, teardownSource, viteSource] = await Promise.all([
    readFile("tests/e2e/global-setup.ts", "utf8"),
    readFile("tests/e2e/global-teardown.ts", "utf8"),
    readFile("vite.config.ts", "utf8"),
  ]);

  for (const source of [setupSource, teardownSource]) {
    // Token-level, so an aliased import (`rmSync as rm`) or a split
    // `path.join(..., "public", "hot")` is caught too.
    assert.doesNotMatch(source, /\b(rmSync|unlinkSync|rmdirSync)\b/);
    assert.doesNotMatch(source, /public\/hot|["']public["']/);
  }

  assert.match(viteSource, /hotFile:\s*process\.env\.LARAVEL_VITE_HOT_FILE/);
});
