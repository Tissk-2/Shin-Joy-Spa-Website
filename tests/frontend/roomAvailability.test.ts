import { mount, flushPromises } from "@vue/test-utils";
import { defineComponent, h, ref, nextTick } from "vue";
import { afterEach, describe, expect, it, vi } from "vitest";
import RoomAvailability from "@/components/booking/RoomAvailability.vue";
import { useMonthAvailability } from "@/composables/useMonthAvailability";
import { useRoomAvailability } from "@/composables/useRoomAvailability";
import {
  jakartaAppointment,
  monthAvailabilityQueryKey,
  parseAvailability,
  parseMonthAvailability,
  slotTimeStates,
} from "@/lib/roomAvailability";

const selection = {
  service: "nezuko",
  mode: "regular",
  duration: 90,
  extension: "",
  date: "2099-09-06",
  guests: 1,
};
const result = {
  generated_at: "2099-09-05T18:00:00Z",
  slots: [
    {
      start_at: "2099-09-06T02:00:00Z",
      rooms: [
        {
          code: "A",
          name: "Quiet room",
          type: null,
          capacity: 2,
          available: true,
        },
      ],
    },
  ],
};
const response = (data: unknown = result, status = 200) =>
  ({ ok: status === 200, status, json: async () => data }) as Response;
function deferred<T>() {
  let resolve!: (v: T) => void;
  let reject!: (e: unknown) => void;
  const promise = new Promise<T>((r, j) => {
    resolve = r;
    reject = j;
  });
  return { promise, resolve, reject };
}
function setup(enabledValue?: unknown) {
  const enabled = ref(arguments.length ? enabledValue : true);
  const query = ref({ ...selection });
  let api!: ReturnType<typeof useRoomAvailability>;
  const wrapper = mount(
    defineComponent({
      setup() {
        api = useRoomAvailability(enabled, query);
        return () => h("div");
      },
    }),
  );
  return { api, wrapper, enabled, query };
}
afterEach(() => vi.unstubAllGlobals());
describe("room availability", () => {
  it("sends only the public current selection and interprets UTC as Jakarta", async () => {
    const fetcher = vi.fn(async () => response());
    vi.stubGlobal("fetch", fetcher);
    const { api, wrapper } = setup();
    await flushPromises();
    expect(fetcher.mock.calls[0]?.[0]).toBe(
      "/booking/availability?service=nezuko&mode=regular&duration=90&date=2099-09-06&guests=1",
    );
    expect(api.slots.value).toEqual(result.slots);
    expect(api.generatedAt.value).toBe(result.generated_at);
    expect(jakartaAppointment("2099-09-05T18:30:00Z")).toEqual({
      date: "2099-09-06",
      time: "01:30",
    });
    expect(jakartaAppointment("2099-09-06T02:00:00Z")).toEqual({
      date: "2099-09-06",
      time: "09:00",
    });
    wrapper.unmount();
  });
  it.each([undefined, false, "true", 1])(
    "requires strict true and complete query (%s)",
    async (value) => {
      const fetcher = vi.fn(async () => response());
      vi.stubGlobal("fetch", fetcher);
      const { api, wrapper, enabled, query } = setup(value);
      await flushPromises();
      expect(fetcher).not.toHaveBeenCalled();
      expect(api.status.value).toBe("disabled");
      query.value = { ...selection, date: "" };
      enabled.value = true;
      await nextTick();
      expect(api.status.value).toBe("incomplete");
      expect(fetcher).not.toHaveBeenCalled();
      wrapper.unmount();
    },
  );
  it("invalidates synchronously, aborts and ignores old success/error/finally including unmount", async () => {
    const first = deferred<Response>();
    const second = deferred<Response>();
    const third = deferred<Response>();
    const fetcher = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise)
      .mockReturnValueOnce(third.promise);
    vi.stubGlobal("fetch", fetcher);
    const { api, wrapper, query } = setup();
    query.value = { ...selection, guests: 2 };
    expect(api.slots.value).toEqual([]);
    expect(fetcher.mock.calls[0][1].signal.aborted).toBe(true);
    first.reject(new Error("old private error"));
    await flushPromises();
    expect(api.loading.value).toBe(true);
    expect(api.error.value).toBeNull();
    second.resolve(response());
    await flushPromises();
    expect(api.slots.value).toHaveLength(1);
    const refreshing = api.refresh();
    expect(api.slots.value).toEqual([]);
    expect(api.generatedAt.value).toBeNull();
    wrapper.unmount();
    expect(fetcher.mock.calls[2][1].signal.aborted).toBe(true);
    third.resolve(response());
    await refreshing;
    expect(api.slots.value).toEqual([]);
  });
  it("ignores an old success while a new selection is loading and when disabled", async () => {
    const old = deferred<Response>();
    const latest = deferred<Response>();
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockReturnValueOnce(old.promise)
        .mockReturnValueOnce(latest.promise),
    );
    const { api, query, enabled, wrapper } = setup();
    query.value = { ...selection, extension: "extra" };
    old.resolve(response());
    await flushPromises();
    expect(api.slots.value).toEqual([]);
    expect(api.loading.value).toBe(true);
    enabled.value = false;
    latest.resolve(response());
    await flushPromises();
    expect(api.status.value).toBe("disabled");
    expect(api.slots.value).toEqual([]);
    wrapper.unmount();
  });
  it.each([
    [401, "auth"],
    [403, "auth"],
    [419, "auth"],
    [422, "invalid"],
    [429, "rate-limit"],
    [503, "unavailable"],
  ])("maps HTTP %s to safe state", async (code, state) => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => response({}, Number(code))),
    );
    const { api, wrapper } = setup();
    await flushPromises();
    expect(api.status.value).toBe(state);
    expect(api.slots.value).toEqual([]);
    wrapper.unmount();
  });
  it.each([
    {},
    { generated_at: "bad", slots: [] },
    {
      ...result,
      slots: [
        {
          ...result.slots[0],
          rooms: [{ ...result.slots[0].rooms[0], available: "true" }],
        },
      ],
    },
    {
      ...result,
      slots: [{ ...result.slots[0], start_at: "2099-09-06T02:00:00" }],
    },
    {
      ...result,
      slots: [{ ...result.slots[0], has_waiting_requests: "true" }],
    },
    {
      ...result,
      slots: [{ ...result.slots[0], waiting_requests_count: -1 }],
    },
    {
      ...result,
      slots: [{ ...result.slots[0], waiting_requests_count: 1.5 }],
    },
    {
      ...result,
      slots: [{ ...result.slots[0], waiting_requests_count: "2" }],
    },
  ])(
    "rejects malformed availability rather than authorizing times",
    async (data) => {
      expect(parseAvailability(data)).toBeNull();
      vi.stubGlobal(
        "fetch",
        vi.fn(async () => response(data)),
      );
      const { api, wrapper } = setup();
      await flushPromises();
      expect(api.status.value).toBe("unavailable");
      expect(api.slots.value).toEqual([]);
      wrapper.unmount();
    },
  );
  it("handles invalid JSON/network failure and explicit successful recovery without retries", async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => {
          throw new Error("secret");
        },
      })
      .mockRejectedValueOnce(new Error("network"))
      .mockResolvedValueOnce(response());
    vi.stubGlobal("fetch", fetcher);
    const { api, wrapper } = setup();
    await flushPromises();
    expect(api.status.value).toBe("unavailable");
    await api.refresh();
    expect(api.status.value).toBe("unavailable");
    await api.refresh();
    expect(api.status.value).toBe("success");
    expect(fetcher).toHaveBeenCalledTimes(3);
    wrapper.unmount();
  });
});

describe("room availability panel", () => {
  const slot = {
    start_at: "2099-09-06T02:00:00Z",
    rooms: [
      { code: "A", name: "Room A", type: null, capacity: 2, available: true },
    ],
  };

  it("asks for the missing choices with no refresh button or caveat yet", () => {
    const wrapper = mount(RoomAvailability, {
      props: {
        language: "en",
        status: "incomplete",
        slots: [],
        selectedTime: "",
        generatedAt: null,
      },
    });

    expect(wrapper.text()).toContain("Choose treatment");
    expect(wrapper.find("[data-availability-refresh]").exists()).toBe(false);
    expect(wrapper.find("#room-availability-caveat").exists()).toBe(false);
  });

  it("adds the caveat once rooms load and explains a blocked review only on review", () => {
    const props = {
      language: "en",
      status: "success",
      slots: [slot],
      selectedTime: "",
      generatedAt: "2099-09-05T12:00:00Z",
    } as const;
    const form = mount(RoomAvailability, { props });

    expect(form.text()).toContain("Choose a time");
    expect(form.get("#room-availability-caveat").text()).toContain(
      "Staff approval reserves",
    );
    expect(form.find("[data-availability-refresh]").exists()).toBe(true);
    expect(form.text()).not.toContain("select an available time again");

    const review = mount(RoomAvailability, {
      props: { ...props, reviewing: true },
    });
    expect(review.text()).toContain("select an available time again");
  });

  it("shows a plain-language summary for guests without internal room names, and keeps rooms + exact counts for staff", () => {
    const parsedGuest = parseAvailability({
      generated_at: "2099-09-05T12:00:00Z",
      slots: [{ ...slot, has_waiting_requests: true }],
    });
    expect(parsedGuest?.slots[0]).toMatchObject({
      has_waiting_requests: true,
    });
    expect(parsedGuest?.slots[0]).not.toHaveProperty("waiting_requests_count");

    const parsedStaff = parseAvailability({
      generated_at: "2099-09-05T12:00:00Z",
      slots: [
        { ...slot, has_waiting_requests: true, waiting_requests_count: 3 },
      ],
    });
    expect(parsedStaff?.slots[0]).toMatchObject({
      has_waiting_requests: true,
      waiting_requests_count: 3,
    });

    const twoRoomLast = {
      start_at: "2099-09-06T02:00:00Z",
      rooms: [
        { code: "A", name: "Room A", type: null, capacity: 2, available: true },
        {
          code: "B",
          name: "Room B",
          type: null,
          capacity: 2,
          available: false,
        },
      ],
    };

    // Guest: open (uncontested) shows plain summary and no room list.
    const guestOpen = mount(RoomAvailability, {
      props: {
        language: "id",
        status: "success",
        slots: [slot],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(guestOpen.get("[data-availability-summary]").text()).toBe(
      "Jam 09:00 WIB tersedia.",
    );
    expect(guestOpen.find(".availability-rooms").exists()).toBe(false);
    expect(guestOpen.text()).not.toContain("Room A");

    // Guest: last room uncontested.
    const guestLast = mount(RoomAvailability, {
      props: {
        language: "en",
        status: "success",
        slots: [twoRoomLast],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(guestLast.get("[data-availability-summary]").text()).toBe(
      "Only 1 room left for 09:00 WIB.",
    );

    // Guest: last room + contested (EN & ID) gives clear actionable advice without room names.
    const guestContestedLastEn = mount(RoomAvailability, {
      props: {
        language: "en",
        status: "success",
        slots: [{ ...twoRoomLast, has_waiting_requests: true }],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(guestContestedLastEn.get("[data-availability-waiting]").text()).toBe(
      "Only 1 room is left for 09:00 WIB and another guest has already requested it. You can still send your request, or pick another time to be surer.",
    );
    expect(guestContestedLastEn.find(".availability-rooms").exists()).toBe(
      false,
    );

    const guestContestedLastId = mount(RoomAvailability, {
      props: {
        language: "id",
        status: "success",
        slots: [{ ...twoRoomLast, has_waiting_requests: true }],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(guestContestedLastId.get("[data-availability-waiting]").text()).toBe(
      "Tinggal 1 ruangan di jam 09:00 WIB dan sudah ada tamu lain yang lebih dulu memesan. Anda tetap bisa mengirim pesanan, atau pilih jam lain agar lebih pasti.",
    );

    // Guest: contested when not "last room" (e.g., 1-room slot or >=2 rooms free).
    const guestContestedGeneral = mount(RoomAvailability, {
      props: {
        language: "en",
        status: "success",
        slots: [{ ...slot, has_waiting_requests: true }],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(
      guestContestedGeneral.get("[data-availability-waiting]").text(),
    ).toBe(
      "Other guests have already requested 09:00 WIB. You can still send your request, or pick another time to be surer.",
    );

    // Staff (showRooms): renders room list as text (XSS-safe) plus exact singular/plural count.
    const staffSingular = mount(RoomAvailability, {
      props: {
        language: "en",
        status: "success",
        showRooms: true,
        slots: [
          {
            start_at: "2099-09-06T02:00:00Z",
            rooms: [
              {
                code: "A",
                name: "Quiet <script> room",
                type: "Private",
                capacity: 2,
                available: true,
              },
            ],
            has_waiting_requests: true,
            waiting_requests_count: 1,
          },
        ],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(staffSingular.get("[data-availability-waiting]").text()).toBe(
      "1 pending request is waiting for confirmation at this time.",
    );
    expect(staffSingular.get(".availability-rooms").text()).toContain(
      "Quiet <script> room",
    );
    expect(staffSingular.find("script").exists()).toBe(false);

    const staffPlural = mount(RoomAvailability, {
      props: {
        language: "en",
        status: "success",
        showRooms: true,
        slots: [
          { ...slot, has_waiting_requests: true, waiting_requests_count: 3 },
        ],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(staffPlural.get("[data-availability-waiting]").text()).toBe(
      "3 pending requests are waiting for confirmation at this time.",
    );

    const staffId = mount(RoomAvailability, {
      props: {
        language: "id",
        status: "success",
        showRooms: true,
        slots: [
          { ...slot, has_waiting_requests: true, waiting_requests_count: 3 },
        ],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(staffId.get("[data-availability-waiting]").text()).toBe(
      "3 permintaan sedang menunggu konfirmasi untuk waktu ini.",
    );
    expect(staffId.get(".availability-rooms").attributes("aria-label")).toBe(
      "Ruangan",
    );

    // Uncontested (even with waiting_requests_count > 0) stays quiet.
    const quiet = mount(RoomAvailability, {
      props: {
        language: "en",
        status: "success",
        showRooms: true,
        slots: [
          { ...slot, has_waiting_requests: false, waiting_requests_count: 1 },
        ],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(quiet.find("[data-availability-waiting]").exists()).toBe(false);

    // Full slot stays quiet (the blocked/occupied messaging applies instead).
    const full = mount(RoomAvailability, {
      props: {
        language: "en",
        status: "success",
        slots: [
          {
            ...slot,
            rooms: [{ ...slot.rooms[0], available: false }],
            has_waiting_requests: true,
          },
        ],
        selectedTime: "09:00",
        generatedAt: "2099-09-05T12:00:00Z",
      },
    });
    expect(full.find("[data-availability-waiting]").exists()).toBe(false);
  });
});

// Staff review 2026-11-26: guests could not tell a time was nearly full, and full times vanished.
describe("slotTimeStates", () => {
  const room = (code: string, available: boolean) => ({
    code,
    name: code,
    type: null,
    capacity: 2,
    available,
  });
  const slot = (time: string, rooms: ReturnType<typeof room>[]) => ({
    // 15:00 WIB is 08:00 UTC.
    start_at: `2099-09-06T${String(Number(time.slice(0, 2)) - 7).padStart(2, "0")}:${time.slice(3)}:00Z`,
    rooms,
  });

  it("marks full and last-room times and keeps every time in server order", () => {
    expect(
      slotTimeStates([
        slot("15:00", [room("A", false), room("B", false)]),
        slot("15:30", [room("A", false), room("B", true)]),
        slot("16:00", [room("A", true), room("B", true)]),
        slot("16:30", [room("A", true)]),
      ]),
    ).toEqual({
      times: ["15:00", "15:30", "16:00", "16:30"],
      states: { "15:00": "full", "15:30": "last" },
    });
  });

  // A time with no suitable room at all is not "full"; the form explains that case on its own.
  it("leaves out a time that has no suitable room", () => {
    expect(
      slotTimeStates([slot("15:00", []), slot("15:30", [room("A", true)])]),
    ).toEqual({ times: ["15:30"], states: {} });
  });

  it("returns nothing for no slots", () => {
    expect(slotTimeStates([])).toEqual({ times: [], states: {} });
  });
});

describe("month availability", () => {
  const monthSelection = {
    service: "nezuko",
    mode: "regular",
    duration: 90,
    extension: "",
    month: "2099-09",
    guests: 1,
  };
  const monthResult = {
    generated_at: "2099-09-05T18:00:00Z",
    month: "2099-09",
    full_dates: ["2099-09-06", "2099-09-12"],
  };

  it("builds valid month query keys and rejects malformed months", () => {
    expect(monthAvailabilityQueryKey(monthSelection)).toBe(
      "service=nezuko&mode=regular&duration=90&month=2099-09&guests=1",
    );
    expect(
      monthAvailabilityQueryKey({ ...monthSelection, month: "2099-13" }),
    ).toBeNull();
    expect(
      monthAvailabilityQueryKey({ ...monthSelection, month: "2099-09-01" }),
    ).toBeNull();
  });

  it("parses valid month responses and rejects wrong-month or duplicate dates", () => {
    expect(parseMonthAvailability(monthResult)).toEqual(monthResult);
    expect(
      parseMonthAvailability({ ...monthResult, full_dates: ["2099-10-01"] }),
    ).toBeNull();
    expect(
      parseMonthAvailability({
        ...monthResult,
        full_dates: ["2099-09-06", "2099-09-06"],
      }),
    ).toBeNull();
    expect(
      parseMonthAvailability({ ...monthResult, full_dates: ["2099-02-29"] }),
    ).toBeNull();
  });

  it("fetches full dates, ignores stale responses, and falls back to empty on error", async () => {
    const first = deferred<Response>();
    const second = deferred<Response>();
    const fetcher = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise)
      .mockResolvedValueOnce(response({}, 503));
    vi.stubGlobal("fetch", fetcher);

    const enabled = ref<unknown>(true);
    const query = ref({ ...monthSelection });
    let api!: ReturnType<typeof useMonthAvailability>;
    const wrapper = mount(
      defineComponent({
        setup() {
          api = useMonthAvailability(enabled, query);
          return () => h("div");
        },
      }),
    );

    expect(fetcher.mock.calls[0]?.[0]).toBe(
      "/booking/availability/month?service=nezuko&mode=regular&duration=90&month=2099-09&guests=1",
    );
    query.value = { ...monthSelection, month: "2099-10" };
    first.resolve(response(monthResult));
    await flushPromises();
    expect(api.fullDates.value).toEqual([]);

    second.resolve(
      response({
        generated_at: "2099-09-05T18:00:00Z",
        month: "2099-10",
        full_dates: ["2099-10-03"],
      }),
    );
    await flushPromises();
    expect(api.fullDates.value).toEqual(["2099-10-03"]);

    await api.refresh();
    expect(api.fullDates.value).toEqual([]);
    wrapper.unmount();
  });
});
