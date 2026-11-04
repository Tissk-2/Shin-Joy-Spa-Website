import assert from "node:assert/strict";
import { existsSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { readFile } from "node:fs/promises";
import test from "node:test";

const projectRoot = fileURLToPath(new URL("../..", import.meta.url));

test("repository is a Laravel 13 Inertia Vue application", async () => {
  assert.equal(existsSync("artisan"), true);
  assert.equal(existsSync("bootstrap/app.php"), true);
  assert.equal(existsSync("resources/js/app.ts"), true);

  const composer = JSON.parse(await readFile("composer.json", "utf8"));
  const packageJson = JSON.parse(await readFile("package.json", "utf8"));

  assert.match(composer.require.php, /8\.3/);
  assert.match(composer.require["laravel/framework"], /13/);
  assert.equal(typeof composer.require["inertiajs/inertia-laravel"], "string");
  assert.equal(typeof packageJson.dependencies["@inertiajs/vue3"], "string");
  assert.equal(typeof packageJson.dependencies.vue, "string");
  assert.equal(typeof packageJson.devDependencies.typescript, "string");
});

test("Laravel production build emits an Inertia entry with JavaScript and CSS assets", () => {
  const outputDirectory = mkdtempSync(join(tmpdir(), "shinjoy-build-test-"));

  try {
    const result = spawnSync(
      process.execPath,
      [
        resolve(projectRoot, "node_modules/vite/bin/vite.js"),
        "build",
        "--outDir",
        outputDirectory,
      ],
      { cwd: projectRoot, encoding: "utf8", timeout: 120_000 },
    );

    assert.ifError(result.error);
    assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
    const manifest = JSON.parse(
      readFileSync(join(outputDirectory, "manifest.json"), "utf8"),
    );
    const entry = manifest["resources/js/app.ts"];
    assert.ok(entry, "The manifest must contain the active Inertia entry");
    assert.equal(entry.isEntry, true);
    assert.match(entry.file, /\.js$/);
    assert.ok(
      entry.css?.length > 0,
      "The entry must include the application stylesheet",
    );

    for (const asset of Object.values(manifest)) {
      for (const file of [
        asset.file,
        ...(asset.css ?? []),
        ...(asset.assets ?? []),
      ]) {
        assert.equal(
          existsSync(join(outputDirectory, file)),
          true,
          `Missing built asset: ${file}`,
        );
      }
    }
  } finally {
    rmSync(outputDirectory, { recursive: true, force: true });
  }
});
