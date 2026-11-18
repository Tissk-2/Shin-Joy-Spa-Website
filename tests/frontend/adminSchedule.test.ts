import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import Schedule from "@/pages/admin/schedule/Index.vue";
import type { AdminScheduleData } from "@/types";

const state = vi.hoisted(() => ({
  page: { props: { locale: "en", flash: {} } },
  gets: [] as Array<{ url: string; data: unknown }>,
}));
vi.mock("@inertiajs/vue3", async () => {
  const { defineComponent, h } = await import("vue");
  return {
    usePage: () => state.page,
    router: {
      post: vi.fn(),
      get: vi.fn((url: string, data: unknown) =>
        state.gets.push({ url, data }),
      ),
    },
    Link: defineComponent({
      props: { href: { type: String, required: true } },
      setup:
        (props, { slots }) =>
        () =>
          h("a", { href: props.href }, slots.default?.()),
    }),
  };
});
vi.mock("@/layouts/admin/AdminLayout.vue", () => ({
  default: {
    props: ["title", "subtitle"],
    template:
      "<main><h1>{{ title }}</h1><p data-subtitle>{{ subtitle }}</p><div data-actions><slot name='actions' /></div><slot /></main>",
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
  agenda: [
    {
      reference: "SJ-DONE",
      status: "completed",
      customerName: "Ayu",
      guests: 1,
      packageNameId: "Pijat",
      packageNameEn: "Massage",
      time: "09:00",
      durationMinutes: 60,
    },
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
  // Catches times drawn in the browser's zone, lost cleaning time, and bookings without a link.
  it("lays each room's day out on an hour scale in Jakarta time with links to bookings", () => {
    const w = mountPage();
    expect(w.get("h1").text()).toBe("Schedule");
    // ICU versions differ on the comma after the weekday.
    expect(w.get("[data-subtitle]").text()).toMatch(
      /^Tuesday,? 1 December 2026$/,
    );
    const hours = w.findAll(".admin-timetable__hour").map((h) => h.text());
    expect(hours[0]).toBe("09:00");
    expect(hours.at(-1)).toBe("21:00");

    const booking = w.get(
      '[data-schedule-room="A"] [data-schedule-item="booking"]',
    );
    const bookingText = booking.text();
    expect(bookingText).toContain("10:00");
    expect(bookingText).toContain("11:30");
    expect(bookingText).toContain("cleaning until 11:45");
    expect(bookingText).toContain("Confirmed");
    expect(bookingText).toContain("Sari");
    expect(bookingText).toContain("2 guests");
    expect(bookingText).toContain("Massage");
    expect(booking.get('a[href="/admin/bookings/SJ-ONE"]').exists()).toBe(true);
    // 10:00 to 11:45 on a 09:00 to 21:00 scale.
    expect(booking.attributes("style")).toContain("left: 8.333");
    expect(booking.attributes("style")).toContain("width: 14.58");
    expect(booking.classes()).toContain("admin-slot--confirmed");

    const block = w.get(
      '[data-schedule-room="A"] [data-schedule-item="block"]',
    );
    const blockText = block.text();
    expect(blockText).toContain("Maintenance");
    expect(blockText).toContain("Deep clean");
    expect(blockText).toContain("30 Nov");
    expect(blockText).toContain("17:00");
    expect(blockText).toContain("09:00");
    expect(blockText).not.toContain("1 Dec");
    // Begun the evening before: clipped at the left edge, not stretching the day.
    expect(block.attributes("style")).toContain("left: 0%");

    expect(
      w
        .findAll('[data-schedule-room="A"] [data-schedule-item]')[0]
        .attributes("data-schedule-item"),
    ).toBe("block");

    const inactive = w.get('[data-schedule-room="B"]');
    expect(inactive.text()).toContain("Capacity 4");
    expect(inactive.text()).toContain("Inactive");
    expect(inactive.text()).toContain("Free all day.");
    expect(inactive.classes()).toContain("admin-timetable__room--inactive");
  });

  // Catches day navigation leaving the header, a dead date field, and a lost Today marker.
  it("navigates by day from the header and opens a picked date at once", async () => {
    const w = mountPage();
    const actions = w.get("[data-actions]");

    expect(actions.get("a[data-schedule-previous]").attributes("href")).toBe(
      "/admin/schedule?date=2026-11-30",
    );
    expect(
      actions.get("a[data-schedule-previous]").attributes("aria-label"),
    ).toBe("Previous day");
    expect(actions.get("a[data-schedule-today]").attributes("href")).toBe(
      "/admin/schedule?date=2026-11-06",
    );
    expect(
      actions.get("a[data-schedule-today]").attributes("aria-current"),
    ).toBeUndefined();
    expect(actions.get("a[data-schedule-next]").attributes("href")).toBe(
      "/admin/schedule?date=2026-12-02",
    );
    const date = actions.get<HTMLInputElement>("#schedule-date");
    expect(date.attributes("name")).toBe("date");
    expect(date.element.value).toBe("2026-12-01");
    expect(w.get("label[for='schedule-date']").text()).toBe("Date");

    state.gets = [];
    await date.setValue("2026-12-24");
    expect(state.gets).toEqual([
      { url: "/admin/schedule", data: { date: "2026-12-24" } },
    ]);

    expect(
      mountPage({ date: "2026-11-06" })
        .get("a[data-schedule-today]")
        .attributes("aria-current"),
    ).toBe("date");
  });

  // Catches the scheduling-off day showing empty rooms instead of the appointments.
  it("lists the whole day as an agenda, and the closures, when room scheduling is off", () => {
    const w = mountPage({ schedulingEnabled: false });

    expect(w.get("[data-schedule-off]").text()).toContain(
      "Room scheduling is off",
    );
    expect(w.find("[data-schedule-room]").exists()).toBe(false);
    expect(w.find("[data-schedule-unassigned]").exists()).toBe(false);
    const rows = w.findAll("[data-schedule-agenda] [data-booking-row]");
    expect(rows.map((row) => row.attributes("data-booking-row"))).toEqual([
      "SJ-DONE",
      "SJ-WAIT",
    ]);
    expect(rows[0].get("time").text()).toBe("09:00");
    expect(rows[0].text()).toContain("Ayu");
    expect(rows[0].text()).toContain("60 min");
    expect(rows[0].get(".admin-status").text()).toBe("Completed");
    expect(rows[0].get("a").attributes("href")).toBe("/admin/bookings/SJ-DONE");
    expect(w.get("[data-schedule-agenda] h2").text()).toContain("2");

    const closures = w.get("[data-schedule-closures]");
    expect(closures.text()).toContain("A · Room A");
    expect(closures.text()).toContain("Deep clean");

    const empty = mountPage({ schedulingEnabled: false, agenda: [] });
    expect(empty.get("[data-schedule-agenda]").text()).toContain(
      "No appointments on this day.",
    );
    expect(
      mountPage({
        schedulingEnabled: false,
        rooms: [{ ...schedule.rooms[1] }],
      })
        .find("[data-schedule-closures]")
        .exists(),
    ).toBe(false);
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
      expect(w.get("h1").text()).toBe("Jadwal");
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
