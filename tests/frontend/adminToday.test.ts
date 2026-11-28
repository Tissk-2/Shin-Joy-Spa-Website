import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { nextTick } from "vue";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import Dashboard from "@/pages/admin/Dashboard.vue";
import type { AdminBookingSummaryData, BookingStatus, Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/admin",
    props: {
      auth: {
        user: {
          id: 1,
          name: "Owner",
          email: "owner@shinjoy.test",
          phone: "0812",
          email_verified_at: "2026-09-01",
        },
        canAccessAdmin: true,
        isOwner: true,
      },
      locale: "en" as Locale,
      flash: {} as { status?: string },
      errors: {},
      catalog: { packages: [] },
      adminNav: { pendingBookings: 3 } as { pendingBookings: number } | null,
    },
  },
  patches: [] as Array<{ url: string; data: unknown }>,
  options: {} as Record<string, (...args: any[]) => unknown>,
}));

vi.mock("@inertiajs/vue3", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@inertiajs/vue3")>();
  const { reactive, defineComponent, h } = await import("vue");
  inertia.page = reactive(inertia.page);
  vi.spyOn(actual.router, "patch").mockImplementation((url, data, options) => {
    inertia.patches.push({ url: String(url), data });
    inertia.options = options as typeof inertia.options;
  });
  vi.spyOn(actual.router, "post").mockImplementation((url, data) => {
    if (url === "/locale")
      inertia.page.props.locale = (data as { locale: Locale }).locale;
  });
  return {
    ...actual,
    Head: "span",
    usePage: () => inertia.page,
    Link: defineComponent({
      props: { href: String },
      setup:
        (props, { slots }) =>
        () =>
          h("a", { href: props.href }, slots.default?.()),
    }),
  };
});

function summary(
  reference: string,
  name: string,
  status: BookingStatus,
  time: string,
  requestedHoursAgo = 2,
): AdminBookingSummaryData {
  return {
    reference,
    status,
    package: { nameId: "Pijat Nezuko", nameEn: "Nezuko Massage", isVip: false },
    treatmentMode: "regular",
    appointment: { date: "2026-11-19", time, timezone: "Asia/Jakarta" },
    customer: { name, email: null, phone: "0812" },
    guests: 1,
    totalDurationMinutes: 90,
    totalPriceIdr: 265000,
    allowedTransitions:
      status === "pending" ? ["confirmed", "cancelled"] : ["completed"],
    requestedAt: new Date(
      Date.now() - requestedHoursAgo * 3600 * 1000,
    ).toISOString(),
  };
}

const queue = [
  summary("SJ-OLDEST", "Oldest Request", "pending", "09:30", 26),
  summary("SJ-NEWER", "Newer Request", "pending", "16:00", 2),
];
const today = [
  summary("SJ-MORNING", "Morning Guest", "completed", "09:00"),
  summary("SJ-NEWER", "Newer Request", "pending", "16:00"),
];
const mounted: ReturnType<typeof mount>[] = [];
function mountPage(props: Record<string, unknown> = {}) {
  const wrapper = mount(Dashboard, {
    props: {
      queue,
      queueTotal: 2,
      today,
      pricingUnverified: null,
      roomSchedulingEnabled: false,
      canCreate: true,
      ...props,
    },
    attachTo: document.body,
  });
  mounted.push(wrapper);
  return wrapper;
}
async function settle() {
  await flushPromises();
  await nextTick();
}
function dialog() {
  return new DOMWrapper(
    document.querySelector<HTMLElement>("[role='alertdialog']")!,
  );
}

beforeEach(() => {
  inertia.page.props.locale = "en";
  inertia.page.props.flash = {};
  inertia.patches = [];
  inertia.options = {};
});
afterEach(async () => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount();
  await settle();
  document.body.innerHTML = "";
  vi.clearAllMocks();
});

describe("admin Today page", () => {
  // Catches the page opening on totals instead of the work, or losing the server order.
  it("opens on the waiting requests in server order, then today's appointments by time", () => {
    const wrapper = mountPage();
    expect(wrapper.get("h1").text()).toBe("Today");
    expect(
      wrapper.get(".admin-page-heading a[data-new-booking]").attributes("href"),
    ).toBe("/admin/bookings/create");
    const queued = wrapper.findAll("[data-queue] [data-booking-row]");
    expect(queued.map((row) => row.attributes("data-booking-row"))).toEqual([
      "SJ-OLDEST",
      "SJ-NEWER",
    ]);
    expect(queued[0].text()).toContain("Requested yesterday");
    expect(queued[1].text()).toContain("Requested 2 hours ago");
    expect(queued[0].text()).toContain("1 guest");
    expect(queued[0].text()).not.toContain("1 guests");
    expect(queued[0].text()).toContain("Rp 265.000");
    expect(queued[0].get("a").attributes("href")).toBe(
      "/admin/bookings/SJ-OLDEST",
    );
    expect(wrapper.get("[data-queue] h2 .admin-tab__count").text()).toBe("2");
    const agenda = wrapper.findAll("[data-agenda] [data-booking-row]");
    expect(agenda.map((row) => row.get("time").text())).toEqual([
      "09:00",
      "16:00",
    ]);
    expect(agenda[0].get(".admin-status").text()).toBe("Completed");
    // No stat tiles, revenue or category chart.
    expect(wrapper.find(".admin-dash-stats, .admin-count-grid").exists()).toBe(
      false,
    );
  });

  // Catches a truncated queue hiding how many requests wait.
  it("links to the full queue when more requests wait than it shows", () => {
    expect(
      mountPage().find("a[href='/admin/bookings?scope=pending']").exists(),
    ).toBe(false);
    const more = mountPage({ queueTotal: 11 }).get(
      "a[href='/admin/bookings?scope=pending']",
    );
    expect(more.text()).toBe("See all 11 requests");
  });

  // Catches approving from Today skipping the confirmation or landing elsewhere afterwards.
  it("declines from the queue through the confirmation and returns to Today", async () => {
    const wrapper = mountPage();
    const decline = wrapper.get<HTMLButtonElement>(
      "[data-queue] [data-booking-row='SJ-OLDEST'] [data-answer='cancelled']",
    );
    expect(decline.text()).toBe("Decline");
    await decline.trigger("click");
    await settle();
    expect(dialog().get("h2").text()).toBe("Decline this booking?");
    expect(dialog().text()).toContain("Oldest Request");
    await dialog().get("textarea").setValue("Fully booked");
    await dialog().get("form").trigger("submit");
    expect(inertia.patches).toEqual([
      {
        url: "/admin/bookings/SJ-OLDEST/status",
        data: {
          currentStatus: "pending",
          nextStatus: "cancelled",
          reason: "Fully booked",
          returnTo: "today",
        },
      },
    ]);
  });

  // Catches empty panels rendering as blank boxes.
  it("says so when nothing waits and nothing is booked today", () => {
    const wrapper = mountPage({ queue: [], queueTotal: 0, today: [] });
    expect(wrapper.get("[data-queue]").text()).toContain(
      "No requests are waiting.",
    );
    expect(wrapper.get("[data-agenda]").text()).toContain(
      "No appointments today.",
    );
  });

  // Catches the owner-only pricing warning showing to staff, or hiding from the owner.
  it("warns about unverified pricing only when the server sends a count", async () => {
    expect(mountPage().find("[data-pricing-alert]").exists()).toBe(false);
    expect(
      mountPage({ pricingUnverified: 0 }).find("[data-pricing-alert]").exists(),
    ).toBe(false);
    const oneAlert = mountPage({ pricingUnverified: 1 }).get(
      "[data-pricing-alert]",
    );
    expect(oneAlert.text()).toContain(
      "1 package has unverified pricing, so guests can only book it for 1 person.",
    );
    const wrapper = mountPage({ pricingUnverified: 2 });
    const alert = wrapper.get("[data-pricing-alert]");
    expect(alert.text()).toContain(
      "2 packages have unverified pricing, so guests can only book them for 1 person.",
    );
    expect(alert.get("a").attributes("href")).toBe("/admin/catalog");
    await wrapper.get("button[data-admin-locale='id']").trigger("click");
    expect(wrapper.get("h1").text()).toBe("Hari ini");
    expect(wrapper.get("[data-pricing-alert]").text()).toContain(
      "Harga 2 paket belum diverifikasi",
    );
  });

  // Catches the waiting count missing from the navigation, or read out as a bare number.
  it("shows how many requests wait on the Bookings link", () => {
    const wrapper = mountPage();
    const link = wrapper.get("aside a[href='/admin/bookings']");
    expect(link.get(".admin-nav-badge").text()).toBe("3");
    expect(link.get(".admin-nav-badge").attributes("aria-hidden")).toBe("true");
    expect(link.get(".sr-only").text()).toBe(", 3 waiting for a response");
    expect(
      wrapper.get("aside a[href='/admin']").find(".admin-nav-badge").exists(),
    ).toBe(false);
  });

  // Staff review 2026-11-26: on a phone, today's schedule sat under the whole queue.
  it("marks the next three unfinished visits for phones and links the full day", () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-11-19T04:00:00Z")); // 11:00 WIB
    try {
      const wrapper = mountPage({
        today: [
          summary("SJ-0900", "Done Guest", "completed", "09:00"),
          summary("SJ-1000", "Current Guest", "confirmed", "10:00"),
          summary("SJ-1300", "Next Guest", "confirmed", "13:00"),
          summary("SJ-1400", "Later Guest", "confirmed", "14:00"),
          summary("SJ-1600", "Evening Guest", "pending", "16:00"),
        ],
      });
      expect(
        wrapper
          .findAll("[data-agenda] li.admin-agenda__later")
          .map((item) => item.attributes("data-booking-row")),
      ).toEqual(["SJ-0900", "SJ-1600"]);
      expect(
        wrapper.get("[data-agenda] a.admin-agenda__all").attributes("href"),
      ).toBe("/admin/schedule");
      expect(wrapper.find("[data-agenda] .admin-agenda__done").exists()).toBe(
        false,
      );
    } finally {
      vi.useRealTimers();
    }
  });

  it("says when no visits are left today", () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-11-19T15:00:00Z")); // 22:00 WIB
    try {
      const wrapper = mountPage();
      expect(wrapper.get("[data-agenda] .admin-agenda__done").text()).toBe(
        "No more appointments today.",
      );
      expect(
        wrapper.findAll("[data-agenda] li.admin-agenda__later"),
      ).toHaveLength(2);
    } finally {
      vi.useRealTimers();
    }
  });
});
