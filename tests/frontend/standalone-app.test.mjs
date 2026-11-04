import assert from "node:assert/strict";
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
