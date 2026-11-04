import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Services from "@/pages/Services.vue";
import PackageMenuCard from "@/components/PackageMenuCard.vue";
import faq from "@/data/faq";
import translations from "@/data/translations";
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

  it("keeps browsing cards focused on one detail action without booking controls", () => {
    const wrapper = mount(PackageMenuCard, {
      props: { servicePackage: packageFixture },
    });

    expect(wrapper.findAll("a")).toHaveLength(1);
    expect(wrapper.get("a").attributes("href")).toBe("/booking?service=balinese-60");
    expect(wrapper.find('[role="radiogroup"]').exists()).toBe(false);
    expect(wrapper.find("button").exists()).toBe(false);
    expect(wrapper.get("[data-starting-price]").text()).toContain("199.000");
    expect(wrapper.get("table").text()).toContain("60 min");
    expect(wrapper.get("table").text()).toContain("Rp 199.000");
    expect(wrapper.findAll("details")).toHaveLength(0);
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
    expect(wrapper.get('a[href="/booking?service=balinese-60"]')).toBeTruthy();
    expect(wrapper.get('a[href="/booking?service=body-scrub"]')).toBeTruthy();
    expect(wrapper.findAll("address")).toHaveLength(1);
    expect(
      wrapper.findAll('[role="note"]').filter((note) =>
        /hot[- ]water/i.test(note.text()),
      ),
    ).toHaveLength(1);
  });

  it("uses the documented hot-water policy wording in both catalog locales", () => {
    expect(translations.id["menu.hotWaterPolicy"]).toBe(faq[0].aId);
    expect(translations.en["menu.hotWaterPolicy"]).toBe(faq[0].aEn);
  });
});
