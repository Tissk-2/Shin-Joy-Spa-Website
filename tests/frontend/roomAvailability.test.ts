import { mount, flushPromises } from "@vue/test-utils";
import { defineComponent, h, ref, nextTick } from "vue";
import { afterEach, describe, expect, it, vi } from "vitest";
import { useRoomAvailability } from "@/composables/useRoomAvailability";
import { jakartaAppointment, parseAvailability } from "@/lib/roomAvailability";

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
