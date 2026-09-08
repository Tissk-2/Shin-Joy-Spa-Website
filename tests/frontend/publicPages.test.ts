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
      "ServiceDetail.vue",
      "Booking.vue",
      "bookings/Index.vue",
      "bookings/Show.vue",
      "Profile.vue",
    ]) {
      const source = readPage(name);

      expect(source).toMatch(/MainLayout/);
      expect(source).toMatch(/<Head/);
      expect(source).toMatch(/<Link/);
      expect(source).not.toMatch(/vue-router|RouterLink|useRoute/);
    }
  });

  it("preserves the bilingual home sections and accessible lightbox controls", () => {
    const source = readPage("Home.vue");

    expect(source).toMatch(/useLanguage/);
    expect(source).toMatch(/id="menu"/);
    expect(source).toMatch(/id="about"/);
    expect(source).toMatch(/id="gallery"/);
    expect(source).toMatch(/id="contact"/);
    expect(source).toMatch(/role="dialog"/);
    expect(source).toMatch(/aria-modal="true"/);
    expect(source).toMatch(/handleLightboxKeydown/);
  });

  it("does not retain placeholder catalog imports or scalar service cards", () => {
    for (const name of [
      "Home.vue",
      "ServiceDetail.vue",
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
      "ServiceDetail.vue",
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
    expect(source).toMatch(/todayIso/);
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
