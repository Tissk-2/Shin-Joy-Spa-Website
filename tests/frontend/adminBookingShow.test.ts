import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { nextTick } from "vue";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import Show from "@/pages/admin/bookings/Show.vue";
import type { AdminBookingDetailData, Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/admin/bookings/SJ%2FAYU%20%231%3F",
    props: {
      auth: {
        user: {
          id: 4,
          name: "Rani",
          email: "rani@shinjoy.test",
          phone: "0812",
          email_verified_at: "2026-09-01",
        },
        canAccessAdmin: true,
      },
      locale: "en" as Locale,
      flash: {} as { status?: string },
      errors: {},
      catalog: { packages: [] },
    },
  },
  patches: [] as Array<{ url: string; data: unknown }>,
  options: {} as Record<string, (...args: any[]) => unknown>,
}));

// Keep Inertia's real useForm transform, processing and validation behavior.
// Only navigation, page props and the PATCH transport are test boundaries.
vi.mock("@inertiajs/vue3", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@inertiajs/vue3")>();
  const { reactive, defineComponent, h } = await import("vue");
  inertia.page = reactive(inertia.page);
  vi.spyOn(actual.router, "patch").mockImplementation((url, data, options) => {
    inertia.patches.push({ url: String(url), data });
    inertia.options = options as typeof inertia.options;
    inertia.options.onStart?.({});
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

const booking: AdminBookingDetailData = Object.freeze({
  reference: "SJ/AYU #1?",
  roomSchedulingEnabled: false,
  assignedRoom: null,
  status: "pending",
  customer: Object.freeze({
    name: "Ayu <script>alert(1)</script>",
    email: "ayu@example.test",
    phone: "+62 812-3456-7890",
  }),
  appointment: Object.freeze({
    date: "2026-10-07",
    time: "14:30",
    timezone: "Asia/Jakarta",
  }),
  package: Object.freeze({
    nameId: "Ritual Relaksasi",
    nameEn: "Relaxation Ritual",
    isVip: true,
  }),
  treatmentMode: "double_therapist",
  guests: 2,
  totalDurationMinutes: 90,
  totalPriceIdr: 450000,
  base: Object.freeze({
    choiceCode: "DT60",
    durationMinutes: 60,
    priceIdr: 350000,
  }),
  extension: Object.freeze({
    choiceCode: "DT30",
    durationMinutes: 30,
    priceIdr: 100000,
  }),
  notes: "Please use light pressure.\n<img src=x onerror=alert(1)>",
  allowedTransitions: Object.freeze(["confirmed", "cancelled"] as const),
  history: Object.freeze([
    Object.freeze({
      actor: Object.freeze({
        name: "First actor",
        email: "first@example.test",
      }),
      fromStatus: "pending" as const,
      toStatus: "confirmed" as const,
      reason: "Called guest",
      operationalConfirmedAt: null,
      createdAt: "2026-09-07T03:00:00Z",
    }),
    Object.freeze({
      actor: Object.freeze({
        name: "Second actor",
        email: "second@example.test",
      }),
      fromStatus: "confirmed" as const,
      toStatus: "completed" as const,
      reason: null,
      operationalConfirmedAt: null,
      createdAt: "2026-09-06T03:00:00Z",
    }),
  ]),
});
const backUrl = "/admin/bookings?scope=past&status=pending&q=Ayu&page=2";
const mounted: ReturnType<typeof mount>[] = [];
function mountPage(data: AdminBookingDetailData = booking) {
  const wrapper = mount(Show, {
    props: { booking: data, backUrl },
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
async function open(wrapper: ReturnType<typeof mount>, status = "confirmed") {
  const trigger = wrapper.get<HTMLButtonElement>(
    `[data-next-status='${status}']`,
  );
  trigger.element.focus();
  await trigger.trigger("click");
  await settle();
  return trigger;
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

describe("admin booking detail", () => {
  // Catches dropped snapshot fields, unsafe HTML rendering, and client-rebuilt back URLs.
  it("renders the complete immutable appointment dossier and server back URL", () => {
    const wrapper = mountPage();
    for (const value of [
      booking.reference,
      booking.customer.name,
      booking.customer.email,
      booking.customer.phone,
      "7 October 2026",
      "14:30 WIB",
      "Relaxation Ritual",
      "VIP",
      "Double therapist",
      "90 min",
      "DT60",
      "60 min",
      "Rp 350.000",
      "DT30",
      "30 min",
      "Rp 100.000",
      "Rp 450.000",
      "Please use light pressure.",
    ])
      expect(wrapper.text()).toContain(value);
    expect(wrapper.get("a[data-back-bookings]").attributes("href")).toBe(
      backUrl,
    );
    expect(wrapper.get("a[href^='mailto:']").attributes("href")).toBe(
      "mailto:ayu%40example.test",
    );
    expect(wrapper.get("a[href^='tel:']").attributes("href")).toBe(
      "tel:%2B62%20812-3456-7890",
    );
    expect(wrapper.find("main script, main img").exists()).toBe(false);
    expect(wrapper.get("[data-booking-guests]").text()).toBe("2");
  });

  // Catches dead tel: links and blank snapshot rows when the WhatsApp number is optional.
  it("renders the dossier without a tel link when no WhatsApp number was provided", () => {
    const wrapper = mountPage({
      ...booking,
      customer: { ...booking.customer, phone: "" },
    });
    expect(wrapper.find("a[href^='tel:']").exists()).toBe(false);
    expect(wrapper.text()).toContain("No WhatsApp number was added.");
  });

  // Catches client history sorting and blank audit reasons.
  it("keeps server history order, actor snapshots and explicit empty reasons", () => {
    const items = mountPage().findAll(".admin-history-item");
    expect(items).toHaveLength(2);
    expect(items[0].text()).toContain("First actor");
    expect(items[0].text()).toContain("first@example.test");
    expect(items[0].text()).toContain("Called guest");
    expect(items[0].text()).toContain("7 September 2026");
    expect(items[1].text()).toContain("Second actor");
    expect(items[1].text()).toContain("No reason was recorded.");
  });

  // Catches empty slots and invented status actions for terminal records.
  it.each(["completed", "cancelled"] as const)(
    "renders a %s dossier without a status form",
    (status) => {
      const wrapper = mountPage({
        ...booking,
        status,
        allowedTransitions: [],
        notes: null,
        extension: null,
        history: [],
      });
      for (const value of [
        "No notes were added.",
        "No extension selected.",
        "There are no status changes yet.",
      ])
        expect(wrapper.text()).toContain(value);
      expect(
        wrapper
          .find("[data-next-status], form[data-status-confirmation]")
          .exists(),
      ).toBe(false);
    },
  );

  // Catches unsupported client-generated transitions and native browser confirmation.
  it("offers only server-approved actions and focuses the real alert dialog", async () => {
    const confirm = vi.spyOn(window, "confirm");
    const wrapper = mountPage({
      ...booking,
      allowedTransitions: ["cancelled"],
    });
    expect(
      wrapper
        .findAll("[data-next-status]")
        .map((node) => node.attributes("data-next-status")),
    ).toEqual(["cancelled"]);
    await open(wrapper, "cancelled");
    expect(dialog().text()).toContain(
      "Change the status from Pending to Cancelled?",
    );
    expect(dialog().element.contains(document.activeElement)).toBe(true);
    expect(confirm).not.toHaveBeenCalled();
  });

  // Catches unclosable dialogs and lost keyboard position on both dismissal paths.
  it.each(["escape", "cancel"])(
    "closes with %s and restores the originating trigger",
    async (method) => {
      const wrapper = mountPage();
      const trigger = await open(wrapper, "cancelled");
      if (method === "escape")
        await dialog().trigger("keydown", { key: "Escape" });
      else await dialog().get("[data-cancel-status]").trigger("click");
      await settle();
      expect(document.querySelector("[role='alertdialog']")).toBeNull();
      expect(document.activeElement).toBe(trigger.element);
      expect(inertia.patches).toEqual([]);
    },
  );

  // Catches wrong endpoint encoding, extra payload fields, and untrimmed/blank audit reasons.
  it.each([
    ["  Confirmed by phone  ", "Confirmed by phone"],
    ["   ", null],
  ])("submits the exact PATCH with reason %j", async (input, expected) => {
    const wrapper = mountPage();
    await open(wrapper);
    const reason = dialog().get("textarea[name='reason']");
    expect(reason.attributes("maxlength")).toBe("500");
    await reason.setValue(input);
    await dialog().get("form[data-status-confirmation]").trigger("submit");
    expect(inertia.patches).toEqual([
      {
        url: "/admin/bookings/SJ%2FAYU%20%231%3F/status",
        data: {
          currentStatus: "pending",
          nextStatus: "confirmed",
          reason: expected,
        },
      },
    ]);
  });

  // Catches double PATCHes and conflicting actions during an in-flight request.
  it("disables every status action while processing and closes on success", async () => {
    const wrapper = mountPage();
    const trigger = await open(wrapper);
    await dialog().get("form").trigger("submit");
    await settle();
    for (const button of [
      ...wrapper.findAll("[data-next-status]"),
      ...dialog().findAll("button"),
    ])
      expect(button.attributes("disabled")).toBeDefined();
    expect(dialog().get("[role='status']").text()).toBe("Saving change…");
    expect(
      dialog().get("[role='status']").element.closest("[aria-busy='true']"),
    ).toBeNull();
    await dialog().trigger("keydown", { key: "Escape" });
    expect(document.querySelector("[role='alertdialog']")).not.toBeNull();
    await dialog().get("form").trigger("submit");
    expect(inertia.patches).toHaveLength(1);
    await inertia.options.onSuccess?.({ props: {} });
    await inertia.options.onFinish?.({});
    await settle();
    expect(document.querySelector("[role='alertdialog']")).toBeNull();
    expect(document.activeElement).toBe(trigger.element);
  });

  // Catches uncapped paste input crossing the 500-character request boundary.
  it("caps a pasted reason at 500 characters", async () => {
    const wrapper = mountPage();
    await open(wrapper);
    await dialog().get("textarea").setValue("a".repeat(501));
    await dialog().get("form").trigger("submit");
    expect(inertia.patches[0].data).toEqual({
      currentStatus: "pending",
      nextStatus: "confirmed",
      reason: "a".repeat(500),
    });
  });

  // Catches loss of focus when the server update removes the terminal transition trigger.
  it("focuses the status summary after a successful terminal update", async () => {
    const wrapper = mountPage();
    await open(wrapper, "cancelled");
    await dialog().get("form").trigger("submit");
    await wrapper.setProps({
      booking: { ...booking, status: "cancelled", allowedTransitions: [] },
    });
    await inertia.options.onSuccess?.({ props: {} });
    await inertia.options.onFinish?.({});
    await settle();
    expect(document.querySelector("[role='alertdialog']")).toBeNull();
    expect(wrapper.find("[data-next-status]").exists()).toBe(false);
    expect(document.activeElement).toBe(
      wrapper.get("section.admin-status-actions").element,
    );
  });

  // Catches a dialog trapping Tab on the last control or escaping backwards to the background.
  it("keeps modal keyboard focus within the reason, cancel and confirm controls", async () => {
    const wrapper = mountPage();
    await open(wrapper);
    const reason = dialog().get<HTMLTextAreaElement>("textarea");
    const cancel = dialog().get<HTMLButtonElement>("[data-cancel-status]");
    const submit = dialog().get<HTMLButtonElement>("[type='submit']");
    for (const control of [reason, cancel, submit]) {
      control.element.focus();
      expect(document.activeElement).toBe(control.element);
    }
    await submit.trigger("keydown", { key: "Tab" });
    expect(document.activeElement).toBe(reason.element);
    await reason.trigger("keydown", { key: "Tab", shiftKey: true });
    expect(document.activeElement).toBe(submit.element);
  });

  // Catches stale and field errors becoming invisible, unassociated, or closing the dialog.
  it("keeps authoritative validation and stale feedback linked with usable focus", async () => {
    const wrapper = mountPage();
    await open(wrapper);
    await dialog().get("textarea").setValue("Keep my reason");
    await dialog().get("form").trigger("submit");
    await inertia.options.onError?.({
      currentStatus: "Refresh this stale booking.",
      nextStatus: "Transition is no longer allowed.",
      reason: "Reason is too long.",
      form: "Server rejected this change.",
    });
    await inertia.options.onFinish?.({});
    await settle();
    for (const [name, message] of Object.entries({
      currentStatus: "Refresh this stale booking.",
      nextStatus: "Transition is no longer allowed.",
      reason: "Reason is too long.",
    })) {
      const field = dialog().get(`[name='${name}']`);
      expect(field.attributes("aria-invalid")).toBe("true");
      expect(
        dialog()
          .get(`#${field.attributes("aria-describedby")}`)
          .text(),
      ).toBe(message);
    }
    expect(dialog().get("form").attributes("aria-describedby")).toContain(
      "admin-status-form-error",
    );
    expect(dialog().get("#admin-status-form-error").text()).toContain(
      "Server rejected this change.",
    );
    expect(dialog().get("textarea").element.value).toBe("Keep my reason");
    expect(dialog().element.contains(document.activeElement)).toBe(true);
    expect(
      dialog().get("[type='submit']").attributes("disabled"),
    ).toBeUndefined();
    await dialog().get("[data-cancel-status]").trigger("click");
    await settle();
    await open(wrapper, "cancelled");
    expect(dialog().find("[role='alert']").exists()).toBe(false);
    expect(dialog().get("textarea").element.value).toBe("");
  });

  // Catches locale drift in the detail, dialog, placeholders and history.
  it("uses the shared locale for detail labels and confirmation", async () => {
    const wrapper = mountPage({ ...booking, notes: null, extension: null });
    await wrapper.get("[data-admin-locale='id']").trigger("click");
    for (const text of [
      "Data tamu",
      "Jadwal",
      "Perawatan",
      "Ritual Relaksasi",
      "Dua terapis",
      "7 Oktober 2026",
      "Tidak ada catatan.",
      "Tanpa tambahan durasi.",
      "Riwayat status",
      "Tidak ada alasan yang dicatat.",
    ])
      expect(wrapper.text()).toContain(text);
    await open(wrapper);
    for (const text of [
      "Konfirmasi perubahan status",
      "Ubah status dari Menunggu menjadi Terkonfirmasi?",
      "Alasan (opsional)",
      "Batal",
      "Konfirmasi perubahan",
    ])
      expect(dialog().text()).toContain(text);
  });
});

describe("room scheduling", () => {
  it("requires an unchecked operational checkbox and submits explicit consent", async () => {
    const wrapper = mountPage({
      ...booking,
      roomSchedulingEnabled: true,
      assignedRoom: null,
    });
    await open(wrapper);
    const checkbox = dialog().get<HTMLInputElement>(
      "input[name='operationalConfirmed']",
    );
    expect(checkbox.element.checked).toBe(false);
    await checkbox.setValue(true);
    await dialog().get("form").trigger("submit");
    expect(inertia.patches.at(-1)?.data).toMatchObject({
      operationalConfirmed: true,
    });
  });
});

describe("room scheduling display", () => {
  it.each(["en", "id"] as const)(
    "shows safe room and persisted attestation in %s",
    async (locale) => {
      inertia.page.props.locale = locale;
      const wrapper = mountPage({
        ...booking,
        assignedRoom: {
          name: "Quiet Room",
          code: "Q1",
          type: "private",
          capacity: 2,
          startsAt: "2026-10-07T07:30:00Z",
          endsAt: "2026-10-07T09:15:00Z",
          releasedAt: null,
        },
        status: "cancelled",
        history: [
          {
            ...booking.history[0]!,
            operationalConfirmedAt: "2026-10-06T07:30:00Z",
          },
        ],
      });
      expect(wrapper.get("[data-assigned-room]").text()).toContain(
        "Quiet Room",
      );
      expect(wrapper.get("[data-assigned-room]").text()).toContain(
        locale === "en" ? "original cleaning end" : "pembersihan semula",
      );
      expect(wrapper.get("[data-operational-attestation]").text()).toContain(
        locale === "en" ? "Therapist availability" : "Ketersediaan terapis",
      );
    },
  );
  it("resets consent on reopening and omits consent for cancellation", async () => {
    const wrapper = mountPage({ ...booking, roomSchedulingEnabled: true });
    await open(wrapper);
    await dialog().get("input[name='operationalConfirmed']").setValue(true);
    await dialog().get("[data-cancel-status]").trigger("click");
    await settle();
    await open(wrapper);
    expect(
      dialog().get<HTMLInputElement>("input[name='operationalConfirmed']")
        .element.checked,
    ).toBe(false);
    await dialog().get("[data-cancel-status]").trigger("click");
    await settle();
    await open(wrapper, "cancelled");
    expect(dialog().find("input[name='operationalConfirmed']").exists()).toBe(
      false,
    );
    await dialog().get("form").trigger("submit");
    expect(inertia.patches.at(-1)?.data).not.toHaveProperty(
      "operationalConfirmed",
    );
  });
});
