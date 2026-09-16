import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Services from "@/pages/Services.vue";
import type { PackageData } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/services",
    props: {
      auth: { user: null, canAccessAdmin: false },
      locale: "en",
      flash: {},
      catalog: { packages: [] },
    },
  },
}));

vi.mock("@inertiajs/vue3", () => ({
  Head: "span",
  Link: "a",
  router: { post: vi.fn() },
  usePage: () => inertia.page,
}));

const packageFixture: PackageData = {
  slug: "balinese-60",
  nameId: "Pijat Bali",
  nameEn: "Balinese Massage",
  descId: "Pijat rileks.",
  descEn: "Relaxing massage.",
  longId: "Pijat rileks.",
  longEn: "Relaxing massage.",
  benefitId: null,
  benefitEn: null,
  image: "/img/massage.jpg",
  gallery: [],
  isVip: false,
  variants: [
    {
      code: "balinese-60-regular-60",
      mode: "regular",
      duration: 60,
      price: 199000,
    },
  ],
  extensions: [],
};

describe("public grouped catalog page", () => {
  beforeEach(() => {
    inertia.page.props.locale = "en";
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("renders category headings and a real package card for each grouped package", () => {
    const wrapper = mount(Services, {
      props: {
        categories: [
          {
            slug: "massage",
            nameId: "Pijat",
            nameEn: "Massage",
            image: "/img/massage.jpg",
            packages: [packageFixture],
          },
          {
            slug: "body-care",
            nameId: "Perawatan Tubuh",
            nameEn: "Body Care",
            image: "/img/body.jpg",
            packages: [{ ...packageFixture, slug: "body-scrub" }],
          },
        ],
      },
    });

    expect(wrapper.get("h1").text()).toBe("All treatments");
    expect(wrapper.findAll("h2").map((heading) => heading.text())).toEqual([
      "Massage",
      "Body Care",
    ]);
    expect(wrapper.findAll(".package-menu-card")).toHaveLength(2);
    expect(wrapper.get('a[href="/services/balinese-60"]')).toBeTruthy();
    expect(wrapper.get('a[href="/services/body-scrub"]')).toBeTruthy();
  });
});
