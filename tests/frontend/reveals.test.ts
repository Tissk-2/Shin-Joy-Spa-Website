import { readFileSync } from "node:fs";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { initializeReveals } from "@/lib/reveals";

let callback: IntersectionObserverCallback;
const observe = vi.fn();
const unobserve = vi.fn();
const disconnect = vi.fn();

beforeEach(() => {
  document.body.innerHTML = '<section class="reveal">Visible content</section>';
  vi.stubGlobal(
    "matchMedia",
    vi.fn(() => ({ matches: false })),
  );
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      constructor(handler: IntersectionObserverCallback) {
        callback = handler;
      }
      observe = observe;
      unobserve = unobserve;
      disconnect = disconnect;
    },
  );
});

afterEach(() => {
  document.body.innerHTML = "";
  initializeReveals();
  vi.unstubAllGlobals();
  vi.clearAllMocks();
});

describe("progressive reveal motion", () => {
  it("keeps uninitialized content visible and animates only its position", () => {
    const css = readFileSync("resources/css/legacy.css", "utf8");
    const reveals = css.slice(
      css.indexOf("/* ---------- Reveal animations"),
      css.indexOf("/* ---------- Page transitions"),
    );
    expect(reveals).not.toMatch(/opacity:\s*0/);
    expect(reveals).toMatch(
      /\.reveal\s*\{\s*opacity:\s*1;\s*transform:\s*none;/,
    );
    expect(reveals).toContain(".reveal.in");
    expect(css).toMatch(/\.reveal\s*\{[^}]*animation:\s*none\s*!important/);
  });

  it("observes replacement content and disconnects the previous page observer", () => {
    initializeReveals();
    const first = document.querySelector(".reveal")!;
    expect(observe).toHaveBeenCalledWith(first);
    document.body.innerHTML =
      '<section class="reveal">After navigation</section>';
    initializeReveals();
    expect(disconnect).toHaveBeenCalledOnce();
    const replacement = document.querySelector(".reveal")!;
    expect(observe).toHaveBeenLastCalledWith(replacement);
    callback(
      [
        {
          target: replacement,
          isIntersecting: true,
        } as IntersectionObserverEntry,
      ],
      {} as IntersectionObserver,
    );
    expect(replacement.classList.contains("in")).toBe(true);
    expect(unobserve).toHaveBeenCalledWith(replacement);
  });

  it("does not animate for reduced motion or missing observer support", () => {
    vi.stubGlobal(
      "matchMedia",
      vi.fn(() => ({ matches: true })),
    );
    initializeReveals();
    expect(observe).not.toHaveBeenCalled();
    expect(document.querySelector(".reveal.in")).toBeNull();
    vi.stubGlobal(
      "matchMedia",
      vi.fn(() => ({ matches: false })),
    );
    vi.stubGlobal("IntersectionObserver", undefined);
    initializeReveals();
    expect(observe).not.toHaveBeenCalled();
  });
});
