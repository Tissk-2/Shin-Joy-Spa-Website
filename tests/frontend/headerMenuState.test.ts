import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it, vi } from "vitest";

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

  it.each([
    [null, "?"],
    [undefined, "?"],
    ["", "?"],
    ["   ", "?"],
    ["  ayu ", "A"],
    ["élodie", "É"],
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
});
