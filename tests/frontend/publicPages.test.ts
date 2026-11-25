import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import MainLayout from "@/layouts/MainLayout.vue";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/",
    props: {
      auth: {
        user: {
          id: 7,
          name: "Nadia Lestari",
          email: "nadia@example.test",
          phone: "+62 812-3456-7890",
          email_verified_at: "2026-08-01",
        },
        canAccessAdmin: false,
      },
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

const pageRoot = resolve(import.meta.dirname, "../../resources/js/pages");
const resourceRoot = resolve(import.meta.dirname, "../../resources/js");
const readPage = (name: string): string =>
  readFileSync(resolve(pageRoot, name), "utf8");

beforeEach(() => {
  inertia.page.url = "/";
  inertia.page.props.auth.canAccessAdmin = false;
  Element.prototype.scrollIntoView = vi.fn();
});

describe("Laravel-owned public pages", () => {
  it("uses the Inertia shell, navigation, and document head on every page", () => {
    for (const name of [
      "Home.vue",
      "Services.vue",
      "Booking.vue",
      "bookings/Index.vue",
      "bookings/Show.vue",
      "Profile.vue",
    ]) {
      const source = readPage(name);

      expect(source).toMatch(/MainLayout/);
      expect(source).toMatch(/<Head/);
      expect(source).not.toMatch(/vue-router|RouterLink|useRoute/);
    }
  });

  it("keeps the bilingual home sections and has no stock-photo gallery", () => {
    const source = readPage("Home.vue");

    expect(source).toMatch(/useLanguage/);
    expect(source).toMatch(/id="menu"/);
    expect(source).toMatch(/id="about"/);
    expect(source).toMatch(/id="faq"/);
    expect(source).toMatch(/id="contact"/);
    expect(source).not.toMatch(/gallery|lightbox|about\.webp/i);
  });

  it("offers a booking link in the header, hidden only on the booking page", () => {
    const home = mount(MainLayout, { slots: { default: "<h1>Page</h1>" } });
    expect(home.get(".header-book").attributes("href")).toBe("/booking");
    expect(home.get("#primary-nav .nav-book").attributes("href")).toBe(
      "/booking",
    );
    expect(
      home.findAll("#primary-nav .nav-link").map((link) => link.text()),
    ).toEqual(["Home", "Menu", "About", "Contact"]);

    inertia.page.url = "/booking?service=nezuko";
    const booking = mount(MainLayout, { slots: { default: "<h1>Page</h1>" } });
    expect(booking.find(".header-book").exists()).toBe(false);
    expect(booking.find(".nav-book").exists()).toBe(false);
  });

  // Flow review F11: deleting the account used to land on the home page with no word that it worked.
  it("says the account was deleted, once, and lets the visitor close the notice", async () => {
    (inertia.page.props.flash as Record<string, unknown>).status =
      "account-deleted";
    const wrapper = mount(MainLayout, { slots: { default: "<h1>Page</h1>" } });
    expect(wrapper.get(".site-notice").attributes("role")).toBe("status");
    expect(wrapper.get(".site-notice").text()).toContain(
      "Your account has been deleted.",
    );

    await wrapper.get(".site-notice__close").trigger("click");
    expect(wrapper.find(".site-notice").exists()).toBe(false);

    (inertia.page.props.flash as Record<string, unknown>).status = undefined;
    const later = mount(MainLayout, { slots: { default: "<h1>Page</h1>" } });
    expect(later.find(".site-notice").exists()).toBe(false);
  });

  it("names the account in the phone menu instead of showing only an initial", () => {
    const wrapper = mount(MainLayout, { slots: { default: "<h1>Page</h1>" } });
    const toggle = wrapper.get(".account-toggle");

    expect(toggle.get(".account-toggle__initial").text()).toBe("N");
    expect(toggle.get(".account-toggle__name").text()).toBe("Nadia Lestari");
    expect(toggle.attributes("aria-label")).toContain("Nadia Lestari");
  });

  it("lets visitors call or message the spa from the footer", () => {
    const wrapper = mount(MainLayout, { slots: { default: "<h1>Page</h1>" } });
    const links = wrapper
      .findAll(".footer-contact a")
      .map((link) => link.attributes("href"));

    expect(links).toEqual([
      "tel:+62895324748530",
      "https://wa.me/62895324748530",
    ]);
    expect(wrapper.find(".footer-meta").exists()).toBe(false);
  });

  it("does not retain placeholder catalog imports or scalar service cards", () => {
    for (const name of [
      "Home.vue",
      "Services.vue",
      "Booking.vue",
      "../layouts/MainLayout.vue",
    ]) {
      expect(readPage(name)).not.toMatch(/@\/data\/(services|categories)/);
      expect(readPage(name)).not.toMatch(
        /Pijat Tradisional Jawa|ServiceCard|ServiceCategoryData/,
      );
    }
  });

  it("keeps browser-only storage, router, and legacy stores out of Laravel runtime", () => {
    const source = [
      "Home.vue",
      "Services.vue",
      "Booking.vue",
      "bookings/Index.vue",
      "bookings/Show.vue",
      "Profile.vue",
      "../layouts/MainLayout.vue",
      "../components/PackageConfigurator.vue",
      "../components/PackageMenuCard.vue",
    ]
      .map(readPage)
      .join("\n");

    expect(source).not.toMatch(
      /localStorage|sessionStorage|vue-router|useAuth|bookingHistory/,
    );
  });

  it("keeps guest booking validation without browser persistence or auth storage", () => {
    const source = readPage("Booking.vue");

    expect(source).toMatch(/validateBooking/);
    expect(source).toMatch(/timeSlots/);
    // Past days are kept unpickable by the shared date and time picker.
    expect(source).toMatch(/DateTimePicker/);
    expect(
      readFileSync(
        resolve(pageRoot, "../components/booking/DateTimePicker.vue"),
        "utf8",
      ),
    ).toMatch(/todayIso/);
    expect(source).toMatch(/novalidate/);
    expect(source).not.toMatch(
      /localStorage|bookingHistory|createBookingStore|useAuth/,
    );
  });

  it("shows the safe server-shared profile identity with no booking history storage", () => {
    const source = readPage("Profile.vue");

    expect(source).toMatch(/usePage<AppPageProps>/);
    expect(source).toMatch(/props\.auth\.user/);
    expect(source).toMatch(/profile\.email/);
    expect(source).toMatch(/profile\.phone/);
    expect(source).not.toMatch(
      /localStorage|bookingHistory|createBookingStore|useAuth/,
    );
  });

  it("names both logo links with the words they show", () => {
    const brands = mount(MainLayout, {
      slots: { default: "<p>content</p>" },
    }).findAll("a.brand");

    expect(brands).toHaveLength(2);
    for (const brand of brands) {
      // Punctuation and case are ignored when matching a name to the visible text.
      const words = brand
        .text()
        .replace(/[^\p{L}\p{N}\s]/gu, " ")
        .replace(/\s+/g, " ")
        .trim()
        .toLowerCase();
      expect(words).toBe("shin joy spa malang");
      expect(brand.attributes("aria-label")?.toLowerCase()).toBe(words);
    }
  });

  it("reads the scroll position only after the first frame, not while mounting", async () => {
    // Reading it during mount forces the browser to lay the whole page out early.
    const original = Object.getOwnPropertyDescriptor(window, "scrollY");
    let reads = 0;
    Object.defineProperty(window, "scrollY", {
      configurable: true,
      get: () => {
        reads += 1;
        return 0;
      },
    });

    try {
      mount(MainLayout, { slots: { default: "<p>content</p>" } });
      expect(reads).toBe(0);
      await new Promise((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(resolve)),
      );
      expect(reads).toBeGreaterThan(0);
    } finally {
      if (original) Object.defineProperty(window, "scrollY", original);
    }
  });

  it("uses second-level footer headings, so no page skips a heading level", () => {
    const wrapper = mount(MainLayout, {
      slots: { default: "<h1>Page</h1><h2>Last section</h2>" },
    });

    expect(wrapper.findAll(".site-footer h3, .site-footer h4")).toHaveLength(0);
    expect(wrapper.findAll(".footer-col h2").map((h) => h.text())).toEqual([
      "Explore",
      "Services",
      "Contact",
    ]);
  });

  it("shows the administrator path only from the server-shared permission", async () => {
    const customer = mount(MainLayout, {
      slots: { default: "<p>content</p>" },
    });
    await customer.get(".account-toggle").trigger("click");
    expect(customer.find('a[href="/admin/bookings"]').exists()).toBe(false);

    inertia.page.props.auth.canAccessAdmin = true;
    const administrator = mount(MainLayout, {
      slots: { default: "<p>content</p>" },
    });
    await administrator.get(".account-toggle").trigger("click");
    expect(administrator.get('a[href="/admin/bookings"]').text()).toBe(
      "Admin Panel",
    );

    const layout = readFileSync(
      resolve(resourceRoot, "layouts/MainLayout.vue"),
      "utf8",
    );
    expect(layout).not.toMatch(/admin-login|role\s*===|email.*admin/i);
  });
});
