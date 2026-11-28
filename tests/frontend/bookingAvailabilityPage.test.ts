import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import Booking from "@/pages/Booking.vue";
import type { PackageData } from "@/types";

interface FormDouble {
  errors: Record<string, string>;
  processing: boolean;
  post: ReturnType<typeof vi.fn>;
  transform: ReturnType<typeof vi.fn>;
  lastPayload: Record<string, unknown>;
  [key: string]: unknown;
}

const inertia = vi.hoisted(() => ({
  forms: [] as FormDouble[],
  page: {
    url: "/booking",
    props: {
      auth: {
        user: {
          id: 7,
          name: "Nadia",
          phone: "+62 812-3456-7890",
          email: "nadia@example.test",
          email_verified_at: "2026-08-01",
        },
        canAccessAdmin: false,
      },
      locale: "en",
      flash: {},
      catalog: { packages: [] },
    },
  },
  posts: [] as Array<{ url: string; data: Record<string, unknown> }>,
  holdProcessing: false,
  nextPostError: null as Record<string, string> | null,
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
    useForm: (initial: Record<string, unknown>) => {
      const form = reactive({
        ...initial,
        errors: {},
        processing: false,
        lastPayload: { ...initial },
        wasTransformed: false,
        transform: vi.fn(),
        post: vi.fn(),
      }) as FormDouble & { wasTransformed: boolean };

      const currentPayload = () =>
        form.wasTransformed
          ? form.lastPayload
          : Object.fromEntries(
              Object.keys(initial).map((key) => [key, form[key]]),
            );

      form.transform.mockImplementation(
        (
          callback: (data: Record<string, unknown>) => Record<string, unknown>,
        ) => {
          form.lastPayload = callback(
            Object.fromEntries(
              Object.keys(initial).map((key) => [key, form[key]]),
            ),
          );
          form.wasTransformed = true;
          return form;
        },
      );

      form.post.mockImplementation(
        (
          url: string,
          options?: {
            onError?: (errors: Record<string, string>) => void;
            onFinish?: () => void;
          },
        ) => {
          form.processing = true;
          inertia.posts.push({ url, data: currentPayload() });

          if (inertia.nextPostError) {
            form.errors = inertia.nextPostError;
            options?.onError?.(inertia.nextPostError);
            inertia.nextPostError = null;
          }

          if (!inertia.holdProcessing) {
            form.processing = false;
            options?.onFinish?.();
          }
        },
      );

      inertia.forms.push(form);
      return form;
    },
  };
});

const packages: PackageData[] = [
  {
    slug: "nezuko",
    nameId: "Nezuko",
    nameEn: "Nezuko",
    isVip: false,
    descId: "",
    descEn: "",
    longId: "",
    longEn: "",
    benefitId: null,
    benefitEn: null,
    image: "",
    gallery: [],
    variants: [
      {
        code: "nezuko-regular-90",
        mode: "regular",
        duration: 90,
        price: 265000,
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
  },
];

const selection = {
  service: "nezuko",
  mode: "regular" as const,
  duration: 90,
  extension: "nezuko-regular-extra-60",
};

const submissionKey = "018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10";

const data = {
  generated_at: "2099-09-05T12:00:00Z",
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
        {
          code: "B",
          name: "Occupied room",
          type: null,
          capacity: 4,
          available: false,
        },
      ],
    },
  ],
};
const ok = (value: unknown = data) =>
  ({ ok: true, status: 200, json: async () => value }) as Response;
function mountPage(enabled?: unknown) {
  return mount(Booking, {
    props: {
      packages,
      selection,
      submissionKey,
      ...(enabled === undefined
        ? {}
        : { availabilityEnabled: enabled as boolean }),
    },
  });
}
async function details(wrapper: ReturnType<typeof mountPage>) {
  await wrapper.get("#f-date").setValue("2099-09-06");
  await flushPromises();
  await wrapper.get('#f-time input[value="09:00"]').setValue(true);
}
beforeEach(() => {
  inertia.forms = [];
  inertia.posts = [];
  inertia.page.props.locale = "en";
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => ok()),
  );
  // The flows book 6 September 2099, which must fall inside the booking window.
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2099-09-01T00:00:00Z"));
});
afterEach(() => vi.useRealTimers());
describe("booking page availability integration", () => {
  it("localizes the guest summary and explains empty starts in plain English", async () => {
    const wrapper = mountPage(true);
    await details(wrapper);
    inertia.page.props.locale = "id";
    await wrapper.vm.$nextTick();
    expect(wrapper.get("[data-availability-summary]").text()).toBe(
      "Tinggal 1 ruangan kosong untuk jam 09:00 WIB.",
    );
    expect(wrapper.find(".availability-rooms").exists()).toBe(false);
    inertia.page.props.locale = "en";
    vi.mocked(fetch).mockResolvedValueOnce(ok({ ...data, slots: [] }));
    await wrapper.get("[data-availability-refresh]").trigger("click");
    await flushPromises();
    expect(wrapper.text()).toContain(
      "No start times are available for this date and treatment.",
    );
    wrapper.unmount();
  });
  it.each([undefined, false, "true"])(
    "keeps legacy time options and makes zero requests unless strict true (%s)",
    async (enabled) => {
      const wrapper = mountPage(enabled);
      await details(wrapper);
      expect(fetch).not.toHaveBeenCalled();
      expect(wrapper.get("#f-time").text()).toContain("09:00");
      expect(wrapper.find("[data-room-availability]").exists()).toBe(false);
      wrapper.unmount();
    },
  );
  it("replaces legacy times, hides internal room names from guests, clears synchronously on guests/treatment changes", async () => {
    const wrapper = mountPage(true);
    expect(wrapper.findAll('#f-time input[type="radio"]')).toHaveLength(0);
    await details(wrapper);
    expect(wrapper.get("[data-room-availability]").text()).toContain(
      "Only 1 room left for 09:00 WIB.",
    );
    expect(wrapper.get("[data-room-availability]").text()).not.toContain(
      "Occupied room",
    );
    expect(wrapper.find("script").exists()).toBe(false);
    expect(wrapper.text()).toContain("Staff approval reserves");
    expect(
      wrapper.findAll('input[name="room"],select[name="room"]'),
    ).toHaveLength(0);
    inertia.forms[0].guests = 2;
    expect(inertia.forms[0].time).toBe("");
    await flushPromises();
    expect(String(vi.mocked(fetch).mock.calls.at(-1)?.[0])).toContain(
      "guests=2",
    );
    inertia.forms[0].duration = 60;
    expect(inertia.forms[0].time).toBe("");
    await flushPromises();
    expect(wrapper.findAll('#f-time input[type="radio"]')).toHaveLength(0);
    wrapper.unmount();
  });
  it("uses latest usable data at review and confirmation and preserves exact existing payload", async () => {
    const wrapper = mountPage(true);
    await details(wrapper);
    await wrapper.get("form").trigger("submit");
    expect(wrapper.find("[data-booking-confirm]").exists()).toBe(true);
    await wrapper.get("[data-booking-confirm]").trigger("click");
    expect(inertia.posts).toHaveLength(1);
    expect(Object.keys(inertia.posts[0].data).sort()).toEqual(
      [
        "name",
        "phone",
        "service",
        "mode",
        "duration",
        "extension",
        "date",
        "time",
        "guests",
        "notes",
        "submission_key",
        "expected_total_price_idr",
      ].sort(),
    );
    expect(inertia.posts[0].data.time).toBe("09:00");
    wrapper.unmount();
  });
  it("refresh invalidates review, disables confirmation during loading/error and retains the draft", async () => {
    const wrapper = mountPage(true);
    await details(wrapper);
    await wrapper.get("#f-notes").setValue("Retain my draft");
    await wrapper.get("form").trigger("submit");
    let release!: (value: Response) => void;
    vi.mocked(fetch).mockImplementationOnce(
      () =>
        new Promise((r) => {
          release = r;
        }),
    );
    await wrapper.get("[data-availability-refresh]").trigger("click");
    expect(
      wrapper.get("[data-booking-confirm]").attributes("disabled"),
    ).toBeDefined();
    release(
      ok({
        ...data,
        slots: [
          {
            ...data.slots[0],
            rooms: data.slots[0].rooms.map((r) => ({ ...r, available: false })),
          },
        ],
      }),
    );
    await flushPromises();
    await wrapper.get("[data-booking-confirm]").trigger("click");
    expect(inertia.posts).toHaveLength(0);
    expect(wrapper.text()).toContain("fully occupied");
    await wrapper.get("[data-booking-edit]").trigger("click");
    expect(wrapper.get<HTMLTextAreaElement>("#f-notes").element.value).toBe(
      "Retain my draft",
    );
    expect(wrapper.find("#f-time input:checked").exists()).toBe(false);
    wrapper.unmount();
  });
  it("asks only for the choice still missing before showing rooms", async () => {
    const wrapper = mountPage(true);
    const status = () => wrapper.get("#room-availability-status").text();
    expect(status()).toBe("Choose a date to see availability.");
    inertia.forms[0].duration = null;
    await flushPromises();
    expect(status()).toBe(
      "Choose a treatment and its length to see availability.",
    );
    inertia.page.props.locale = "id";
    await flushPromises();
    expect(status()).toBe(
      "Pilih perawatan dan durasinya untuk melihat ketersediaan.",
    );
    inertia.forms[0].duration = 90;
    await flushPromises();
    expect(status()).toBe("Pilih tanggal untuk melihat ketersediaan.");
    wrapper.unmount();
  });
  it("shows localized incomplete/empty/incompatible/rate limit/auth/error and explicit recovery", async () => {
    const wrapper = mountPage(true);
    expect(wrapper.text()).toContain("Choose a date");
    inertia.page.props.locale = "id";
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("Pilih tanggal");
    vi.mocked(fetch).mockResolvedValueOnce(ok({ ...data, slots: [] }));
    await wrapper.get("#f-date").setValue("2099-09-06");
    await flushPromises();
    expect(wrapper.text()).toContain("Tidak ada waktu mulai");
    vi.mocked(fetch).mockResolvedValueOnce(
      ok({ ...data, slots: [{ ...data.slots[0], rooms: [] }] }),
    );
    await wrapper.get("[data-availability-refresh]").trigger("click");
    await flushPromises();
    expect(wrapper.text()).toContain("Tidak ada ruangan yang sesuai");
    vi.mocked(fetch).mockResolvedValueOnce({
      ok: false,
      status: 429,
    } as Response);
    await wrapper.get("[data-availability-refresh]").trigger("click");
    await flushPromises();
    expect(wrapper.text()).toContain("Terlalu banyak");
    vi.mocked(fetch).mockResolvedValueOnce({
      ok: false,
      status: 401,
    } as Response);
    await wrapper.get("[data-availability-refresh]").trigger("click");
    await flushPromises();
    expect(wrapper.text()).toContain("Sesi Anda");
    vi.mocked(fetch).mockResolvedValueOnce(ok({}));
    await wrapper.get("[data-availability-refresh]").trigger("click");
    await flushPromises();
    expect(wrapper.text()).toContain("belum dapat dimuat");
    expect(wrapper.findAll('#f-time input[type="radio"]')).toHaveLength(0);
    await wrapper.get("[data-availability-refresh]").trigger("click");
    await flushPromises();
    expect(wrapper.get("#f-time").text()).toContain("09:00");
    wrapper.unmount();
  });
  // Staff review 2026-11-26: guests could not see a time was nearly full, and full times vanished.
  describe("time labels", () => {
    const room = (code: string, available: boolean) => ({
      code,
      name: `Room ${code}`,
      type: null,
      capacity: 2,
      available,
    });
    const labelled = (fifteenThirtyFree: boolean) => ({
      ...data,
      slots: [
        {
          start_at: "2099-09-06T08:00:00Z",
          rooms: [room("A", false), room("B", false)],
        },
        {
          start_at: "2099-09-06T08:30:00Z",
          rooms: [room("A", fifteenThirtyFree), room("B", false)],
        },
        {
          start_at: "2099-09-06T09:00:00Z",
          rooms: [room("A", true), room("B", true)],
        },
      ],
    });
    const label = (wrapper: ReturnType<typeof mountPage>, time: string) =>
      wrapper.get(`#f-time input[value="${time}"]`).element.closest("label")!;

    it("greys out full times and labels the last room", async () => {
      vi.mocked(fetch).mockResolvedValue(ok(labelled(true)));
      const wrapper = mountPage(true);
      await wrapper.get("#f-date").setValue("2099-09-06");
      await flushPromises();
      expect(
        wrapper.get('#f-time input[value="15:00"]').attributes("disabled"),
      ).toBeDefined();
      expect(label(wrapper, "15:00").textContent).toContain("Full");
      expect(label(wrapper, "15:30").textContent).toContain("1 left");
      expect(label(wrapper, "16:00").textContent!.trim()).toBe("16:00");
      wrapper.unmount();
    });

    it("drops a chosen time that a refresh shows as full, and shows no labels while checking", async () => {
      vi.mocked(fetch).mockResolvedValue(ok(labelled(true)));
      const wrapper = mountPage(true);
      await wrapper.get("#f-date").setValue("2099-09-06");
      await flushPromises();
      await wrapper.get('#f-time input[value="15:30"]').setValue(true);
      expect(inertia.forms[0].time).toBe("15:30");

      let finish: (value: Response) => void = () => {};
      vi.mocked(fetch).mockImplementationOnce(
        () => new Promise<Response>((resolve) => (finish = resolve)),
      );
      await wrapper.get("[data-availability-refresh]").trigger("click");
      await flushPromises();
      expect(wrapper.find(".dtp__state").exists()).toBe(false);

      finish(ok(labelled(false)));
      await flushPromises();
      expect(inertia.forms[0].time).toBe("");
      expect(
        wrapper.get('#f-time input[value="15:30"]').attributes("disabled"),
      ).toBeDefined();
      wrapper.unmount();
    });

    it("leaves the times plain while room scheduling is off", async () => {
      const wrapper = mountPage(false);
      await wrapper.get("#f-date").setValue("2099-09-06");
      await flushPromises();
      expect(wrapper.findAll("#f-time input[disabled]")).toHaveLength(0);
      expect(wrapper.find(".dtp__state").exists()).toBe(false);
      wrapper.unmount();
    });

    it("shows the count-free waiting note on the form and review step when a contested time is chosen", async () => {
      vi.mocked(fetch).mockResolvedValue(
        ok({
          ...data,
          slots: [
            {
              start_at: "2099-09-06T08:30:00Z",
              rooms: [room("A", true), room("B", false)],
              has_waiting_requests: true,
            },
            {
              start_at: "2099-09-06T09:00:00Z",
              rooms: [room("A", true), room("B", true)],
              has_waiting_requests: false,
            },
          ],
        }),
      );
      const wrapper = mountPage(true);
      await wrapper.get("#f-date").setValue("2099-09-06");
      await flushPromises();
      expect(wrapper.find("[data-availability-waiting]").exists()).toBe(false);

      await wrapper.get('#f-time input[value="15:30"]').setValue(true);
      expect(wrapper.get("[data-availability-waiting]").text()).toContain(
        "Only 1 room is left for 15:30 WIB and another guest has already requested it.",
      );

      await wrapper.get("form").trigger("submit");
      expect(wrapper.get("[data-availability-waiting]").text()).toContain(
        "Only 1 room is left for 15:30 WIB and another guest has already requested it.",
      );

      await wrapper.get("[data-booking-edit]").trigger("click");
      await wrapper.get('#f-time input[value="16:00"]').setValue(true);
      expect(wrapper.find("[data-availability-waiting]").exists()).toBe(false);
      expect(wrapper.get("[data-availability-summary]").text()).toBe(
        "16:00 WIB is available.",
      );
      wrapper.unmount();
    });
  });
});
