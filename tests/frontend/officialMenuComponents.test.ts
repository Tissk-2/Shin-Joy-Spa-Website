import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";

import PackageConfigurator from "@/components/PackageConfigurator.vue";
import PackageMenuCard from "@/components/PackageMenuCard.vue";
import type { PackageData } from "@/types";

const inertia = vi.hoisted(() => ({ props: { locale: "en" } }));
vi.mock("@inertiajs/vue3", () => ({
  Link: "a",
  router: { post: vi.fn() },
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
    { code: "nezuko-regular-60", mode: "regular", duration: 60, price: 199000 },
    { code: "nezuko-regular-90", mode: "regular", duration: 90, price: 265000 },
    {
      code: "nezuko-regular-120",
      mode: "regular",
      duration: 120,
      price: 315000,
    },
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
  function mountHost(showBookingLink = true) {
    const Host = defineComponent({
      components: { PackageConfigurator },
      props: {
        servicePackage: { type: Object, required: true },
        showBookingLink: { type: Boolean, required: true },
      },
      setup() {
        const selection = ref({
          service: "nezuko",
          mode: "" as const,
          duration: null,
          extension: "",
        });
        return { selection };
      },
      template:
        '<PackageConfigurator v-model="selection" :service-package="servicePackage" :show-booking-link="showBookingLink" />',
    });
    return mount(Host, {
      attachTo: document.body,
      props: {
        servicePackage: nezuko,
        showBookingLink,
      },
    });
  }

  it("updates a real v-model host and renders compatible configured CTAs", async () => {
    const original = structuredClone(nezuko);
    inertia.props.locale = "en";
    const wrapper = mountHost();
    const configurator = () => wrapper.getComponent(PackageConfigurator);

    expect(
      configurator().get('[role="radiogroup"][aria-label="Treatment type"]'),
    ).toBeTruthy();
    expect(
      configurator().get('[data-booking-cta="desktop"]').attributes("disabled"),
    ).toBeDefined();
    expect(
      configurator()
        .get(".package-configurator__mobile-cta button")
        .attributes("disabled"),
    ).toBeDefined();

    await configurator().get('button[data-mode="regular"]').trigger("click");
    expect(wrapper.vm.selection).toMatchObject({
      service: "nezuko",
      mode: "regular",
      duration: null,
      extension: "",
    });
    expect(
      configurator()
        .get('button[data-mode="regular"]')
        .attributes("aria-pressed"),
    ).toBe("true");
    expect(
      configurator()
        .get('button[data-mode="regular"]')
        .attributes("aria-checked"),
    ).toBe("true");
    expect(configurator().findAll("button[data-duration]")).toHaveLength(3);
    expect(
      configurator()
        .findAll("button[data-duration]")
        .map((button) => button.attributes("data-duration")),
    ).toEqual(["60", "90", "120"]);

    await configurator().get('button[data-duration="90"]').trigger("click");
    await configurator()
      .get('input[value="nezuko-regular-extra-60"]')
      .setValue(true);
    expect(wrapper.vm.selection).toMatchObject({
      duration: 90,
      extension: "nezuko-regular-extra-60",
    });
    expect(configurator().text()).toContain("Rp 340.000");
    expect(configurator().text()).toContain("150 min");
    expect(
      configurator().get('a[data-booking-cta="desktop"]').attributes("href"),
    ).toBe(
      "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
    );
    expect(
      configurator()
        .get(".package-configurator__mobile-cta a")
        .attributes("href"),
    ).toBe(
      "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
    );

    await configurator()
      .get('button[data-mode="double_therapist"]')
      .trigger("click");
    expect(configurator().findAll("button[data-duration]")).toHaveLength(1);
    expect(
      configurator().get("button[data-duration]").attributes("data-duration"),
    ).toBe("60");
    expect(wrapper.vm.selection).toMatchObject({
      mode: "double_therapist",
      duration: null,
      extension: "",
    });
    await configurator().get('button[data-duration="60"]').trigger("click");
    expect(
      configurator().get('input[type="checkbox"]').attributes("value"),
    ).toBe("nezuko-double-extra-30");
    expect(nezuko).toEqual(original);
  });

  it("uses Indonesian labels and Arrow, Home, and End keys to select and focus button radios", async () => {
    inertia.props.locale = "id";
    const wrapper = mountHost();
    const configurator = () => wrapper.getComponent(PackageConfigurator);
    const regular = configurator().get('button[data-mode="regular"]');

    expect(
      configurator().get('[role="radiogroup"][aria-label="Jenis perawatan"]'),
    ).toBeTruthy();
    await regular.trigger("keydown", { key: "ArrowRight" });
    expect(wrapper.vm.selection.mode).toBe("double_therapist");
    expect(
      configurator()
        .get('button[data-mode="double_therapist"]')
        .attributes("aria-checked"),
    ).toBe("true");
    expect(document.activeElement).toBe(
      configurator().get('button[data-mode="double_therapist"]').element,
    );

    await configurator()
      .get('button[data-mode="double_therapist"]')
      .trigger("keydown", { key: "Home" });
    expect(wrapper.vm.selection.mode).toBe("regular");
    expect(document.activeElement).toBe(
      configurator().get('button[data-mode="regular"]').element,
    );

    await configurator()
      .get('button[data-duration="60"]')
      .trigger("keydown", { key: "End" });
    expect(wrapper.vm.selection.duration).toBe(120);
    expect(
      configurator()
        .get('button[data-duration="120"]')
        .attributes("aria-pressed"),
    ).toBe("true");
    expect(document.activeElement).toBe(
      configurator().get('button[data-duration="120"]').element,
    );
  });

  it.each([
    ["en", "Choose a duration first"],
    ["id", "Pilih durasi terlebih dahulu"],
  ] as const)(
    "renders disabled %s invalid CTAs and hides all CTAs once a valid hidden-link configuration is selected",
    async (locale, invalidLabel) => {
      inertia.props.locale = locale;
      const wrapper = mountHost();
      const configurator = () => wrapper.getComponent(PackageConfigurator);

      expect(configurator().get('[data-booking-cta="desktop"]').text()).toBe(
        invalidLabel,
      );
      expect(
        configurator()
          .get('[data-booking-cta="desktop"]')
          .attributes("disabled"),
      ).toBeDefined();
      expect(
        configurator().get(".package-configurator__mobile-cta button").text(),
      ).toBe(invalidLabel);
      expect(
        configurator()
          .get(".package-configurator__mobile-cta button")
          .attributes("disabled"),
      ).toBeDefined();

      await wrapper.setProps({ showBookingLink: false });
      await configurator().get('button[data-mode="regular"]').trigger("click");
      await configurator().get('button[data-duration="90"]').trigger("click");
      expect(configurator().find('[data-booking-cta="desktop"]').exists()).toBe(
        false,
      );
      expect(
        configurator().find(".package-configurator__mobile-cta").exists(),
      ).toBe(false);
      expect(configurator().text()).not.toContain(invalidLabel);
    },
  );

  it("renders localized editorial package content without mutating its prop", async () => {
    const original = structuredClone(nezuko);
    inertia.props.locale = "id";
    const wrapper = mount(PackageMenuCard, {
      props: { servicePackage: nezuko },
    });

    expect(wrapper.get('article[aria-label="Nezuko"]')).toBeTruthy();
    expect(wrapper.text()).toContain("Pijat lembut.");
    expect(wrapper.get('a[href="/services/nezuko"]')).toBeTruthy();
    expect(wrapper.get("[data-starting-price]").text()).toContain(
      "Mulai dari Rp 199.000",
    );
    expect(wrapper.get("img").attributes("src")).toBe("/img/nezuko.jpg");
    await wrapper.get("img").trigger("error");
    expect(wrapper.get("img").attributes("src")).toContain("/img/massage.jpg");
    expect(nezuko).toEqual(original);
  });

  it("derives the starting price from unsorted variants and handles no variants safely", () => {
    inertia.props.locale = "en";
    const unsorted = mount(PackageMenuCard, {
      props: {
        servicePackage: {
          ...nezuko,
          variants: [
            { code: "high", mode: "regular", duration: 90, price: 265000 },
            { code: "low", mode: "regular", duration: 60, price: 175000 },
          ],
        },
      },
    });
    const unavailable = mount(PackageMenuCard, {
      props: { servicePackage: { ...nezuko, variants: [] } },
    });

    expect(unsorted.get("[data-starting-price]").text()).toContain(
      "From Rp 175.000",
    );
    expect(unavailable.get("[data-starting-price]").text()).toBe(
      "This selection is currently unavailable.",
    );
    expect(unavailable.text()).not.toContain("Infinity");
  });
});
