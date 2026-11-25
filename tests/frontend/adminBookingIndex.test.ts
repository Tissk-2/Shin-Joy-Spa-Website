import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { nextTick } from "vue";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { formatDay, shortReference } from "@/lib/adminBooking";
import Index from "@/pages/admin/bookings/Index.vue";
import type {
  AdminBookingCounts,
  AdminBookingFilters,
  AdminBookingPagination,
  AdminBookingSummaryData,
  Locale,
} from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/admin/bookings",
    props: {
      auth: {
        user: {
          id: 4,
          name: "Rani Kusuma",
          email: "rani@shinjoy.test",
          phone: "+62 812-0000-0000",
          email_verified_at: "2026-09-01",
        },
        canAccessAdmin: true,
      },
      locale: "en" as Locale,
      flash: {},
      errors: {} as Record<string, string>,
      catalog: { packages: [] },
    },
  },
  gets: [] as Array<{ url: string; data: Record<string, unknown> }>,
  patches: [] as Array<{ url: string; data: unknown }>,
  options: {} as Record<string, (...args: any[]) => unknown>,
}));

// Keep Inertia's real useForm for the shared status dialog; only the
// transport, page props and links are test boundaries.
vi.mock("@inertiajs/vue3", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@inertiajs/vue3")>();
  const { reactive, defineComponent, h } = await import("vue");
  inertia.page = reactive(inertia.page);
  vi.spyOn(actual.router, "get").mockImplementation((url, data, options) => {
    inertia.gets.push({
      url: String(url),
      data: data as Record<string, unknown>,
    });
    inertia.options = options as typeof inertia.options;
    inertia.options.onStart?.({});
  });
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
      props: {
        href: { type: String, required: true },
        preserveState: Boolean,
        preserveScroll: Boolean,
      },
      setup:
        (props, { slots }) =>
        () =>
          h(
            "a",
            {
              href: props.href,
              "data-preserve-state": props.preserveState,
              "data-preserve-scroll": props.preserveScroll,
            },
            slots.default?.(),
          ),
    }),
  };
});

const emptyFilters: AdminBookingFilters = Object.freeze({
  scope: "upcoming",
  status: null,
  q: "",
  dateFrom: null,
  dateTo: null,
});
const counts: AdminBookingCounts = Object.freeze({
  upcoming: 9,
  pending: 12,
  today: 7,
  past: 30,
  all: 51,
});
const threeHoursAgo = new Date(Date.now() - 3 * 3600 * 1000).toISOString();
const data: readonly AdminBookingSummaryData[] = Object.freeze(
  (["pending", "confirmed", "completed", "cancelled"] as const).map(
    (status, index) =>
      Object.freeze({
        reference: index === 0 ? "SJ/AYU #1?" : `SJ-20260907-000${index}`,
        status,
        package: Object.freeze({
          nameId: "Ritual Relaksasi",
          nameEn: "Relaxation Ritual",
          isVip: index === 0,
        }),
        treatmentMode:
          index === 0 ? ("double_therapist" as const) : ("regular" as const),
        appointment: Object.freeze({
          date: "2026-10-07",
          time: "14:30",
          timezone: "Asia/Jakarta",
        }),
        customer: Object.freeze({
          name:
            index === 0 ? "Ayu <script>alert(1)</script>" : `Guest ${index}`,
          email: `ayu${index}@example.test`,
          phone: "+62 812-3456-7890",
        }),
        guests: 2,
        totalDurationMinutes: 90,
        totalPriceIdr: 450000,
        allowedTransitions: Object.freeze(
          status === "pending"
            ? (["confirmed", "cancelled"] as const)
            : status === "confirmed"
              ? (["completed", "cancelled"] as const)
              : [],
        ),
        requestedAt: threeHoursAgo,
      }),
  ),
);
const bookings: AdminBookingPagination = Object.freeze({
  data,
  links: Object.freeze([
    Object.freeze({ url: null, label: "« Previous", active: false }),
    Object.freeze({
      url: "/admin/bookings?scope=upcoming&page=1",
      label: "1",
      active: true,
    }),
    Object.freeze({
      url: "/admin/bookings?scope=upcoming&status=pending&q=AYU%25&page=2",
      label: "2",
      active: false,
    }),
    Object.freeze({
      url: "/admin/bookings?scope=upcoming&status=pending&q=AYU%25&page=2",
      label: "Next »",
      active: false,
    }),
  ]),
  currentPage: 1,
  lastPage: 2,
  total: 24,
});
const filtered: AdminBookingFilters = Object.freeze({
  scope: "upcoming",
  status: "confirmed",
  q: "Ayu",
  dateFrom: "2026-09-07",
  dateTo: "2026-09-30",
});
const mounted: ReturnType<typeof mount>[] = [];
const mountPage = (
  filters = emptyFilters,
  records = bookings,
  extra: Record<string, unknown> = {},
) => {
  const wrapper = mount(Index, {
    props: { counts, filters, bookings: records, ...extra },
    attachTo: document.body,
  });
  mounted.push(wrapper);
  return wrapper;
};
async function settle() {
  await flushPromises();
  await nextTick();
}
function dialog() {
  return new DOMWrapper(
    document.querySelector<HTMLElement>("[role='alertdialog']")!,
  );
}

describe("admin booking console", () => {
  beforeEach(() => {
    inertia.page.props.locale = "en";
    inertia.gets = [];
    inertia.patches = [];
    inertia.options = {};
    inertia.page.props.errors = {};
  });
  afterEach(async () => {
    vi.useRealTimers();
    for (const wrapper of mounted.splice(0)) wrapper.unmount();
    await settle();
    document.body.innerHTML = "";
    vi.clearAllMocks();
  });

  // Catches swapped tab counts, lost initial filters, and unlabeled form controls.
  it("renders one tab per list with its count and the current filters with visible labels", () => {
    const wrapper = mountPage(filtered, bookings, { canCreate: true });
    expect(wrapper.get("h1").text()).toBe("Bookings");
    expect(
      wrapper.get(".admin-page-heading a[data-new-booking]").attributes("href"),
    ).toBe("/admin/bookings/create");
    const tabs = wrapper.findAll("[data-scope]");
    expect(tabs.map((tab) => tab.attributes("data-scope"))).toEqual([
      "upcoming",
      "pending",
      "today",
      "past",
      "all",
    ]);
    expect(tabs.map((tab) => tab.get(".admin-tab__count").text())).toEqual([
      "9",
      "12",
      "7",
      "30",
      "51",
    ]);
    expect(tabs.map((tab) => tab.attributes("aria-pressed"))).toEqual([
      "true",
      "false",
      "false",
      "false",
      "false",
    ]);
    // Waiting requests stand out; the other counts stay quiet.
    expect(tabs[1].get(".admin-tab__count").classes()).toContain(
      "admin-tab__count--alert",
    );
    expect(tabs[0].get(".admin-tab__count").classes()).not.toContain(
      "admin-tab__count--alert",
    );
    for (const [name, value] of Object.entries(filtered).filter(
      ([key]) => key !== "scope",
    )) {
      const control = wrapper.get<HTMLInputElement>(`[name='${name}']`);
      expect(control.element.value).toBe(value);
      expect(
        wrapper.get(`label[for='${control.attributes("id")}']`).text(),
      ).not.toBe("");
    }
    expect(wrapper.get("input[name='q']").attributes("maxlength")).toBe("100");
    // Filters apply as they change, so there is no Apply button.
    expect(wrapper.find("form [type='submit']").exists()).toBe(false);
  });

  // Catches missing phone fields, stale independent list data, and unsafe reference interpolation.
  it("renders the same ordered records in the table and the phone list with safe, labelled detail links", () => {
    const wrapper = mountPage();
    const rows = wrapper.findAll(".admin-booking-table tbody tr");
    const items = wrapper.findAll(".admin-booking-list > li");
    expect(rows).toHaveLength(4);
    expect(items).toHaveLength(4);
    expect(wrapper.get("caption.sr-only").text()).toBe("Bookings");
    expect(
      wrapper
        .findAll("thead th")
        .map((node) => [node.text(), node.attributes("scope")]),
    ).toEqual(
      ["Guest", "Treatment", "Schedule", "Total price", "Status", "Action"].map(
        (label) => [label, "col"],
      ),
    );
    for (const [index, booking] of data.entries()) {
      for (const record of [rows[index], items[index]]) {
        for (const text of [
          booking.customer.name,
          shortReference(booking.reference),
          "Relaxation Ritual",
          formatDay(booking.appointment.date, "en", "short"),
          "14:30 WIB",
          "2 guests",
          "Rp 450.000",
        ])
          expect(record.text()).toContain(text);
        const link = record.get("a");
        expect(link.attributes("href")).toBe(
          `/admin/bookings/${encodeURIComponent(booking.reference)}`,
        );
        expect(link.attributes("title")).toBe(booking.reference);
        // Voice control users say the words they see, so the name is the visible text.
        expect(link.attributes("aria-label")).toBeUndefined();
        expect(link.text().startsWith(booking.customer.name)).toBe(true);
        expect(record.get(".admin-status").text().toLowerCase()).toBe(
          booking.status,
        );
        expect(record.find("script").exists()).toBe(false);
      }
    }
    // Staff review 2026-11-25: a phone line could start with "· Rp". Each "·" is glued to the item before it.
    for (const item of items) {
      const meta = item.get(".admin-booking-list__meta").element.textContent!;
      expect(meta.match(/·/g)).toHaveLength(4);
      expect(meta).not.toMatch(/[^\u00a0]·|·\S/);
    }
    expect(rows[0].text()).toContain("Double therapist");
    expect(rows[0].text()).toContain("90 min");
    expect(rows[1].text()).toContain("Regular");
    expect(rows[0].text()).toContain("VIP");
    expect(rows[0].text()).toContain("Requested 3 hours ago");
    expect(rows[1].text()).not.toContain("Requested");
  });

  // Catches mutation of readonly server filters, untrimmed literal search, lost dates, duplicate requests and storage.
  it("applies a changed field at once and the search after a pause, without storage", async () => {
    vi.useFakeTimers();
    const storage = vi.spyOn(Storage.prototype, "setItem");
    const wrapper = mountPage(filtered);
    await wrapper.get("select[name='status']").setValue("pending");
    expect(inertia.gets).toEqual([
      {
        url: "/admin/bookings",
        data: {
          scope: "upcoming",
          status: "pending",
          q: "Ayu",
          dateFrom: "2026-09-07",
          dateTo: "2026-09-30",
        },
      },
    ]);
    expect(inertia.options).toMatchObject({
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
    await wrapper.get("input[name='q']").setValue(" AYU% ");
    vi.advanceTimersByTime(349);
    expect(inertia.gets).toHaveLength(1);
    vi.advanceTimersByTime(1);
    expect(inertia.gets.at(-1)?.data).toEqual({
      scope: "upcoming",
      status: "pending",
      q: "AYU%",
      dateFrom: "2026-09-07",
      dateTo: "2026-09-30",
    });
    // Enter with nothing new sends nothing, and typing never gets locked out.
    await wrapper.get("form[data-admin-filters]").trigger("submit");
    expect(inertia.gets).toHaveLength(2);
    expect(wrapper.get("form").attributes("aria-busy")).toBe("true");
    for (const control of wrapper.findAll("form input, form select"))
      expect(control.attributes("disabled")).toBeUndefined();
    expect(filtered.q).toBe("Ayu");
    expect(filtered.status).toBe("confirmed");
    expect(storage).not.toHaveBeenCalled();
  });

  // Catches a reply to an earlier filter wiping out what staff typed while it was on its way.
  it("keeps typing that happened while a filter request was on its way", async () => {
    vi.useFakeTimers();
    const wrapper = mountPage();
    await wrapper.get("select[name='status']").setValue("pending");
    await wrapper.get("input[name='q']").setValue("Bud");
    // The status request comes back, carrying the search as it was when it left.
    await wrapper.setProps({ filters: { ...emptyFilters, status: "pending" } });
    expect(wrapper.get<HTMLInputElement>("input[name='q']").element.value).toBe(
      "Bud",
    );
    vi.advanceTimersByTime(350);
    expect(inertia.gets.at(-1)?.data).toEqual({
      scope: "upcoming",
      status: "pending",
      q: "Bud",
    });
  });

  // Catches Enter waiting for the pause and then sending the same search twice.
  it("applies the search at once on Enter and does not repeat it after the pause", async () => {
    vi.useFakeTimers();
    const wrapper = mountPage();
    await wrapper.get("input[name='q']").setValue("Budi");
    await wrapper.get("form[data-admin-filters]").trigger("submit");
    expect(inertia.gets).toEqual([
      { url: "/admin/bookings", data: { scope: "upcoming", q: "Budi" } },
    ]);
    vi.advanceTimersByTime(1000);
    expect(inertia.gets).toHaveLength(1);
  });

  // Catches a filter visit offering no visible or politely announced progress message.
  it("shows a visible polite loading status until the filter visit finishes", async () => {
    const wrapper = mountPage(filtered);
    expect(wrapper.find("[data-admin-loading]").exists()).toBe(false);

    await wrapper.get("select[name='status']").setValue("pending");

    const message = wrapper.get("[data-admin-loading]");
    expect(message.isVisible()).toBe(true);
    expect(message.attributes("role")).toBe("status");
    expect(message.attributes("aria-live")).toBe("polite");
    expect(message.text()).toBe("Loading bookings…");

    await wrapper.get("button[data-admin-locale='id']").trigger("click");
    expect(message.text()).toBe("Memuat reservasi…");

    inertia.options.onFinish?.({});
    await nextTick();
    expect(wrapper.find("[data-admin-loading]").exists()).toBe(false);
  });

  // Catches empty optional query keys and accidental persistence of page/stale filters.
  it("omits blank optional values after real field edits", async () => {
    vi.useFakeTimers();
    const wrapper = mountPage(filtered);
    await wrapper.get("input[name='q']").setValue("   ");
    await wrapper.get("select[name='status']").setValue("");
    await wrapper.get("input[name='dateFrom']").setValue("");
    await wrapper.get("input[name='dateTo']").setValue("");
    vi.advanceTimersByTime(350);
    expect(inertia.gets.at(-1)).toEqual({
      url: "/admin/bookings",
      data: { scope: "upcoming" },
    });
    for (const visit of inertia.gets)
      expect(Object.values(visit.data)).not.toContain("");
  });

  // Catches tabs rendered as inert decoration or sending the old tab.
  it.each(["pending", "today", "past", "all"] as const)(
    "opens the %s tab with a fresh query",
    async (scope) => {
      const wrapper = mountPage();
      const tab = wrapper.get(`button[data-scope='${scope}']`);
      await tab.trigger("click");
      expect(inertia.gets).toEqual([
        { url: "/admin/bookings", data: { scope } },
      ]);
      expect(tab.attributes("aria-pressed")).toBe("true");
    },
  );

  // Catches Clear leaving status/search/date in the request or jumping to another tab.
  it("clears the filters but keeps the open tab, and hides Clear without filters", async () => {
    vi.useFakeTimers();
    const wrapper = mountPage({ ...filtered, scope: "past" });
    await wrapper.get("button[data-clear-filters]").trigger("click");
    vi.advanceTimersByTime(1000);
    expect(inertia.gets).toEqual([
      { url: "/admin/bookings", data: { scope: "past" } },
    ]);
    expect(wrapper.get<HTMLInputElement>("input[name='q']").element.value).toBe(
      "",
    );
    expect(mountPage().find("button[data-clear-filters]").exists()).toBe(false);
    expect(
      mountPage({ ...emptyFilters, scope: "past" })
        .find("button[data-clear-filters]")
        .exists(),
    ).toBe(false);
  });

  // Catches preserved Inertia page instances resubmitting obsolete server filters.
  it("resynchronizes local controls when a preserved visit returns normalized filters", async () => {
    vi.useFakeTimers();
    const wrapper = mountPage(filtered);
    await wrapper.setProps({ filters: emptyFilters });
    expect(wrapper.get<HTMLInputElement>("input[name='q']").element.value).toBe(
      "",
    );
    expect(
      wrapper.get<HTMLSelectElement>("select[name='status']").element.value,
    ).toBe("");
    vi.advanceTimersByTime(1000);
    expect(inertia.gets).toEqual([]);
  });

  // Catches pagination losing query/state, disabled URLs becoming links, and HTML labels executing.
  it("renders plain pagination, preserves links, and marks the current page", () => {
    const wrapper = mountPage(emptyFilters, {
      ...bookings,
      links: [
        ...bookings.links.slice(0, 2),
        {
          url: "/admin/bookings?page=2",
          label: "<img src=x onerror=alert(1)>",
          active: false,
        },
        bookings.links[3],
      ],
    });
    const navigation = wrapper.get(".admin-pagination");
    expect(navigation.get("span[aria-disabled='true']").text()).toBe(
      "Previous",
    );
    expect(navigation.get("a[aria-current='page']").text()).toBe("1");
    expect(navigation.find("img").exists()).toBe(false);
    expect(navigation.text()).toContain("<img src=x onerror=alert(1)>");
    const next = navigation.findAll("a").at(-1)!;
    expect(next.attributes("href")).toBe(bookings.links[3].url);
    expect(next.attributes("data-preserve-state")).toBe("true");
    expect(next.attributes("data-preserve-scroll")).toBe("true");
  });

  // Catches unhelpful no-bookings copy after filtering, or a single page showing pagination.
  it.each([
    emptyFilters,
    { ...emptyFilters, scope: "past" as const },
    filtered,
  ])("distinguishes unfiltered and filtered empty results: %j", (filters) => {
    const wrapper = mountPage(filters, {
      ...bookings,
      data: [],
      total: 0,
      links: [],
      lastPage: 1,
    });
    expect(wrapper.get("[data-booking-empty]").text()).toBe(
      filters.q
        ? "No bookings match these filters."
        : "There are no bookings yet.",
    );
    expect(wrapper.find(".admin-booking-table").exists()).toBe(false);
    expect(wrapper.find(".admin-booking-list").exists()).toBe(false);
    expect(wrapper.find(".admin-pagination").exists()).toBe(false);
  });

  // Catches operational labels/statuses that remain in English after the real shell locale action.
  it("switches every index label, status, package and date with the server locale", async () => {
    const wrapper = mountPage(filtered);
    await wrapper.get("button[data-admin-locale='id']").trigger("click");
    expect(wrapper.get("h1").text()).toBe("Reservasi");
    expect(
      wrapper
        .findAll("[data-scope]")
        .map((node) => node.text().replace(/\s*\d+$/, "")),
    ).toEqual(["Mendatang", "Perlu tanggapan", "Hari ini", "Lampau", "Semua"]);
    expect(wrapper.findAll("form label").map((node) => node.text())).toEqual([
      "Cari referensi atau data tamu",
      "Status",
      "Dari tanggal",
      "Sampai tanggal",
    ]);
    expect(wrapper.findAll("form button").map((node) => node.text())).toEqual([
      "Hapus filter",
    ]);
    // Flow review F16: "awaiting payment" only exists with online payment, which is off here.
    expect(wrapper.findAll("select option").map((node) => node.text())).toEqual(
      [
        "Semua",
        "Menunggu",
        "Terkonfirmasi",
        "Selesai",
        "Dibatalkan",
        "Tidak hadir",
      ],
    );
    const online = mountPage(filtered, bookings, {
      onlinePaymentsEnabled: true,
    });
    expect(
      online.findAll("select option").map((node) => node.text()),
    ).toContain("Menunggu pembayaran");
    expect(wrapper.findAll("thead th").map((node) => node.text())).toEqual([
      "Tamu",
      "Perawatan",
      "Jadwal",
      "Total harga",
      "Status",
      "Aksi",
    ]);
    for (const selector of [".admin-booking-table", ".admin-booking-list"]) {
      const view = wrapper.get(selector);
      expect(view.findAll(".admin-status").map((node) => node.text())).toEqual([
        "Menunggu",
        "Terkonfirmasi",
        "Selesai",
        "Dibatalkan",
      ]);
      for (const text of [
        "Ritual Relaksasi",
        formatDay("2026-10-07", "id", "short"),
        "2 tamu",
        "Setujui",
        "Tolak",
      ])
        expect(view.text()).toContain(text);
    }
    expect(wrapper.get(".admin-booking-table").text()).toContain("Dua terapis");
    expect(wrapper.get(".admin-booking-table").text()).toContain("90 menit");
    expect(wrapper.get(".admin-pagination").text()).toContain("Sebelumnya");
    expect(wrapper.get(".admin-pagination").text()).toContain("Berikutnya");
  });

  // Catches rejected server filters leaving staff without a linked explanation.
  it("connects server filter validation errors to their editable controls", () => {
    inertia.page.props.errors = {
      q: "Search must be at most 100 characters.",
      status: "Choose a supported status.",
      dateFrom: "Choose a valid start date.",
      dateTo: "End date must follow the start date.",
    };
    const wrapper = mountPage(filtered);
    for (const [name, message] of Object.entries(inertia.page.props.errors)) {
      const control = wrapper.get(`[name='${name}']`);
      expect(control.attributes("aria-invalid")).toBe("true");
      expect(
        wrapper.get(`#${control.attributes("aria-describedby")}`).text(),
      ).toBe(message);
    }
  });

  // Catches tab traps and custom controls that cannot receive keyboard focus.
  it("keeps tabs, filter controls and booking links keyboard focusable in document order", () => {
    const wrapper = mountPage(filtered);
    const controls = wrapper.findAll<HTMLElement>(
      ".admin-tabs button, form button, form input, form select, .admin-booking-table a, .admin-booking-table button, .admin-pagination a",
    );
    expect(
      controls
        .map((node) => node.attributes("name") || node.attributes("data-scope"))
        .slice(0, 9),
    ).toEqual([
      "upcoming",
      "pending",
      "today",
      "past",
      "all",
      "q",
      "status",
      "dateFrom",
      "dateTo",
    ]);
    for (const control of controls) {
      control.element.focus();
      expect(document.activeElement).toBe(control.element);
      expect(control.attributes("tabindex")).not.toBe("-1");
    }
  });

  // Catches approving from the list skipping the confirmation, losing the
  // list afterwards, or offering an answer on a booking that is not waiting.
  it("answers a waiting request from its row through the confirmation and returns to the list", async () => {
    const wrapper = mountPage();
    const rows = wrapper.findAll(".admin-booking-table tbody tr");
    const items = wrapper.findAll(".admin-booking-list > li");
    for (const record of [rows[0], items[0]])
      expect(
        record.findAll("[data-answer]").map((node) => node.text()),
      ).toEqual(["Decline", "Approve"]);
    for (const record of [...rows.slice(1), ...items.slice(1)])
      expect(record.find("[data-answer]").exists()).toBe(false);
    expect(
      rows[0].get("[data-answer='confirmed']").attributes("aria-label"),
    ).toBe(`Approve ${data[0].customer.name}`);

    const approve = rows[0].get<HTMLButtonElement>("[data-answer='confirmed']");
    approve.element.focus();
    await approve.trigger("click");
    await settle();
    expect(dialog().get("h2").text()).toBe("Approve this booking?");
    expect(dialog().get("[data-dialog-booking]").text()).toContain(
      "7 October 2026, 14:30 WIB",
    );
    expect(dialog().find("script").exists()).toBe(false);
    expect(dialog().find("#admin-operational-confirmed").exists()).toBe(false);

    await dialog().get("form[data-status-confirmation]").trigger("submit");
    expect(inertia.patches).toEqual([
      {
        url: "/admin/bookings/SJ%2FAYU%20%231%3F/status",
        data: {
          currentStatus: "pending",
          nextStatus: "confirmed",
          reason: null,
          returnTo: "bookings",
        },
      },
    ]);
    await inertia.options.onSuccess?.({ props: {} });
    await inertia.options.onFinish?.({});
    await settle();
    expect(document.querySelector("[role='alertdialog']")).toBeNull();
    expect(document.activeElement).toBe(approve.element);
  });

  // Catches inline approval bypassing the room-scheduling consent the detail page requires.
  it("asks for the operational check before approving from the list when rooms are scheduled", async () => {
    const wrapper = mountPage(emptyFilters, bookings, {
      roomSchedulingEnabled: true,
    });
    await wrapper.get("[data-answer='confirmed']").trigger("click");
    await settle();
    const checkbox = dialog().get<HTMLInputElement>(
      "#admin-operational-confirmed",
    );
    expect(checkbox.element.checked).toBe(false);
    await checkbox.setValue(true);
    await dialog().get("form").trigger("submit");
    expect(inertia.patches[0].data).toEqual({
      currentStatus: "pending",
      nextStatus: "confirmed",
      reason: null,
      operationalConfirmed: true,
      returnTo: "bookings",
    });
  });
});
