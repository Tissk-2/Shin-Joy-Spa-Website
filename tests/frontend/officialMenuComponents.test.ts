import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import PackageConfigurator from "@/components/PackageConfigurator.vue";
import PackageMenuCard from "@/components/PackageMenuCard.vue";
import type { PackageData } from "@/types";

const inertia = vi.hoisted(() => ({ props: { locale: "en" } }));
vi.mock("@inertiajs/vue3", () => ({
  Link: "a",
  usePage: () => ({ props: inertia.props }),
}));

const nezuko: PackageData = {
  slug: "nezuko",
  nameId: "Nezuko",
  nameEn: "Nezuko",
  isVip: false,
  descId: "Pijat lembut.",
  descEn: "Gentle massage.",
  longId: "Pijat lembut.",
  longEn: "Gentle massage.",
  benefitId: "Tidur lebih baik.",
  benefitEn: "Better sleep.",
  image: "/img/nezuko.jpg",
  gallery: [],
  variants: [
    { code: "nezuko-regular-90", mode: "regular", duration: 90, price: 265000 },
    {
      code: "nezuko-double-60",
      mode: "double_therapist",
      duration: 60,
      price: 250000,
    },
  ],
  extensions: [
    {
      code: "nezuko-regular-extra-60",
      mode: "regular",
      duration: 60,
      price: 75000,
    },
    {
      code: "nezuko-double-extra-30",
      mode: "double_therapist",
      duration: 30,
      price: 100000,
    },
  ],
};

describe("PackageConfigurator", () => {
  it("offers compatible choices and a configured booking link", async () => {
    const wrapper = mount(PackageConfigurator, {
      props: {
        servicePackage: nezuko,
        modelValue: {
          service: "nezuko",
          mode: "",
          duration: null,
          extension: "",
        },
        showBookingLink: true,
      },
    });
    expect(
      wrapper.get('[role="radiogroup"][aria-label="Treatment type"]'),
    ).toBeTruthy();
    expect(
      wrapper.get('[data-booking-cta="desktop"]').attributes("disabled"),
    ).toBeDefined();

    await wrapper.get('button[data-mode="regular"]').trigger("click");
    await wrapper.get('button[data-duration="90"]').trigger("click");
    await wrapper.get('input[value="nezuko-regular-extra-60"]').setValue(true);

    expect(wrapper.text()).toContain("Rp 340.000");
    expect(wrapper.text()).toContain("150 min");
    expect(
      wrapper.get('a[data-booking-cta="desktop"]').attributes("href"),
    ).toBe(
      "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
    );
    expect(
      wrapper.get(".package-configurator__mobile-cta a").attributes("href"),
    ).toBe(
      "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
    );

    await wrapper.get('button[data-mode="double_therapist"]').trigger("click");
    expect(wrapper.findAll("button[data-duration]")).toHaveLength(1);
    expect(
      wrapper.get("button[data-duration]").attributes("data-duration"),
    ).toBe("60");
    await wrapper.get("button[data-duration]").trigger("click");
    expect(wrapper.get('input[type="checkbox"]').attributes("value")).toBe(
      "nezuko-double-extra-30",
    );
  });

  it("renders localized editorial package content without mutating its prop", async () => {
    const original = structuredClone(nezuko);
    inertia.props.locale = "id";
    const wrapper = mount(PackageMenuCard, {
      props: { servicePackage: nezuko },
    });

    expect(wrapper.get('article[aria-label="Nezuko"]')).toBeTruthy();
    expect(wrapper.text()).toContain("Pijat lembut.");
    expect(wrapper.get('a[href="/services/nezuko"]')).toBeTruthy();
    expect(wrapper.get("img").attributes("src")).toBe("/img/nezuko.jpg");
    await wrapper.get("img").trigger("error");
    expect(wrapper.get("img").attributes("src")).toContain("/img/massage.jpg");
    expect(nezuko).toEqual(original);
  });
});
