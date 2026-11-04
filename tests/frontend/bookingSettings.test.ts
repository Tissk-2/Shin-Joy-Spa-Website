import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import Booking from "@/pages/admin/settings/Booking.vue";
import type { Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: { props: { locale: "en" as Locale, flash: {} } },
  calls: [] as Array<{
    url: string;
    data: Record<string, unknown>;
    options: Record<string, unknown>;
  }>,
}));
vi.mock("@inertiajs/vue3", () => ({
  usePage: () => inertia.page,
  router: {
    patch: (
      url: string,
      data: Record<string, unknown>,
      options: Record<string, unknown>,
    ) => inertia.calls.push({ url, data, options }),
  },
}));
vi.mock("@/layouts/admin/AdminLayout.vue", () => ({
  default: {
    props: ["title", "subtitle"],
    template: "<main><h1>{{ title }}</h1><p>{{ subtitle }}</p><slot /></main>",
  },
}));

const saved = {
  version: 1,
  booking_horizon_days: 30,
  minimum_lead_minutes: 120,
  review_target_minutes: 60,
  minimum_checkout_minutes: 15,
  review_intervals: [{ weekday: 1, start: "09:00", end: "17:00" }],
};
const render = (props = {}) =>
  mount(Booking, {
    props: { state: "unconfigured", settings: null, version: 0, ...props },
  });

describe("owner booking settings", () => {
  beforeEach(() => {
    inertia.calls = [];
    inertia.page.props.locale = "en";
    inertia.page.props.flash = {};
  });

  it("starts blank, explains the prerequisite and supports multiple empty intervals without fabricated hours", async () => {
    const wrapper = render();
    expect(wrapper.text()).toContain("not configured");
    expect(wrapper.text()).toContain("future availability");
    expect(wrapper.text()).toContain("Asia/Jakarta");
    for (const field of [
      "booking_horizon_days",
      "minimum_lead_minutes",
      "review_target_minutes",
      "minimum_checkout_minutes",
    ]) {
      expect(wrapper.get<HTMLInputElement>(`#${field}`).element.value).toBe("");
    }
    expect(wrapper.find('[name="version"]').exists()).toBe(false);
    await wrapper.get("[data-add-interval]").trigger("click");
    await wrapper.get("[data-add-interval]").trigger("click");
    expect(wrapper.findAll("[data-review-interval]")).toHaveLength(2);
    expect(
      wrapper.get<HTMLSelectElement>("#interval-0-weekday").element.value,
    ).toBe("");
    expect(
      wrapper.get<HTMLInputElement>("#interval-0-start").element.value,
    ).toBe("");
    await wrapper.get('[data-remove-interval="0"]').trigger("click");
    expect(wrapper.findAll("[data-review-interval]")).toHaveLength(1);
  });

  it("submits real transport state, blocks repeat submission, and refreshes version after successful save", async () => {
    const wrapper = render({
      state: "configured",
      settings: saved,
      version: 1,
    });
    await wrapper.get("#booking_horizon_days").setValue("45");
    await wrapper.get("form").trigger("submit");
    await wrapper.get("form").trigger("submit");
    expect(inertia.calls).toHaveLength(1);
    expect(inertia.calls[0].url).toBe("/admin/settings/booking");
    expect(inertia.calls[0].data.version).toBe(1);
    expect(
      wrapper.get('button[type="submit"]').attributes("disabled"),
    ).toBeDefined();
    const options = inertia.calls[0].options;
    (options.onSuccess as (page: unknown) => void)({
      props: {
        settings: { ...saved, version: 2, booking_horizon_days: 45 },
        version: 2,
      },
    });
    (options.onFinish as () => void)();
    await wrapper.vm.$nextTick();
    await wrapper.get("form").trigger("submit");
    expect(inertia.calls[1].data.version).toBe(2);
  });

  it("keeps failed drafts and stale version with reload instruction and exact nested validation messages", async () => {
    const wrapper = render({
      state: "configured",
      settings: saved,
      version: 1,
    });
    await wrapper.get("#booking_horizon_days").setValue("70");
    await wrapper.get("form").trigger("submit");
    const options = inertia.calls[0].options;
    (options.onError as (e: unknown) => void)({
      version: "Settings have changed. Reload and review.",
      "review_intervals.0.end": "End must follow start.",
    });
    (options.onFinish as () => void)();
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get<HTMLInputElement>("#booking_horizon_days").element.value,
    ).toBe("70");
    expect(wrapper.get('[role="alert"]').text()).toContain(
      "End must follow start.",
    );
    expect(wrapper.get('[role="alert"] a').text()).toContain("Reload");
    expect(wrapper.get("#interval-0-end").attributes("aria-invalid")).toBe(
      "true",
    );
    await wrapper.get("form").trigger("submit");
    expect(inertia.calls[1].data.version).toBe(1);
  });

  it("renders unavailable without a save form and localized correction/unconfigured states", () => {
    const unavailable = render({ state: "unavailable", version: null });
    expect(unavailable.find("form").exists()).toBe(false);
    expect(unavailable.text()).toContain("unavailable");
    inertia.page.props.locale = "id";
    const invalid = render({ state: "invalid", version: 3 });
    expect(invalid.text()).toContain("tidak valid");
    expect(invalid.get("h1").text()).toBe("Pengaturan pemesanan");
    expect(invalid.find("form").exists()).toBe(true);
    expect(invalid.text()).toContain("Simpan pengaturan");
  });
});
