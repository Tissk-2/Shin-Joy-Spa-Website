import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

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
  options: {} as Record<string, unknown>,
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive, defineComponent, h } = await import("vue");
  inertia.page = reactive(inertia.page);
  return {
    Head: "span",
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
    usePage: () => inertia.page,
    router: {
      get: vi.fn(
        (
          url: string,
          data: Record<string, unknown>,
          options: Record<string, unknown>,
        ) => {
          inertia.gets.push({ url, data });
          inertia.options = options;
          (options.onStart as () => void)?.();
        },
      ),
      post: vi.fn((url: string, data: { locale: Locale }) => {
        if (url === "/locale") inertia.page.props.locale = data.locale;
      }),
    },
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
  today: 7,
  pending: 12,
  confirmed: 8,
  completed: 36,
});
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
const mountPage = (filters = emptyFilters, records = bookings) =>
  mount(Index, {
    props: { counts, filters, bookings: records },
    attachTo: document.body,
  });

describe("admin booking console", () => {
  beforeEach(() => {
    inertia.page.props.locale = "en";
    inertia.gets = [];
    inertia.options = {};
    inertia.page.props.errors = {};
  });
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = "";
  });

  // Catches swapped count values, lost initial filters, and unlabeled form controls.
  it("renders the server counts and current filters with visible labels", () => {
    const wrapper = mountPage(filtered);
    expect(wrapper.get("h1").text()).toBe("Bookings");
    expect(
      wrapper.findAll(".admin-count-grid dt").map((node) => node.text()),
    ).toEqual(["Today", "Pending", "Confirmed", "Completed"]);
    expect(
      wrapper.findAll(".admin-count-grid dd").map((node) => node.text()),
    ).toEqual(["7", "12", "8", "36"]);
    for (const [name, value] of Object.entries(filtered).filter(
      ([key]) => key !== "scope",
    )) {
      const control = wrapper.get<HTMLInputElement>(`[name='${name}']`);
      expect(control.element.value).toBe(value);
      expect(
        wrapper.get(`label[for='${control.attributes("id")}']`).text(),
      ).not.toBe("");
    }
    expect(
      wrapper.get("button[data-scope='upcoming']").attributes("aria-pressed"),
    ).toBe("true");
    expect(wrapper.get("input[name='q']").attributes("maxlength")).toBe("100");
  });

  // Catches missing mobile fields, stale independent card data, and unsafe reference interpolation.
  it("renders the same ordered records, complete fields and encoded detail links in both views", () => {
    const wrapper = mountPage();
    const rows = wrapper.findAll(".admin-booking-table tbody tr");
    const cards = wrapper.findAll(".admin-booking-cards > li");
    expect(rows).toHaveLength(4);
    expect(cards).toHaveLength(4);
    expect(wrapper.get("caption.sr-only").text()).toBe("Bookings");
    expect(
      wrapper
        .findAll("thead th")
        .map((node) => [node.text(), node.attributes("scope")]),
    ).toEqual(
      [
        "Reference",
        "Guest",
        "Treatment",
        "Schedule",
        "Guests",
        "Total price",
        "Status",
        "Action",
      ].map((label) => [label, "col"]),
    );
    for (const [index, booking] of data.entries()) {
      for (const record of [rows[index], cards[index]]) {
        for (const text of [
          booking.reference,
          booking.customer.name,
          booking.customer.email,
          booking.customer.phone,
          "Relaxation Ritual",
          "7 October 2026",
          "14:30 WIB",
          "90 min",
          "Rp 450.000",
        ])
          expect(record.text()).toContain(text);
        expect(record.get("a").attributes("href")).toBe(
          `/admin/bookings/${encodeURIComponent(booking.reference)}`,
        );
        expect(record.get(".admin-status").text().toLowerCase()).toBe(
          booking.status,
        );
        expect(record.find("script").exists()).toBe(false);
      }
    }
    expect(rows[0].text()).toContain("Double therapist");
    expect(rows[1].text()).toContain("Regular");
    expect(rows[0].text()).toContain("VIP");
  });

  // Catches mutation of readonly server filters, untrimmed literal search, lost dates, and duplicate requests.
  it("submits the exact GET query and disables every form control until finish without storage", async () => {
    const storage = vi.spyOn(Storage.prototype, "setItem");
    const wrapper = mountPage(filtered);
    await wrapper.get("input[name='q']").setValue(" AYU% ");
    await wrapper.get("select[name='status']").setValue("pending");
    await wrapper.get("form[data-admin-filters]").trigger("submit");
    await wrapper.get("form[data-admin-filters]").trigger("submit");
    expect(inertia.gets).toEqual([
      {
        url: "/admin/bookings",
        data: {
          scope: "upcoming",
          status: "pending",
          q: "AYU%",
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
    expect(filtered.q).toBe("Ayu");
    expect(filtered.status).toBe("confirmed");
    expect(wrapper.get("form").attributes("aria-busy")).toBe("true");
    for (const control of wrapper.findAll(
      "form input, form select, form button",
    ))
      expect(control.attributes("disabled")).toBeDefined();
    (inertia.options.onFinish as () => void)();
    await wrapper.vm.$nextTick();
    for (const control of wrapper.findAll(
      "form input, form select, form button",
    ))
      expect(control.attributes("disabled")).toBeUndefined();
    expect(storage).not.toHaveBeenCalled();
  });

  // Catches a filter visit offering no visible or politely announced progress message.
  it("shows a visible polite loading status until the filter visit finishes", async () => {
    const wrapper = mountPage(filtered);
    expect(wrapper.find("[data-admin-loading]").exists()).toBe(false);

    await wrapper.get("form[data-admin-filters]").trigger("submit");

    const message = wrapper.get("[data-admin-loading]");
    expect(message.isVisible()).toBe(true);
    expect(message.attributes("role")).toBe("status");
    expect(message.attributes("aria-live")).toBe("polite");
    expect(message.text()).toBe("Loading bookings…");

    await wrapper.get("button[data-admin-locale='id']").trigger("click");
    expect(message.text()).toBe("Memuat reservasi…");

    (inertia.options.onFinish as () => void)();
    await wrapper.vm.$nextTick();
    expect(wrapper.find("[data-admin-loading]").exists()).toBe(false);
  });

  // Catches empty optional query keys and accidental persistence of page/stale filters.
  it("omits blank optional values after real field edits", async () => {
    const wrapper = mountPage(filtered);
    await wrapper.get("input[name='q']").setValue("   ");
    await wrapper.get("select[name='status']").setValue("");
    await wrapper.get("input[name='dateFrom']").setValue("");
    await wrapper.get("input[name='dateTo']").setValue("");
    await wrapper.get("form").trigger("submit");
    expect(inertia.gets).toEqual([
      { url: "/admin/bookings", data: { scope: "upcoming" } },
    ]);
  });

  // Catches scope controls rendered as inert tabs or accidental form submissions with old scope.
  it.each(["past", "all", "upcoming"] as const)(
    "submits the %s scope from a real form button",
    async (scope) => {
      const wrapper = mountPage();
      const button = wrapper.get(`form button[data-scope='${scope}']`);
      await button.trigger("click");
      expect(inertia.gets).toEqual([
        { url: "/admin/bookings", data: { scope } },
      ]);
      expect(button.attributes("aria-pressed")).toBe("true");
    },
  );

  // Catches Clear leaving status/search/date or past scope in the request.
  it("clears with only upcoming scope and is absent for default filters", async () => {
    const wrapper = mountPage({ ...filtered, scope: "past" });
    await wrapper.get("button[data-clear-filters]").trigger("click");
    expect(inertia.gets).toEqual([
      { url: "/admin/bookings", data: { scope: "upcoming" } },
    ]);
    expect(mountPage().find("button[data-clear-filters]").exists()).toBe(false);
  });

  // Catches preserved Inertia page instances continuing to submit obsolete server filters.
  it("resynchronizes local controls when a preserved visit returns normalized filters", async () => {
    const wrapper = mountPage(filtered);
    await wrapper.setProps({ filters: emptyFilters });
    expect(wrapper.get<HTMLInputElement>("input[name='q']").element.value).toBe(
      "",
    );
    expect(
      wrapper.get<HTMLSelectElement>("select[name='status']").element.value,
    ).toBe("");
    await wrapper.get("form").trigger("submit");
    expect(inertia.gets.at(-1)?.data).toEqual({ scope: "upcoming" });
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

  // Catches unhelpful no-bookings copy after filtering, or scope-only being treated as an optional filter.
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
    expect(wrapper.find(".admin-booking-cards").exists()).toBe(false);
  });

  // Catches operational labels/statuses that remain in English after the real shell locale action.
  it("switches every index label, status, package and date with the server locale", async () => {
    const wrapper = mountPage(filtered);
    await wrapper.get("button[data-admin-locale='id']").trigger("click");
    expect(wrapper.get("h1").text()).toBe("Reservasi");
    expect(
      wrapper.findAll(".admin-count-grid dt").map((node) => node.text()),
    ).toEqual(["Hari ini", "Menunggu", "Terkonfirmasi", "Selesai"]);
    expect(wrapper.findAll("form label").map((node) => node.text())).toEqual([
      "Cari referensi atau data tamu",
      "Status",
      "Dari tanggal",
      "Sampai tanggal",
    ]);
    expect(wrapper.findAll("form button").map((node) => node.text())).toEqual([
      "Mendatang",
      "Lampau",
      "Semua",
      "Terapkan filter",
      "Hapus filter",
    ]);
    expect(wrapper.findAll("select option").map((node) => node.text())).toEqual(
      ["Semua", "Menunggu", "Terkonfirmasi", "Selesai", "Dibatalkan"],
    );
    expect(wrapper.findAll("thead th").map((node) => node.text())).toEqual([
      "Referensi",
      "Tamu",
      "Perawatan",
      "Jadwal",
      "Jumlah tamu",
      "Total harga",
      "Status",
      "Aksi",
    ]);
    for (const selector of [".admin-booking-table", ".admin-booking-cards"]) {
      const view = wrapper.get(selector);
      expect(view.findAll(".admin-status").map((node) => node.text())).toEqual([
        "Menunggu",
        "Terkonfirmasi",
        "Selesai",
        "Dibatalkan",
      ]);
      for (const text of [
        "Ritual Relaksasi",
        "Dua terapis",
        "Reguler",
        "7 Oktober 2026",
        "90 menit",
        "Buka",
      ])
        expect(view.text()).toContain(text);
    }
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
  it("keeps filter controls and booking links keyboard focusable in document order", () => {
    const wrapper = mountPage(filtered);
    const controls = wrapper.findAll<HTMLElement>(
      "form button, form input, form select, .admin-booking-table a, .admin-pagination a",
    );
    expect(
      controls
        .map((node) => node.attributes("name") || node.attributes("data-scope"))
        .slice(0, 7),
    ).toEqual(["upcoming", "past", "all", "q", "status", "dateFrom", "dateTo"]);
    for (const control of controls) {
      control.element.focus();
      expect(document.activeElement).toBe(control.element);
      expect(control.attributes("tabindex")).not.toBe("-1");
    }
  });
});
