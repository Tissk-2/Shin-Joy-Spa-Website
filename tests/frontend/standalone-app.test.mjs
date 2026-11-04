import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { fileURLToPath, pathToFileURL } from "node:url";
import { resolve } from "node:path";
import test from "node:test";

const projectRoot = fileURLToPath(new URL("../..", import.meta.url));

test("standalone router exposes every spa page and redirects unknown URLs home", async () => {
  const moduleUrl = pathToFileURL(resolve(projectRoot, "src/routes.js")).href;
  let routes;

  try {
    ({ default: routes } = await import(moduleUrl));
  } catch {
    routes = undefined;
  }

  assert.ok(Array.isArray(routes), "src/routes.js must export route records");
  assert.deepEqual(
    routes.map(({ path }) => path),
    [
      "/",
      "/services",
      "/services/:slug",
      "/booking",
      "/about",
      "/login",
      "/register",
      "/profile",
      "/admin-login",
      "/admin",
      "/:pathMatch(.*)*",
    ],
  );
  assert.equal(
    routes.find(({ path }) => path === "/services/:slug").props,
    true,
  );
  assert.equal(routes.at(-1).redirect, "/");
});

test("standalone project builds static assets without Laravel when declared pages exist", () => {
  const deferredPages = [
    "src/Pages/Login.vue",
    "src/Pages/Register.vue",
    "src/Pages/Profile.vue",
  ];
  if (deferredPages.some((page) => !existsSync(resolve(projectRoot, page))))
    return;

  const npm = process.platform === "win32" ? "npm.cmd" : "npm";
  const result = spawnSync(npm, ["run", "build"], {
    cwd: projectRoot,
    encoding: "utf8",
    shell: process.platform === "win32",
  });

  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.equal(existsSync(resolve(projectRoot, "dist/index.html")), true);
});
