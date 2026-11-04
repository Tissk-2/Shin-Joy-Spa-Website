import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";

import PackageConfigurator from "@/components/PackageConfigurator.vue";
import PackageMenuCard from "@/components/PackageMenuCard.vue";
import type { PackageData } from "@/types";

const inertia = vi.hoisted(() => ({
  props: { locale: "en", auth: { user: null } },
}));
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

describe("verified public menu pricing", () => {
  it.each([
    ["en", "per_person", "Prices per person · 2–4 guests"],
    ["en", "per_booking", "Prices per booking · 2–4 guests"],
    ["id", "per_person", "Harga per orang · 2–4 tamu"],
    ["id", "per_booking", "Harga per reservasi · 2–4 tamu"],
  ] as const)(
    "shows basis and coverage in %s for %s",
    (locale, pricingBasis, label) => {
      inertia.props.locale = locale;
      const wrapper = mount(PackageMenuCard, {
        props: {
          servicePackage: {
            ...nezuko,
            pricingVerified: true,
            pricingBasis,
            minGuests: 2,
            maxGuests: 4,
          },
        },
      });
      expect(wrapper.get("[data-pricing-scope]").text()).toBe(label);
      expect(wrapper.text()).toContain("Rp 199.000");
      wrapper.unmount();
    },
  );

  it("does not infer pricing scope for legacy packages", () => {
    const wrapper = mount(PackageMenuCard, {
      props: { servicePackage: nezuko },
    });
    expect(wrapper.find("[data-pricing-scope]").exists()).toBe(false);
    wrapper.unmount();
  });
});

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
    expect(configurator().findAll(".package-configurator__cta")).toHaveLength(
      1,
    );
    expect(configurator().find(".package-configurator__total").exists()).toBe(
      false,
    );

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
    expect(configurator().findAll(".package-configurator__cta")).toHaveLength(
      1,
    );
    expect(
      configurator().find(".package-configurator__mobile-cta").exists(),
    ).toBe(false);

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
    ["en", "Choose a treatment type first"],
    ["id", "Pilih jenis perawatan terlebih dahulu"],
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
      expect(configurator().get('[role="status"]').text()).toBe(invalidLabel);
      expect(configurator().findAll(".package-configurator__cta")).toHaveLength(
        1,
      );

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

  it("renders concise package content, full published prices, and accessible optional details", async () => {
    const original = structuredClone(nezuko);
    inertia.props.locale = "id";
    const wrapper = mount(PackageMenuCard, {
      props: { servicePackage: nezuko },
    });

    expect(wrapper.get('article[aria-label="Nezuko"]')).toBeTruthy();
    expect(wrapper.text()).toContain("Pijat lembut.");
    expect(wrapper.get('a[href="/booking?service=nezuko"]')).toBeTruthy();
    expect(wrapper.get("h3").text()).toBe("Nezuko");
    expect(wrapper.get("table").text()).toContain("60 menit");
    expect(wrapper.get("table").text()).toContain("Rp 199.000");
    expect(wrapper.get("table").text()).toContain("Rp 265.000");
    expect(wrapper.get("table").text()).toContain("Rp 315.000");
    expect(wrapper.get("details summary").text()).toContain("Dua terapis");
    expect(wrapper.get("details summary").element.tagName).toBe("SUMMARY");
    expect(wrapper.get("details").text()).toContain("Rp 250.000");
    expect(wrapper.findAll("details")).toHaveLength(2);
    expect(
      wrapper
        .get("[data-booking-auth-note]")
        .element.compareDocumentPosition(
          wrapper.get('a[href="/booking?service=nezuko"]').element,
        ) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      wrapper
        .get('a[href="/booking?service=nezuko"]')
        .element.compareDocumentPosition(wrapper.get("details").element) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
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

  it("uses the published name and omits absent benefits and choices", () => {
    inertia.props.locale = "en";
    const wrapper = mount(PackageMenuCard, {
      props: {
        servicePackage: {
          ...nezuko,
          slug: "royal-recovery",
          nameEn: "Royal Recovery",
          nameId: "Pemulihan Royal",
          isVip: true,
          benefitEn: null,
          benefitId: null,
          variants: [nezuko.variants[0]],
          extensions: [],
        },
      },
    });

    expect(wrapper.get("h3").text()).toBe("Royal Recovery");
    expect(wrapper.text()).not.toContain("circulation");
    expect(wrapper.text()).not.toContain("Double Therapist");
    expect(wrapper.text()).not.toContain("Extra 30");
    expect(wrapper.text()).not.toContain("Extra 60");
  });

  it("shows only configured duration and mode specific extension prices", () => {
    inertia.props.locale = "en";
    const wrapper = mount(PackageMenuCard, {
      props: {
        servicePackage: {
          ...nezuko,
          variants: [
            {
              code: "double-90",
              mode: "double_therapist",
              duration: 90,
              price: 310000,
            },
          ],
          extensions: [
            {
              code: "double-extra-45",
              mode: "double_therapist",
              duration: 45,
              price: 123000,
            },
          ],
        },
      },
    });

    expect(wrapper.text()).toContain("Double therapist");
    expect(wrapper.text()).toContain("45 min");
    expect(wrapper.text()).toContain("Rp 123.000");
    expect(wrapper.text()).not.toContain("Extra 30");
    expect(wrapper.text()).not.toContain("Extra 60");
  });

  it("does not advertise an extension without a bookable variant in its mode", () => {
    inertia.props.locale = "en";
    const wrapper = mount(PackageMenuCard, {
      props: {
        servicePackage: {
          ...nezuko,
          variants: [nezuko.variants[0]],
          extensions: [nezuko.extensions[1]],
        },
      },
    });

    expect(wrapper.text()).not.toContain("Extra 30 Min");
    expect(wrapper.text()).not.toContain("Rp 100.000");
  });

  it("uses a mode label in the primary comparison when a package has no regular mode", () => {
    inertia.props.locale = "en";
    const wrapper = mount(PackageMenuCard, {
      props: {
        servicePackage: {
          ...nezuko,
          variants: [nezuko.variants[3]],
          extensions: [],
        },
      },
    });

    expect(wrapper.get("table").text()).toContain("Double therapist");
    expect(wrapper.get("table").text()).toContain("Rp 250.000");
    expect(wrapper.findAll("details")).toHaveLength(0);
  });

  it.each([
    ["en", "Duration", "Price", "Double therapist", "Extension"],
    ["id", "Durasi", "Harga", "Dua terapis", "Tambahan"],
  ] as const)(
    "localizes comparison and optional mode labels in %s",
    (locale, duration, price, doubleTherapist, extension) => {
      inertia.props.locale = locale;
      const wrapper = mount(PackageMenuCard, {
        props: { servicePackage: nezuko },
      });

      expect(wrapper.get("table thead").text()).toContain(duration);
      expect(wrapper.get("table thead").text()).toContain(price);
      expect(
        wrapper.findAll("details summary").map((item) => item.text()),
      ).toEqual([doubleTherapist, extension]);
    },
  );
});
