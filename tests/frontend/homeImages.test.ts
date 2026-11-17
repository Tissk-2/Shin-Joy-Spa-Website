import { mount } from "@vue/test-utils";
import { existsSync } from "node:fs";
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

describe("home page images", () => {
  it("shows the WebP hero, the one the HTML preloads", () => {
    const wrapper = mount(Home, { props: { categories: [] } });

    expect(wrapper.get(".hero__media img").attributes("src")).toBe(
      "/img/hero.webp",
    );
    expect(wrapper.get("#about img").attributes("src")).toBe("/img/about.webp");
  });

  it("uses thumbnails in the gallery grid and the full photo in the lightbox", async () => {
    const wrapper = mount(Home, { props: { categories: [] } });
    const items = wrapper.findAll(".gallery__item");

    expect(items).toHaveLength(galleryImgs.length);
    items.forEach((item, index) => {
      expect(item.get("img").attributes("src")).toBe(galleryImgs[index].thumb);
      expect(item.attributes("href")).toBe(galleryImgs[index].src);
    });

    await items[2].trigger("click");
    expect(wrapper.get(".lightbox__img").attributes("src")).toBe(
      galleryImgs[2].src,
    );
  });

  it("refers only to image files that exist", () => {
    const urls = [
      "/img/hero.webp",
      "/img/about.webp",
      ...galleryImgs.flatMap((image) => [image.src, image.thumb]),
    ];

    for (const url of urls) {
      expect(existsSync(resolve(publicRoot, `.${url}`)), url).toBe(true);
    }
  });
});
