import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it, vi } from "vitest";
import { build } from "vite";

import vitestConfig from "../../vitest.config";

import {
  accountInitial,
  initialHeaderMenuState,
  transitionHeaderMenuState,
} from "../../resources/js/lib/headerMenuState";

describe("header menu state", () => {
  it("returns frozen state without mutating the input", () => {
    const input = { mobileMenuOpen: true, accountMenuOpen: true };
    const restoreFocus = vi.fn();

    const next = transitionHeaderMenuState(
      input,
      { type: "escape" },
      restoreFocus,
    );

    expect(input).toEqual({ mobileMenuOpen: true, accountMenuOpen: true });
    expect(next).toEqual({ mobileMenuOpen: true, accountMenuOpen: false });
    expect(Object.isFrozen(next)).toBe(true);
    expect(restoreFocus).toHaveBeenCalledOnce();
  });

  it("closes both menus after navigation without reusing the initial object", () => {
    const next = transitionHeaderMenuState(
      { mobileMenuOpen: true, accountMenuOpen: true },
      { type: "route-change" },
    );

    expect(next).toEqual({ mobileMenuOpen: false, accountMenuOpen: false });
    expect(next).not.toBe(initialHeaderMenuState);
  });

  it("uses a fallback for missing and blank account names", () => {
    expect(accountInitial(null)).toBe("?");
    expect(accountInitial(undefined)).toBe("?");
    expect(accountInitial("")).toBe("?");
    expect(accountInitial("   ")).toBe("?");
  });

  it.each([
    ["  ayu ", "A"],
    ["élodie", "É"],
    ["🪷 Spa", "🪷"],
  ])("derives a safe account initial from %j", (name, expected) => {
    expect(accountInitial(name)).toBe(expected);
  });
});

describe("Inertia runtime boundary", () => {
  it("uses Inertia instead of Vue Router and browser auth or locale storage", () => {
    const root = resolve(import.meta.dirname, "../../resources/js");
    const layout = readFileSync(
      resolve(root, "layouts/MainLayout.vue"),
      "utf8",
    );
    const language = readFileSync(
      resolve(root, "composables/useLanguage.ts"),
      "utf8",
    );

    expect(`${layout}\n${language}`).not.toMatch(/from\s+['"]vue-router['"]/);
    expect(`${layout}\n${language}`).not.toMatch(/localStorage/);
    expect(layout).toMatch(/from\s+['"]@inertiajs\/vue3['"]/);
    expect(layout).toMatch(/router\.post\(\s*['"]\/logout['"]/);
  });

  it("discovers the committed lowercase TypeScript test tree", () => {
    const config = vitestConfig as { test?: { include?: string[] } };

    expect(config.test?.include).toContain(
      "tests/frontend/**/*.test.{ts,tsx}",
    );
  });

  it("emits Tailwind before the public shell and admin overrides", async () => {
    const result = await build({
      configFile: resolve(import.meta.dirname, "../../vite.config.ts"),
      build: { write: false },
    });
    const outputs = (Array.isArray(result) ? result : [result]).flatMap(
      (output) => output.output,
    );
    const cssAsset = outputs.find(
      (output) => output.type === "asset" && output.fileName.endsWith(".css"),
    );

    expect(cssAsset?.type).toBe("asset");
    if (!cssAsset || cssAsset.type !== "asset") return;

    const css = String(cssAsset.source);
    const tailwindBody = css.indexOf("body{line-height:inherit;margin:0}");
    const publicBody = css.indexOf("body{font-family:var(--font-body)");
    const adminRules = css.indexOf(".admin-sidebar");

    expect(tailwindBody).toBeGreaterThanOrEqual(0);
    expect(publicBody).toBeGreaterThan(tailwindBody);
    expect(adminRules).toBeGreaterThan(publicBody);
  }, 30_000);
});
