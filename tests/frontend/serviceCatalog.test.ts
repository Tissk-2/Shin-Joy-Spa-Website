import { mount } from "@vue/test-utils";
import { readFileSync } from "node:fs";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import PackageConfigurator from "@/components/PackageConfigurator.vue";
import { emptySelection } from "@/lib/serviceSelection";
import MainLayout from "@/layouts/MainLayout.vue";
import Home from "@/pages/Home.vue";
import Booking from "@/pages/Booking.vue";
import Services from "@/pages/Services.vue";
import type { PackageData, PublicCatalogCategoryData } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/",
    props: {
      auth: { user: null, canAccessAdmin: false },
      locale: "en",
      flash: {},
      catalog: { packages: [] },
    },
  },
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");

  return {
    Head: "span",
    Link: "a",
    router: { post: vi.fn() },
    usePage: () => inertia.page,
    useForm: (initial: Record<string, unknown>) =>
      reactive({
        ...initial,
        errors: {},
        processing: false,
        transform: vi.fn(function (this: { post: ReturnType<typeof vi.fn> }) {
          return this;
        }),
        post: vi.fn(),
      }),
  };
});

const packages: PackageData[] = [
  {
    slug: "nezuko",
    nameId: "Nezuko",
    nameEn: "Nezuko",
    isVip: false,
    descId: "Pijat lembut.",
    descEn: "A gentle treatment.",
    longId: "",
    longEn: "",
    benefitId: "",
    benefitEn: "",
    image: "/img/massage.jpg",
    gallery: [],
    variants: [{ code: "n60", mode: "regular", duration: 60, price: 199000 }],
    extensions: [],
  },
  {
    slug: "nezuko-vip",
    nameId: "Nezuko VIP",
    nameEn: "Nezuko VIP",
    isVip: true,
    descId: "Pijat sesuai kebutuhan.",
    descEn: "A tailored treatment.",
    longId: "",
    longEn: "",
    benefitId: "",
    benefitEn: "",
    image: "/img/massage.jpg",
    gallery: [],
    variants: [{ code: "v60", mode: "regular", duration: 60, price: 299000 }],
    extensions: [],
  },
];

const bodyPackage: PackageData = {
  ...packages[0],
  slug: "body-glow",
  nameId: "Body Glow",
  nameEn: "Body Glow",
  descId: "Perawatan tubuh yang menyegarkan.",
  descEn: "A refreshing body treatment.",
  image: "/img/body-treatment.jpg",
  variants: [
    { code: "body-90", mode: "regular", duration: 90, price: 225000 },
    { code: "body-60", mode: "regular", duration: 60, price: 175000 },
  ],
};

const categories: PublicCatalogCategoryData[] = [
  {
    slug: "massage",
    nameId: "Pijat",
    nameEn: "Massage",
    image: "/img/massage.jpg",
    packages,
  },
  {
    slug: "body-care",
    nameId: "Perawatan Tubuh",
    nameEn: "Body Care",
    image: null,
    packages: [bodyPackage],
  },
];

beforeEach(() => {
  inertia.page.url = "/";
  inertia.page.props = {
    auth: { user: null, canAccessAdmin: false },
    locale: "en",
    flash: {},
    catalog: { packages },
  };
  Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => vi.restoreAllMocks());

describe("official menu homepage", () => {
  it("styles separated outlined choices and a full-width booking action", () => {
    const styles = readFileSync("resources/css/menu.css", "utf8");
    const configuratorBlock = styles.match(
      /\.package-configurator\s*\{[^}]*\}/,
    )?.[0];

    expect(configuratorBlock).toBeTruthy();
    expect(configuratorBlock).toMatch(/--config-surface:\s*var\(--panel\)/);
    expect(styles).toMatch(
      /\.package-configurator__group\s*\{[^}]*gap:\s*0\.75rem/,
    );
    expect(styles).toMatch(
      /\.package-configurator__group\s+button\s*\{[^}]*padding:[^;]+;[^}]*border:\s*1px solid/,
    );
    expect(styles).toMatch(
      /\.package-configurator__group\s+button\[aria-checked="true"\]\s*\{[^}]*background:/,
    );
    expect(styles).toMatch(
      /\.package-configurator__cta\s*\{[^}]*display:\s*(?:inline-)?flex[^}]*min-height:\s*44px[^}]*width:\s*100%/,
    );
    expect(styles).toMatch(
      /\.package-configurator__extension input:focus-visible/,
    );
    expect(styles).not.toContain(".package-configurator__mobile-cta");
  });

  it.each([
    [
      "en",
      "Choose a treatment type first",
      "Choose a duration first",
      "Book now",
    ],
    [
      "id",
      "Pilih jenis perawatan terlebih dahulu",
      "Pilih durasi terlebih dahulu",
      "Pesan sekarang",
    ],
  ])(
    "guides %s selections and exposes only one booking action",
    async (locale, chooseType, chooseDuration, book) => {
      inertia.page.props.locale = locale;
      const wrapper = mount(PackageConfigurator, {
        props: {
          servicePackage: packages[0],
          modelValue: emptySelection("nezuko"),
        },
      });
      expect(wrapper.get('[role="status"]').text()).toBe(chooseType);
      expect(wrapper.find(".package-configurator__total").exists()).toBe(false);
      expect(wrapper.findAll(".package-configurator__cta")).toHaveLength(1);
      expect(
        wrapper.get(".package-configurator__cta").attributes("disabled"),
      ).toBeDefined();
      expect(wrapper.findAll("button[data-duration]")).toHaveLength(0);

      await wrapper.get('[data-mode="regular"]').trigger("click");
      expect(wrapper.get('[role="status"]').text()).toBe(chooseDuration);
      expect(wrapper.find(".package-configurator__total").exists()).toBe(false);
      await wrapper.get('[data-duration="60"]').trigger("click");
      expect(wrapper.get(".package-configurator__total").text()).toBe(
        "Total: 60 min · Rp 199.000",
      );
      expect(wrapper.findAll(".package-configurator__cta")).toHaveLength(1);
      expect(wrapper.get("a.package-configurator__cta").text()).toBe(book);
      expect(
        wrapper.get("a.package-configurator__cta").attributes("href"),
      ).toBe("/booking?service=nezuko&mode=regular&duration=60");
    },
  );

  it("renders every grouped category and package in the supplied order at the canonical menu anchor", async () => {
    const wrapper = mount(Home, { props: { categories } });
    expect(wrapper.get("#menu")).toBeTruthy();
    expect(wrapper.findAll("[data-catalog-category]")).toHaveLength(2);
    expect(wrapper.findAll("[data-package-card]")).toHaveLength(3);
    expect(
      wrapper
        .findAll("[data-catalog-category]")
        .map((section) => section.attributes("data-catalog-category")),
    ).toEqual(["massage", "body-care"]);
    expect(wrapper.text()).toContain("Massage");
    expect(wrapper.text()).toContain("Body Care");
    expect(wrapper.text()).toContain("Rp 175.000");
    expect(
      wrapper.findAll(".package-menu-card__type").some((label) =>
        label.text().includes("VIP"),
      ),
    ).toBe(true);
    expect(wrapper.get('a[href="/booking?service=nezuko"]')).toBeTruthy();
    expect(wrapper.get('a[href="/booking?service=nezuko-vip"]')).toBeTruthy();
    expect(wrapper.get('a[href="/booking?service=body-glow"]')).toBeTruthy();
    expect(wrapper.find(".cat-grid").exists()).toBe(false);
    expect(wrapper.text()).toContain("Jalan Terusan Batubara No. 12, Lantai 2");
    expect(wrapper.text()).toMatch(/hot[- ]water/i);
    expect(wrapper.findAll("address")).toHaveLength(1);
    const cards = wrapper.findAll("[data-package-card]");
    expect(wrapper.find(".package-configurator").exists()).toBe(false);
    expect(cards[0].text()).toContain("Rp 199.000");
    expect(cards[1].text()).toContain("Rp 299.000");
  });

  it("switches localized category and package copy without changing prices", () => {
    inertia.page.props.locale = "id";
    const indonesian = mount(Home, { props: { categories } });
    inertia.page.props.locale = "en";
    const english = mount(Home, { props: { categories } });
    expect(indonesian.text()).toContain("Perawatan Tubuh");
    expect(indonesian.text()).toContain("Pijat lembut.");
    expect(english.text()).toContain("Body Care");
    expect(english.text()).toContain("A gentle treatment.");
    for (const wrapper of [indonesian, english]) {
      expect(wrapper.text()).toContain("Nezuko VIP");
      expect(wrapper.text()).toContain("Rp 175.000");
    }
  });

  it("renders a localized empty state when no published category contains packages", () => {
    inertia.page.props.locale = "en";
    const categoriesWithoutPackages: PublicCatalogCategoryData[] = [
      { ...categories[0], packages: [] },
    ];
    const home = mount(Home, {
      props: { categories: categoriesWithoutPackages },
    });
    const services = mount(Services, {
      props: { categories: categoriesWithoutPackages },
    });

    for (const wrapper of [home, services]) {
      expect(wrapper.find("[data-catalog-empty]").exists()).toBe(true);
      expect(wrapper.find("[data-catalog-category]").exists()).toBe(false);
      expect(wrapper.text()).toContain("Our treatment menu is being refreshed");
      expect(wrapper.text()).not.toContain("undefined");
    }
  });

  it("renders the standalone services page for zero, one, and multiple categories", () => {
    const empty = mount(Services, { props: { categories: [] } });
    const single = mount(Services, { props: { categories: [categories[1]] } });
    const multiple = mount(Services, { props: { categories } });

    expect(empty.find("[data-catalog-empty]").exists()).toBe(true);
    expect(single.findAll("[data-catalog-category]")).toHaveLength(1);
    expect(single.findAll("[data-package-card]")).toHaveLength(1);
    expect(multiple.findAll("[data-catalog-category]")).toHaveLength(2);
    expect(multiple.findAll("[data-package-card]")).toHaveLength(3);
  });

  it("uses the catalog page for Services navigation and keeps package links in the footer", () => {
    const wrapper = mount(MainLayout, { slots: { default: "<p>content</p>" } });
    expect(wrapper.findAll('a[href="/services"]')).toHaveLength(2);
    expect(wrapper.find('a[href="/#menu"]').exists()).toBe(false);
    expect(wrapper.get('a[href="/booking?service=nezuko"]')).toBeTruthy();
    expect(wrapper.get('a[href="/booking?service=nezuko-vip"]')).toBeTruthy();
  });
});

describe("authenticated package booking", () => {
  it("renders the normalized configuration, retains editable account details, and reviews without claiming persistence", async () => {
    inertia.page.props = {
      auth: {
        user: {
          id: 1,
          name: "Nadia",
          phone: "+62 812-3456-7890",
          email: "nadia@example.test",
          email_verified_at: "2026-08-01",
        },
        canAccessAdmin: false,
      },
      locale: "en",
      flash: {},
      catalog: { packages },
    };
    const configured = {
      service: "nezuko",
      mode: "regular" as const,
      duration: 90,
      extension: "nezuko-regular-extra-60",
    };
    const bookingPackages = [
      {
        ...packages[0],
        variants: [
          {
            code: "n90",
            mode: "regular" as const,
            duration: 90,
            price: 265000,
          },
          {
            code: "n-double",
            mode: "double_therapist" as const,
            duration: 60,
            price: 250000,
          },
        ],
        extensions: [
          {
            code: "nezuko-regular-extra-60",
            mode: "regular" as const,
            duration: 60,
            price: 75000,
          },
          {
            code: "nezuko-double-extra-30",
            mode: "double_therapist" as const,
            duration: 30,
            price: 100000,
          },
        ],
      },
    ];
    const wrapper = mount(Booking, {
      props: {
        packages: bookingPackages,
        selection: configured,
        submissionKey: "018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10",
      },
    });
    expect(wrapper.find("#f-email").exists()).toBe(false);
    expect(wrapper.get<HTMLInputElement>("#f-name").element.value).toBe(
      "Nadia",
    );
    expect(wrapper.get<HTMLInputElement>("#f-phone").element.value).toBe(
      "+62 812-3456-7890",
    );
    expect(wrapper.text()).toContain("150 min");
    expect(wrapper.text()).toContain("Rp 340.000");
    expect(wrapper.get('button[data-mode="double_therapist"]')).toBeTruthy();
    await wrapper.get('button[data-mode="double_therapist"]').trigger("click");
    expect(wrapper.findAll("button[data-duration]")).toHaveLength(1);
    expect(
      wrapper.find('input[value="nezuko-regular-extra-60"]').exists(),
    ).toBe(false);
    expect(wrapper.text()).toContain("Review booking");
    expect(wrapper.text()).not.toContain("Send reservation");
    await wrapper.get('button[data-mode="regular"]').trigger("click");
    await wrapper.get('button[data-duration="90"]').trigger("click");
    await wrapper.get('input[value="nezuko-regular-extra-60"]').setValue(true);
    await wrapper.get<HTMLInputElement>("#f-date").setValue("2099-09-06");
    await wrapper.get<HTMLSelectElement>("#f-time").setValue("09:00");
    await wrapper.get("form").trigger("submit");
    expect(wrapper.text()).toContain("No reservation has been submitted yet");
    expect(wrapper.text()).toContain("Edit details");
    expect(wrapper.text()).toContain("Base duration");
    expect(wrapper.text()).toContain("Extension");
    expect(wrapper.text()).toContain("60 min · Rp 75.000");
  });
});
