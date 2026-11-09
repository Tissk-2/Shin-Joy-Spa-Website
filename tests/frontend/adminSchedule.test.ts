import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import Schedule from "@/pages/admin/schedule/Index.vue";
import type { AdminScheduleData } from "@/types";

const state = vi.hoisted(() => ({
  page: { props: { locale: "en", flash: {} } },
}));
vi.mock("@inertiajs/vue3", () => ({
  usePage: () => state.page,
  router: { post: vi.fn() },
}));
vi.mock("@/layouts/admin/AdminLayout.vue", () => ({
  default: {
    props: ["title"],
    template: "<main><h1>{{ title }}</h1><slot /></main>",
  },
}));

const schedule = {
  date: "2026-12-01",
  previousDate: "2026-11-30",
  nextDate: "2026-12-02",
  today: "2026-11-06",
  schedulingEnabled: true,
  rooms: [
    {
      id: 1,
      code: "A",
      name: "Room A",
      capacity: 2,
      isActive: true,
      items: [
        {
          kind: "block",
          id: 9,
          reason: "Deep clean",
          startsAt: "2026-11-30T10:00:00Z",
          endsAt: "2026-12-01T02:00:00Z",
        },
        {
          kind: "booking",
          reference: "SJ-ONE",
          status: "confirmed",
          customerName: "Sari",
          guests: 2,
          packageNameId: "Pijat",
          packageNameEn: "Massage",
          startsAt: "2026-12-01T03:00:00Z",
          treatmentEndsAt: "2026-12-01T04:30:00Z",
          endsAt: "2026-12-01T04:45:00Z",
        },
      ],
    },
    {
      id: 2,
      code: "B",
      name: "Room B",
      capacity: 4,
      isActive: false,
      items: [],
    },
  ],
  unassigned: [
    {
      reference: "SJ-WAIT",
      status: "pending",
      customerName: "Budi",
      guests: 1,
      packageNameId: "Pijat",
      packageNameEn: "Massage",
      time: "14:00",
      durationMinutes: 90,
    },
  ],
} satisfies AdminScheduleData;

function mountPage(overrides: Partial<AdminScheduleData> = {}) {
  return mount(Schedule, {
    props: { schedule: { ...schedule, ...overrides } },
  });
}

describe("room schedule", () => {
  it("lists each room's day in Jakarta time with links to bookings", () => {
    const w = mountPage();
    const booking = w.get(
      '[data-schedule-room="A"] [data-schedule-item="booking"]',
    );
    const bookingText = booking.text();

    expect(bookingText).toContain("10:00");
    expect(bookingText).toContain("11:30");
    expect(bookingText).toContain("cleaning until 11:45");
    expect(bookingText).toContain("Confirmed");
    expect(bookingText).toContain("Sari");
    expect(bookingText).toContain("Guests: 2");
    expect(bookingText).toContain("Massage");
    expect(booking.get('a[href="/admin/bookings/SJ-ONE"]').exists()).toBe(true);

    const blockText = w
      .get('[data-schedule-room="A"] [data-schedule-item="block"]')
      .text();
    expect(blockText).toContain("Maintenance");
    expect(blockText).toContain("Deep clean");
    expect(blockText).toContain("30 Nov");
    expect(blockText).toContain("17:00");
    expect(blockText).toContain("09:00");
    expect(blockText).not.toContain("1 Dec");

    expect(
      w
        .findAll('[data-schedule-room="A"] [data-schedule-item]')[0]
        .attributes("data-schedule-item"),
    ).toBe("block");

    const inactive = w.get('[data-schedule-room="B"]').text();
    expect(inactive).toContain("Capacity 4");
    expect(inactive).toContain("Inactive");
    expect(inactive).toContain("Free all day.");
  });

  it("navigates by day and explains when scheduling is off", () => {
    const w = mountPage();

    expect(w.get("a[data-schedule-previous]").attributes("href")).toBe(
      "/admin/schedule?date=2026-11-30",
    );
    expect(w.get("a[data-schedule-today]").attributes("href")).toBe(
      "/admin/schedule?date=2026-11-06",
    );
    expect(w.get("a[data-schedule-next]").attributes("href")).toBe(
      "/admin/schedule?date=2026-12-02",
    );
    expect(w.get("form").attributes("action")).toBe("/admin/schedule");
    expect(w.get("form").attributes("method")).toBe("get");
    expect(w.get("#schedule-date").attributes("name")).toBe("date");
    expect((w.get("#schedule-date").element as HTMLInputElement).value).toBe(
      "2026-12-01",
    );

    expect(w.find("[data-schedule-off]").exists()).toBe(false);
    const off = mountPage({ schedulingEnabled: false });
    expect(off.get("[data-schedule-off]").text()).toContain(
      "Room scheduling is off",
    );
  });

  it("lists bookings that hold no room", () => {
    const w = mountPage();
    const unassigned = w.get("[data-schedule-unassigned]");

    expect(unassigned.text()).toContain("14:00");
    expect(unassigned.text()).toContain("Budi");
    expect(unassigned.text()).toContain("Pending");
    expect(unassigned.get('a[href="/admin/bookings/SJ-WAIT"]').exists()).toBe(
      true,
    );

    const empty = mountPage({ unassigned: [] });
    expect(empty.get("[data-schedule-unassigned]").text()).toContain("None.");
  });

  it("shows an empty state without rooms", () => {
    const w = mountPage({ rooms: [] });

    expect(w.get(".admin-empty").text()).toBe("No rooms yet.");
    expect(w.find("[data-schedule-room]").exists()).toBe(false);
  });

  // Review fix: the no-room list printed the raw 14:00 beside id-ID 10.00 times.
  it("formats every time the same way in Indonesian", () => {
    state.page.props.locale = "id";
    try {
      const w = mountPage();
      expect(
        w.get('[data-schedule-room="A"] [data-schedule-item="booking"]').text(),
      ).toContain("10.00");
      const unassigned = w.get("[data-schedule-unassigned]").text();
      expect(unassigned).toContain("14.00");
      expect(unassigned).not.toContain("14:00");
    } finally {
      state.page.props.locale = "en";
    }
  });
});
