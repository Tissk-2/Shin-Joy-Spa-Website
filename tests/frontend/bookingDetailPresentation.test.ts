import { mount, type VueWrapper } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import Show from "@/pages/bookings/Show.vue";
import type { BookingDetailData, Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/bookings/SJ-DETAIL",
    props: {
      auth: { user: null, canAccessAdmin: false },
      locale: "en" as Locale,
      flash: {},
      catalog: { packages: [] },
      content: {} as Record<string, never>,
    },
  },
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  const page = reactive(inertia.page);
  inertia.page = page;

  return {
    Head: "span",
    Link: "a",
    router: { post: vi.fn() },
    usePage: () => inertia.page,
  };
});

const detail: BookingDetailData = {
  reference: "SJ-DETAIL",
  status: "pending",
  package: {
    slug: "nezuko",
    nameId: "Nezuko Tenang",
    nameEn: "Calm Nezuko",
    isVip: false,
  },
  treatmentMode: "regular",
  appointment: {
    date: "2026-12-01",
    time: "09:00",
    timezone: "Asia/Jakarta",
  },
  guests: 2,
  totalDurationMinutes: 150,
  totalPriceIdr: 340000,
  customer: {
    name: "Nadia Lestari",
    email: "nadia@example.test",
    phone: "+62 812-3456-7890",
  },
  base: {
    choiceCode: "nezuko-regular-90",
    durationMinutes: 90,
    priceIdr: 265000,
  },
  extension: null,
  notes: null,
};

function setLocale(locale: Locale): void {
  inertia.page.props.locale = locale;
  document.documentElement.lang = locale;
}

async function switchLocale(
  wrapper: VueWrapper,
  locale: Locale,
): Promise<void> {
  setLocale(locale);
  await wrapper.vm.$nextTick();
  await nextTick();
}

describe("customer booking presentation", () => {
  beforeEach(() => {
    setLocale("en");
    document.body.innerHTML = "";
  });

  it("explains a pending request and how to change or cancel it.", () => {
    const wrapper = mount(Show, {
      props: {
        booking: {
          ...detail,
          status: "pending",
          room: null,
          paymentPreference: "at_spa",
        },
      },
    });

    const note = wrapper.get("[data-booking-status-note]").text();
    expect(note).toContain("Awaiting staff approval");
    expect(note).toContain("doesn't hold a room yet");
    // Review fix: with scheduling off, approval reserves no room, so the note must not promise one.
    expect(note).not.toContain("reserved");
    expect(wrapper.find("[data-booking-room]").exists()).toBe(false);

    const changes = wrapper.get("[data-booking-changes]").text();
    expect(changes).toContain("can't be changed online");

    const contact = wrapper.get("a[data-booking-contact]");
    expect(contact.attributes("href")).toMatch(/^https:\/\/wa\.me\//);
    expect(contact.attributes("rel")).toBe("noopener noreferrer");
    expect(contact.text()).toContain("(opens in a new tab)");
    // Review fix: one booking cannot be moved; another time is a new booking.
    expect(changes).toContain(
      "make a new booking and ask the spa to cancel this one",
    );

    expect(wrapper.find('[data-test="reschedule"]').exists()).toBe(false);
    expect(wrapper.findAll(".booking-detail button")).toHaveLength(1);
  });

  it("shows the reserved room, the fixed price and pay at the spa.", async () => {
    const wrapper = mount(Show, {
      props: {
        booking: {
          ...detail,
          status: "confirmed",
          room: "Anggrek couple suite",
          paymentPreference: "at_spa",
          pricingVerified: true,
        },
      },
    });

    const room = wrapper.get("[data-booking-room]").text();
    expect(room).toContain("Room");
    expect(room).toContain("Anggrek couple suite");
    expect(wrapper.get("[data-booking-price-fixed]").text()).toContain(
      "fixed when you sent the request",
    );
    expect(wrapper.get("[data-booking-pay-at-spa]").text()).toContain(
      "Pay at the spa",
    );
    expect(wrapper.get("[data-booking-status-note]").text()).toContain(
      "Approved by staff",
    );

    await switchLocale(wrapper, "id");

    expect(wrapper.get("[data-booking-room]").text()).toContain("Ruangan");
    expect(wrapper.get("[data-booking-pay-at-spa]").text()).toContain(
      "Bayar di spa",
    );
    expect(wrapper.get("[data-booking-status-note]").text()).toContain(
      "Disetujui staf",
    );
  });

  it("points a cancelled booking to a new booking without change options.", () => {
    const wrapper = mount(Show, {
      props: {
        booking: {
          ...detail,
          status: "cancelled",
          room: null,
          paymentPreference: "at_spa",
          pricingVerified: true,
        },
      },
    });

    expect(wrapper.get("[data-booking-status-note]").text()).toContain(
      "make a new booking",
    );
    expect(wrapper.find("[data-booking-changes]").exists()).toBe(false);
    // Review fix: a cancelled booking has nothing left to pay at the spa.
    expect(wrapper.find("[data-booking-pay-at-spa]").exists()).toBe(false);
    expect(wrapper.find('.booking-actions a[href="/booking"]').exists()).toBe(
      true,
    );
  });

  it("keeps older bookings without the new fields readable.", () => {
    const wrapper = mount(Show, {
      props: { booking: { ...detail, status: "completed" } },
    });

    expect(wrapper.find("[data-booking-status-note]").exists()).toBe(false);
    expect(wrapper.find("[data-booking-room]").exists()).toBe(false);
    expect(wrapper.find("[data-booking-pay-at-spa]").exists()).toBe(false);
    expect(wrapper.find("[data-booking-changes]").exists()).toBe(false);
    // Review fix: older rows have no verified pricing, so the total is an estimate.
    expect(wrapper.find("[data-booking-price-fixed]").exists()).toBe(false);
    expect(wrapper.find("[data-booking-price-estimate]").exists()).toBe(true);
  });

  // Review fix: unverified pricing never multiplied by guests, as the booking form warned.
  it("calls an unverified group total an estimate, not a fixed price.", async () => {
    const wrapper = mount(Show, {
      props: {
        booking: {
          ...detail,
          status: "pending",
          guests: 3,
          paymentPreference: "at_spa",
          pricingVerified: false,
        },
      },
    });

    expect(wrapper.find("[data-booking-price-fixed]").exists()).toBe(false);
    expect(wrapper.get("[data-booking-price-estimate]").text()).toContain(
      "staff confirm the total for all guests",
    );

    await switchLocale(wrapper, "id");
    expect(wrapper.get("[data-booking-price-estimate]").text()).toContain(
      "staf akan mengonfirmasi total untuk semua tamu",
    );
  });
});
