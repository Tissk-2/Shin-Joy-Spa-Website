import { mount } from "@vue/test-utils";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it, vi } from "vitest";

import Home from "@/pages/Home.vue";

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  const page = reactive({
    url: "/",
    props: {
      auth: { user: null, canAccessAdmin: false },
      locale: "id",
      flash: {},
      catalog: { packages: [] },
      content: {},
    },
  });

  return {
    Head: "span",
    Link: "a",
    router: { post: vi.fn() },
    usePage: () => page,
    useForm: (data: Record<string, unknown>) =>
      reactive({ ...data, processing: false, errors: {} }),
  };
});

const publicRoot = resolve(import.meta.dirname, "../../public");

const imageUrls = (srcset: string) =>
  srcset.split(",").map((candidate) => candidate.trim().split(" ")[0]);

describe("home page images", () => {
  it("offers the hero exactly as the HTML preloads it, so it downloads once", () => {
    const wrapper = mount(Home, { props: { categories: [] } });
    const hero = wrapper.get(".hero__media img");
    const preload = readFileSync(
      resolve(import.meta.dirname, "../../resources/views/app.blade.php"),
      "utf8",
    ).match(/<link rel="preload" as="image"[^>]*>/)?.[0];

    expect(preload).toBeDefined();
    expect(hero.attributes("src")).toBe("/img/hero.webp");
    expect(preload).toContain(`href="${hero.attributes("src")}"`);
    expect(preload).toContain(`imagesrcset="${hero.attributes("srcset")}"`);
    expect(preload).toContain(`imagesizes="${hero.attributes("sizes")}"`);
    expect(imageUrls(hero.attributes("srcset")!)).toEqual([
      "/img/hero-1600.webp",
      "/img/hero.webp",
    ]);
  });

  it("refers only to image files that exist", () => {
    const wrapper = mount(Home, { props: { categories: [] } });
    const urls = wrapper
      .findAll("img")
      .flatMap((img) =>
        imageUrls(img.attributes("srcset") ?? img.attributes("src")!),
      );

    expect(urls).toContain("/img/hero.webp");

    for (const url of urls) {
      expect(existsSync(resolve(publicRoot, `.${url}`)), url).toBe(true);
    }
  });

  it("lazy-loads the muted spa tour loop with an existing poster, video file, and pause/play toggle", async () => {
    const wrapper = mount(Home, { props: { categories: [] } });
    const video = wrapper.get("video.home-about__video");
    const toggle = wrapper.get("[data-tour-toggle]");

    const videoEl = video.element as HTMLVideoElement;
    expect(video.attributes("poster")).toBe("/img/shin-joy-tour-poster.webp");
    expect(video.attributes("preload")).toBe("none");
    expect(videoEl.muted).toBe(true);
    expect(videoEl.loop).toBe(true);
    expect(video.attributes("playsinline")).toBeDefined();
    expect(video.attributes("src")).toBeUndefined();
    expect(
      existsSync(resolve(publicRoot, "./img/shin-joy-tour-poster.webp")),
    ).toBe(true);
    expect(existsSync(resolve(publicRoot, "./img/shin-joy-tour.mp4"))).toBe(
      true,
    );

    const play = vi.fn().mockResolvedValue(undefined);
    const load = vi.fn();
    Object.defineProperty(video.element, "play", { value: play });
    Object.defineProperty(video.element, "load", { value: load });

    await toggle.trigger("click");
    expect(video.attributes("src")).toBe("/img/shin-joy-tour.mp4");
    expect(load).toHaveBeenCalledTimes(1);
    expect(play).toHaveBeenCalledTimes(1);
  });
});
