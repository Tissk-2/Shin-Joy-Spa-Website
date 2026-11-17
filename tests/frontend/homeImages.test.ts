import { mount } from "@vue/test-utils";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it, vi } from "vitest";

import Home from "@/pages/Home.vue";
import galleryImgs from "@/data/gallery";

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

  it("lets the browser pick a smaller about photo on smaller screens", () => {
    const about = mount(Home, { props: { categories: [] } }).get("#about img");

    expect(about.attributes("src")).toBe("/img/about.webp");
    // Prettier lays srcset out over several lines; browsers ignore the whitespace.
    expect(about.attributes("srcset")!.replace(/\s+/g, " ").trim()).toBe(
      "/img/about-560.webp 560w, /img/about-720.webp 720w, /img/about.webp 960w",
    );
    expect(about.attributes("sizes")).toBe("(min-width: 1024px) 540px, 92vw");
  });

  it("uses thumbnails in the gallery grid and the full photo in the lightbox", async () => {
    const wrapper = mount(Home, { props: { categories: [] } });
    const items = wrapper.findAll(".gallery__item");

    expect(items).toHaveLength(galleryImgs.length);
    items.forEach((item, index) => {
      const img = item.get("img");
      expect(img.attributes("src")).toBe(galleryImgs[index].thumb);
      expect(img.attributes("srcset")).toBe(
        `${galleryImgs[index].small} 320w, ${galleryImgs[index].thumb} 640w`,
      );
      // Wide tiles span two grid columns.
      expect(img.attributes("sizes")).toBe(
        item.classes("wide")
          ? "(min-width: 1024px) 475px, 92vw"
          : "(min-width: 1024px) 233px, 45vw",
      );
      expect(item.attributes("href")).toBe(galleryImgs[index].src);
    });

    await items[2].trigger("click");
    expect(wrapper.get(".lightbox__img").attributes("src")).toBe(
      galleryImgs[2].src,
    );
  });

  it("refers only to image files that exist", () => {
    const wrapper = mount(Home, { props: { categories: [] } });
    const urls = [
      ...imageUrls(wrapper.get(".hero__media img").attributes("srcset")!),
      ...imageUrls(wrapper.get("#about img").attributes("srcset")!),
      ...galleryImgs.flatMap((image) => [image.src, image.thumb, image.small]),
    ];

    for (const url of urls) {
      expect(existsSync(resolve(publicRoot, `.${url}`)), url).toBe(true);
    }
  });
});
