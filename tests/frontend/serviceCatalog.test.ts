import { mount } from "@vue/test-utils";
import { readFileSync } from "node:fs";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import MainLayout from "@/layouts/MainLayout.vue";
import Home from "@/pages/Home.vue";
import type { PackageData } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/",
    props: { auth: { user: null }, locale: "en", flash: {}, catalog: { packages: [] } },
  },
}));

vi.mock("@inertiajs/vue3", () => ({
  Head: "span", Link: "a", router: { post: vi.fn() }, usePage: () => inertia.page,
}));

const packages: PackageData[] = [
  { slug: "nezuko", nameId: "Nezuko", nameEn: "Nezuko", isVip: false, descId: "Pijat lembut.", descEn: "A gentle treatment.", longId: "", longEn: "", benefitId: "", benefitEn: "", image: "/img/massage.jpg", gallery: [], variants: [{ code: "n60", mode: "regular", duration: 60, price: 199000 }], extensions: [] },
  { slug: "nezuko-vip", nameId: "Nezuko VIP", nameEn: "Nezuko VIP", isVip: true, descId: "Pijat sesuai kebutuhan.", descEn: "A tailored treatment.", longId: "", longEn: "", benefitId: "", benefitEn: "", image: "/img/massage.jpg", gallery: [], variants: [{ code: "v60", mode: "regular", duration: 60, price: 299000 }], extensions: [] },
];

beforeEach(() => {
  inertia.page.url = "/";
  inertia.page.props = { auth: { user: null }, locale: "en", flash: {}, catalog: { packages } };
  Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => vi.restoreAllMocks());

describe("official menu homepage", () => {
  it("keeps package CTAs as full-size anchors, including the mobile action", () => {
    const styles = readFileSync("resources/css/menu.css", "utf8");
    expect(styles).toMatch(/\.package-configurator__cta\s*\{[\s\S]*display:\s*(?:inline-)?flex[\s\S]*min-height:\s*44px/);
    expect(styles).toMatch(/\.package-configurator__mobile-cta\s+\.package-configurator__cta\s*\{[\s\S]*width:\s*100%/);
  });

  it("renders exactly the two official package articles at the canonical menu anchor", async () => {
    const wrapper = mount(Home, { props: { packages } });
    expect(wrapper.get("#menu")).toBeTruthy();
    expect(wrapper.findAll(".package-menu-card")).toHaveLength(2);
    expect(wrapper.text()).toContain("Rp199.000");
    expect(wrapper.find(".package-menu-card__vip").text()).toBe("VIP");
    expect(wrapper.get('a[href="/services/nezuko"]')).toBeTruthy();
    expect(wrapper.get('a[href="/services/nezuko-vip"]')).toBeTruthy();
    expect(wrapper.find(".cat-grid").exists()).toBe(false);
    expect(wrapper.text()).toContain("Jalan Terusan Batubara No. 12, Lantai 2");
    expect(wrapper.text()).toContain("hot-water");
    const cards = wrapper.findAll(".package-menu-card");
    await cards[0].get('button[data-mode="regular"]').trigger("click");
    await cards[0].get('button[data-duration="60"]').trigger("click");
    await cards[1].get('button[data-mode="regular"]').trigger("click");
    await cards[1].get('button[data-duration="60"]').trigger("click");
    expect(cards[0].text()).toContain("Rp 199.000");
    expect(cards[1].text()).toContain("Rp 299.000");
  });

  it("switches localized menu descriptions without translating names or prices", () => {
    inertia.page.props.locale = "id";
    const indonesian = mount(Home, { props: { packages } });
    inertia.page.props.locale = "en";
    const english = mount(Home, { props: { packages } });
    expect(indonesian.text()).toContain("Pijat lembut.");
    expect(english.text()).toContain("A gentle treatment.");
    for (const wrapper of [indonesian, english]) {
      expect(wrapper.text()).toContain("Nezuko VIP");
      expect(wrapper.text()).toContain("Rp199.000");
    }
  });

  it("uses Menu navigation and two package links in the shared footer", () => {
    const wrapper = mount(MainLayout, { slots: { default: "<p>content</p>" } });
    expect(wrapper.get('a[href="/#menu"]').text()).toBe("Menu");
    expect(wrapper.get('a[href="/services/nezuko"]')).toBeTruthy();
    expect(wrapper.get('a[href="/services/nezuko-vip"]')).toBeTruthy();
  });
});
