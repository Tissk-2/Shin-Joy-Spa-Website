import { mount } from "@vue/test-utils";
import { nextTick } from "vue";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import Reports from "@/pages/admin/Reports.vue";
import type { AdminReportData, Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/admin/reports",
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
      adminNav: { pendingBookings: 1 },
    },
  },
  gets: [] as Array<{ url: string; data: unknown }>,
}));

vi.mock("@inertiajs/vue3", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@inertiajs/vue3")>();
  const { reactive, defineComponent, h } = await import("vue");
  inertia.page = reactive(inertia.page);
  vi.spyOn(actual.router, "get").mockImplementation((url, data) => {
    inertia.gets.push({ url: String(url), data });
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

function sampleReport(
  overrides: Partial<AdminReportData> = {},
): AdminReportData {
  return {
    period: "30d",
    range: { from: "2026-10-30", to: "2026-11-28" },
    previousRange: { from: "2026-09-30", to: "2026-10-29" },
    summary: {
      totalBookings: 12,
      completedCount: 6,
      confirmedCount: 2,
      pendingCount: 1,
      awaitingPaymentCount: 0,
      cancelledCount: 2,
      noShowCount: 1,
      expiredCount: 1,
      slotFullCount: 0,
      manualCancelledCount: 1,
      estimatedCompletedRevenueIdr: 1590000,
      confirmedPipelineIdr: 530000,
      completionRate: 67,
    },
    previousSummary: {
      totalBookings: 10,
      completedCount: 4,
      lostCount: 4,
      estimatedCompletedRevenueIdr: 1060000,
    },
    dailyTrend: [
      { date: "2026-11-26", total: 2, completed: 2, active: 0, lost: 0 },
      { date: "2026-11-27", total: 3, completed: 1, active: 1, lost: 1 },
      { date: "2026-11-28", total: 1, completed: 0, active: 1, lost: 0 },
    ],
    topPackages: [
      {
        slug: "nezuko",
        nameId: "Nezuko",
        nameEn: "Nezuko",
        isVip: false,
        treatmentMode: "regular",
        durationMinutes: 90,
        count: 5,
        estimatedValueIdr: 1325000,
      },
      {
        slug: "nezuko-vip",
        nameId: "Nezuko VIP",
        nameEn: "Nezuko VIP",
        isVip: true,
        treatmentMode: "regular",
        durationMinutes: 120,
        count: 4,
        estimatedValueIdr: 1440000,
      },
    ],
    peakHours: [
      { hour: "09:00", count: 1 },
      { hour: "10:00", count: 4 },
      { hour: "14:00", count: 2 },
    ],
    channels: [
      { key: "website", count: 9 },
      { key: "walk_in", count: 2 },
      { key: "phone", count: 1 },
      { key: "whatsapp", count: 0 },
    ],
    ...overrides,
  };
}

const mounted: ReturnType<typeof mount>[] = [];
function mountPage(report: AdminReportData = sampleReport()) {
  const wrapper = mount(Reports, {
    props: { report },
    attachTo: document.body,
  });
  mounted.push(wrapper);
  return wrapper;
}

beforeEach(() => {
  inertia.page.props.locale = "en";
  inertia.gets = [];
});

afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount();
  document.body.innerHTML = "";
  vi.clearAllMocks();
});

describe("admin Reports page", () => {
  it("renders honest KPI metrics, trend bars, top treatments, peak hours, outcomes, and channels", async () => {
    const wrapper = mountPage();
    expect(wrapper.get("h1").text()).toBe("Reports");
    expect(wrapper.get("[data-kpi='total']").text()).toContain("12");
    expect(wrapper.get("[data-kpi-delta='total']").text()).toBe("+20%");
    expect(wrapper.get("[data-kpi-delta='lost']").classes()).toContain(
      "admin-delta--good",
    );
    expect(wrapper.get("[data-kpi='completed']").text()).toContain("67%");
    expect(wrapper.get("[data-kpi='lost']").text()).toContain("3");
    expect(wrapper.get("[data-kpi='value']").text()).toContain("Rp 1.590.000");
    expect(wrapper.get("[data-report-peak-day]").text()).toContain("3");
    expect(wrapper.get("[data-report-peak-hour]").text()).toContain(
      "10:00 WIB",
    );
    expect(wrapper.get("[data-report-estimate-note]").text()).toContain(
      "Payment is collected at the spa",
    );

    // Real mouse order (mouseenter -> focus -> click) must pin the day rather than toggling it off.
    const trendCols = wrapper.findAll(
      "[data-report-trend] button.admin-trend__col",
    );
    expect(trendCols).toHaveLength(3);
    await trendCols[1].trigger("mouseenter");
    await trendCols[1].trigger("focus");
    await trendCols[1].trigger("click");
    expect(trendCols[1].attributes("aria-pressed")).toBe("true");
    await trendCols[1].trigger("mouseleave");
    await trendCols[1].trigger("blur");
    expect(wrapper.get("[data-report-trend] header p").text()).toContain(
      "3 bookings (1 completed, 1 active, 1 lost)",
    );
    // Clicking the pinned bar a second time unpins it.
    await trendCols[1].trigger("click");
    expect(trendCols[1].attributes("aria-pressed")).toBe("false");

    expect(wrapper.get("[data-report-packages]").text()).toContain(
      "Nezuko VIP",
    );
    expect(wrapper.get("[data-report-packages]").text()).toContain(
      "5 bookings",
    );
    expect(wrapper.get("[data-report-hours]").text()).toContain("10");
    expect(wrapper.get("[data-report-outcomes]").text()).toContain(
      "Expired (past review window)",
    );
    expect(wrapper.get("[data-report-channels]").text()).toContain(
      "Online website",
    );
    expect(wrapper.get("[data-report-channels]").text()).toContain("75%");
  });

  it("switches report period through Inertia and localizes cleanly into Indonesian", async () => {
    const wrapper = mountPage();
    await wrapper.get("[data-period='7d']").trigger("click");
    expect(inertia.gets).toEqual([
      { url: "/admin/reports", data: { period: "7d" } },
    ]);

    // Clicking the already active period does not re-fetch.
    await wrapper.get("[data-period='30d']").trigger("click");
    expect(inertia.gets).toHaveLength(1);

    await wrapper.get("button[data-admin-locale='id']").trigger("click");
    await nextTick();
    expect(wrapper.get("h1").text()).toBe("Laporan");
    expect(wrapper.get("[data-report-estimate-note]").text()).toContain(
      "Pembayaran dilakukan di spa",
    );
  });

  it("handles an empty period cleanly without NaN percentages or broken charts", () => {
    const wrapper = mountPage(
      sampleReport({
        summary: {
          totalBookings: 0,
          completedCount: 0,
          confirmedCount: 0,
          pendingCount: 0,
          awaitingPaymentCount: 0,
          cancelledCount: 0,
          noShowCount: 0,
          expiredCount: 0,
          slotFullCount: 0,
          manualCancelledCount: 0,
          estimatedCompletedRevenueIdr: 0,
          confirmedPipelineIdr: 0,
          completionRate: null,
        },
        dailyTrend: [
          { date: "2026-11-28", total: 0, completed: 0, active: 0, lost: 0 },
        ],
        topPackages: [],
        peakHours: [{ hour: "09:00", count: 0 }],
        channels: [
          { key: "website", count: 0 },
          { key: "walk_in", count: 0 },
          { key: "phone", count: 0 },
          { key: "whatsapp", count: 0 },
        ],
      }),
    );

    expect(wrapper.text()).not.toContain("NaN");
    expect(wrapper.get("[data-kpi='completed']").text()).toContain(
      "No decided bookings yet",
    );
    expect(wrapper.get("[data-report-trend]").text()).toContain(
      "No bookings in this period yet.",
    );
    expect(wrapper.get("[data-report-packages]").text()).toContain(
      "No active or completed bookings in this period.",
    );
    expect(wrapper.get("[data-report-hours]").text()).toContain(
      "No start-time data in this period.",
    );
  });
});
