import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import { readFile } from "node:fs/promises";
import test from "node:test";

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
