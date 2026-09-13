import { mount } from "@vue/test-utils";
import { readFileSync } from "node:fs";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import MainLayout from "@/layouts/MainLayout.vue";
import Home from "@/pages/Home.vue";
import Booking from "@/pages/Booking.vue";
import ServiceDetail from "@/pages/ServiceDetail.vue";
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
  it("keeps package CTAs as full-size anchors, including the mobile action", () => {
    const styles = readFileSync("resources/css/menu.css", "utf8");
    expect(styles).toMatch(
      /\.package-configurator__cta\s*\{[\s\S]*display:\s*(?:inline-)?flex[\s\S]*min-height:\s*44px/,
    );
    expect(styles).toMatch(
      /\.package-configurator__mobile-cta\s+\.package-configurator__cta\s*\{[\s\S]*width:\s*100%/,
    );
  });

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
    expect(wrapper.find(".package-menu-card__vip").text()).toBe("VIP");
    expect(wrapper.get('a[href="/services/nezuko"]')).toBeTruthy();
    expect(wrapper.get('a[href="/services/nezuko-vip"]')).toBeTruthy();
    expect(wrapper.get('a[href="/services/body-glow"]')).toBeTruthy();
    expect(wrapper.find(".cat-grid").exists()).toBe(false);
    expect(wrapper.text()).toContain("Jalan Terusan Batubara No. 12, Lantai 2");
    expect(wrapper.text()).toContain("hot-water");
    const cards = wrapper.findAll("[data-package-card]");
    await cards[0].get('button[data-mode="regular"]').trigger("click");
    await cards[0].get('button[data-duration="60"]').trigger("click");
    await cards[1].get('button[data-mode="regular"]').trigger("click");
    await cards[1].get('button[data-duration="60"]').trigger("click");
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
    expect(wrapper.get('a[href="/services/nezuko"]')).toBeTruthy();
    expect(wrapper.get('a[href="/services/nezuko-vip"]')).toBeTruthy();
  });
});

describe("package detail", () => {
  const nezuko: PackageData = {
    slug: "nezuko",
    nameId: "Nezuko",
    nameEn: "Nezuko",
    isVip: false,
    descId: "Pijat lembut.",
    descEn: "A gentle treatment.",
    longId: "Pijatan lembut yang menenangkan.",
    longEn: "A gentle treatment combining traditional and Thai massage.",
    benefitId: "Membantu tidur lebih nyenyak.",
    benefitEn: "Helps improve sleep quality.",
    image: "/img/massage.jpg",
    gallery: ["/img/massage.jpg", "/img/oil.jpg"],
    variants: [
      {
        code: "nezuko-regular-60",
        mode: "regular",
        duration: 60,
        price: 199000,
      },
      {
        code: "nezuko-regular-90",
        mode: "regular",
        duration: 90,
        price: 265000,
      },
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

  const nezukoVip: PackageData = {
    slug: "nezuko-vip",
    nameId: "Nezuko VIP",
    nameEn: "Nezuko VIP",
    isVip: true,
    descId: "Pijat sesuai kebutuhan.",
    descEn: "A tailored treatment.",
    longId: "Pijatan yang disesuaikan dengan kebutuhan klien.",
    longEn: "A tailored blend adjusted to the client's needs.",
    benefitId: "Membantu sirkulasi darah.",
    benefitEn: "Helps support healthy circulation.",
    image: "/img/about.jpg",
    gallery: ["/img/about.jpg", "/img/oil.jpg"],
    variants: [
      {
        code: "nezuko-vip-regular-60",
        mode: "regular",
        duration: 60,
        price: 299000,
      },
      {
        code: "nezuko-vip-regular-90",
        mode: "regular",
        duration: 90,
        price: 349000,
      },
      {
        code: "nezuko-vip-regular-120",
        mode: "regular",
        duration: 120,
        price: 499000,
      },
      {
        code: "nezuko-vip-double-60",
        mode: "double_therapist",
        duration: 60,
        price: 350000,
      },
    ],
    extensions: [
      {
        code: "nezuko-vip-regular-extra-60",
        mode: "regular",
        duration: 60,
        price: 100000,
      },
      {
        code: "nezuko-vip-double-extra-30",
        mode: "double_therapist",
        duration: 30,
        price: 100000,
      },
    ],
  };

  it("renders an accessible package configurator, gallery, and every supplied related link", async () => {
    const wrapper = mount(ServiceDetail, {
      props: {
        service: nezuko,
        related: [
          {
            slug: "nezuko-vip",
            nameId: "Nezuko VIP",
            nameEn: "Nezuko VIP",
            isVip: true,
          },
          {
            slug: "body-glow",
            nameId: "Body Glow",
            nameEn: "Body Glow",
            isVip: false,
          },
        ],
      },
    });

    expect(wrapper.get('nav[aria-label="Breadcrumb"]')).toBeTruthy();
    expect(wrapper.text()).toContain("A gentle treatment.");
    expect(wrapper.text()).toContain("Helps improve sleep quality.");
    expect(
      wrapper.get('[role="radiogroup"][aria-label="Treatment type"]'),
    ).toBeTruthy();
    await wrapper.get('button[data-mode="regular"]').trigger("click");
    expect(wrapper.findAll("button[data-duration]")).toHaveLength(3);
    await wrapper.get('button[data-duration="90"]').trigger("click");
    await wrapper.get('input[value="nezuko-regular-extra-60"]').setValue(true);
    expect(wrapper.text()).toContain("150 min");
    expect(wrapper.text()).toContain("Rp 340.000");
    expect(
      wrapper.get('a[data-booking-cta="desktop"]').attributes("href"),
    ).toBe(
      "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
    );
    await wrapper.get('button[data-mode="double_therapist"]').trigger("click");
    expect(wrapper.findAll("button[data-duration]")).toHaveLength(1);
    expect(
      wrapper.get("button[data-duration]").attributes("data-duration"),
    ).toBe("60");
    await wrapper.get('button[data-duration="60"]').trigger("click");
    expect(wrapper.get("input").attributes("value")).toBe(
      "nezuko-double-extra-30",
    );
    await wrapper.get('[aria-label="Nezuko 2"]').trigger("click");
    expect(wrapper.get(".detail-media > img").attributes("src")).toBe(
      "/img/oil.jpg",
    );
    const mainImage = wrapper.get(".detail-media > img");
    await mainImage.trigger("error");
    expect(mainImage.attributes("src")).toBe("/img/massage.jpg");
    await mainImage.trigger("error");
    expect(mainImage.attributes("src")).toBe("/img/massage.jpg");
    const thumbnailImages = wrapper.findAll(".detail-thumbs img");
    await thumbnailImages[0].trigger("error");
    await thumbnailImages[1].trigger("error");
    expect(thumbnailImages[0].attributes("src")).toBe("/img/massage.jpg");
    expect(thumbnailImages[1].attributes("src")).toBe("/img/massage.jpg");
    expect(
      wrapper.findAll('.package-detail a[href="/services/nezuko-vip"]'),
    ).toHaveLength(1);
    expect(
      wrapper.findAll('.package-detail a[href="/services/body-glow"]'),
    ).toHaveLength(1);
    expect(wrapper.text()).toContain("You may also enjoy");
    expect(wrapper.find(".svc-tag").exists()).toBe(false);
    expect(wrapper.find(".price-row").exists()).toBe(false);
    expect(wrapper.find(".svc-grid").exists()).toBe(false);
  });

  it("renders the complete VIP choices, configured total, and reverse related link", async () => {
    inertia.page.props.locale = "id";
    const vip = mount(ServiceDetail, {
      props: {
        service: nezukoVip,
        related: [
          {
            slug: "nezuko",
            nameId: "Nezuko",
            nameEn: "Nezuko",
            isVip: false,
          },
        ],
      },
    });
    expect(vip.text()).toContain("VIP");
    expect(vip.text()).toContain("Pijat sesuai kebutuhan.");
    expect(vip.text()).toContain("Membantu sirkulasi darah.");
    await vip.get('button[data-mode="regular"]').trigger("click");
    expect(
      vip
        .findAll("button[data-duration]")
        .map((button) => button.attributes("data-duration")),
    ).toEqual(["60", "90", "120"]);
    await vip.get('button[data-duration="90"]').trigger("click");
    await vip.get('input[value="nezuko-vip-regular-extra-60"]').setValue(true);
    expect(vip.text()).toContain("150 min");
    expect(vip.text()).toContain("Rp 449.000");
    expect(vip.get('a[data-booking-cta="desktop"]').attributes("href")).toBe(
      "/booking?service=nezuko-vip&mode=regular&duration=90&extension=nezuko-vip-regular-extra-60",
    );
    await vip.get('button[data-mode="double_therapist"]').trigger("click");
    expect(
      vip
        .findAll("button[data-duration]")
        .map((button) => button.attributes("data-duration")),
    ).toEqual(["60"]);
    await vip.get('button[data-duration="60"]').trigger("click");
    expect(vip.get("input").attributes("value")).toBe(
      "nezuko-vip-double-extra-30",
    );
    expect(
      vip.findAll('.package-detail a[href="/services/nezuko"]'),
    ).toHaveLength(1);
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
    expect(wrapper.get<HTMLInputElement>("#f-email").element.readOnly).toBe(
      true,
    );
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
