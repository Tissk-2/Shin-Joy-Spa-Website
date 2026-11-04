import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import Index from "@/pages/admin/staff/Index.vue";
import type { AdminStaffPage, Locale } from "@/types";

const state = vi.hoisted(() => ({
  page: {
    url: "/admin/staff",
    props: {
      locale: "en" as Locale,
      auth: { user: { name: "Owner", email: "owner@test" }, isOwner: true },
      flash: {},
    },
  },
  patches: [] as Array<{ url: string; data: unknown }>,
  options: {} as Record<string, (...args: any[]) => unknown>,
}));
vi.mock("@inertiajs/vue3", async (original) => {
  const actual = await original<typeof import("@inertiajs/vue3")>();
  const { reactive, defineComponent, h } = await import("vue");
  state.page = reactive(state.page);
  vi.spyOn(actual.router, "patch").mockImplementation((url, data, options) => {
    state.patches.push({ url: String(url), data });
    state.options = options as typeof state.options;
    state.options.onStart?.({});
  });
  return {
    ...actual,
    usePage: () => state.page,
    Head: "span",
    Link: defineComponent({
      props: { href: String },
      setup:
        (props, { slots }) =>
        () =>
          h("a", { href: props.href }, slots.default?.()),
    }),
  };
});
const rows = [
  {
    id: 1,
    name: "Owner",
    email: "owner@test",
    role: "owner" as const,
    verified: true,
    canManageBookings: true,
  },
  {
    id: 2,
    name: "Ayu <script>",
    email: "ayu@test",
    role: "admin" as const,
    verified: true,
    canManageBookings: false,
  },
  {
    id: 3,
    name: "Unverified",
    email: "unverified@test",
    role: "admin" as const,
    verified: false,
    canManageBookings: false,
  },
  {
    id: 4,
    name: "Capable",
    email: "capable@test",
    role: "admin" as const,
    verified: false,
    canManageBookings: true,
  },
];
const page: AdminStaffPage = {
  data: rows,
  current_page: 1,
  last_page: 2,
  total: 22,
  prev_page_url: null,
  next_page_url: "/admin/staff?page=2",
};
const wrappers: ReturnType<typeof mount>[] = [];
function screen(data = page) {
  const wrapper = mount(Index, {
    props: { staff: data },
    attachTo: document.body,
  });
  wrappers.push(wrapper);
  return wrapper;
}
const dialog = () =>
  new DOMWrapper(document.querySelector<HTMLElement>("[role='alertdialog']")!);
beforeEach(() => {
  state.page.props.auth.isOwner = true;
  state.page.props.locale = "en";
  state.patches = [];
  state.options = {};
});
afterEach(async () => {
  wrappers.splice(0).forEach((w) => w.unmount());
  await flushPromises();
  document.body.innerHTML = "";
});

it("lists safe staff, owners, verification and pagination, disabling only ineligible grants", () => {
  const wrapper = screen();
  expect(wrapper.text()).toContain("Ayu <script>");
  expect(wrapper.find("main script").exists()).toBe(false);
  expect(wrapper.find("[data-staff-id='1'] button").exists()).toBe(false);
  expect(
    wrapper.get("[data-staff-id='3'] button").attributes("disabled"),
  ).toBeDefined();
  expect(
    wrapper.get("[data-staff-id='4'] button").attributes("disabled"),
  ).toBeUndefined();
  expect(wrapper.get("[data-staff-next]").attributes("href")).toBe(
    "/admin/staff?page=2",
  );
});
it("shows empty state and owner-only navigation", () => {
  const wrapper = screen({
    ...page,
    data: [],
    total: 0,
    last_page: 1,
    next_page_url: null,
  });
  expect(wrapper.text()).toContain("No staff accounts");
  expect(wrapper.find("nav a[href='/admin/staff']").exists()).toBe(true);
  state.page.props.auth.isOwner = false;
});
it.each([
  [2, true],
  [4, false],
] as const)(
  "confirms person and submits explicit permission for %s",
  async (id, allowed) => {
    const wrapper = screen();
    await wrapper.get(`[data-staff-id='${id}'] button`).trigger("click");
    await flushPromises();
    expect(dialog().text()).toContain(id === 2 ? "ayu@test" : "capable@test");
    expect(dialog().text()).toContain(
      allowed ? "Grant booking management" : "Revoke booking management",
    );
    expect(document.activeElement).toBe(dialog().get("h2").element);
    await dialog().get("form").trigger("submit");
    expect(state.patches).toEqual([
      { url: `/admin/staff/${id}/booking-permission`, data: { allowed } },
    ]);
    await flushPromises();
    expect(
      dialog().get("button[type='submit']").attributes("disabled"),
    ).toBeDefined();
    await dialog().get("form").trigger("submit");
    expect(state.patches).toHaveLength(1);
    await state.options.onSuccess?.({ props: {} });
    await state.options.onFinish?.({});
    await wrapper.setProps({
      staff: {
        ...page,
        data: rows.map((row) =>
          row.id === id ? { ...row, canManageBookings: allowed } : row,
        ),
      },
    });
    await flushPromises();
    expect(document.querySelector("[role='alertdialog']")).toBeNull();
    expect(wrapper.get(`[data-staff-id='${id}']`).text()).toContain(
      allowed ? "Allowed" : "Not allowed",
    );
  },
);
it("keeps server errors accessible and permits retry", async () => {
  const wrapper = screen();
  await wrapper.get("[data-staff-id='2'] button").trigger("click");
  await flushPromises();
  await dialog().get("form").trigger("submit");
  await state.options.onError?.({
    allowed: "Permission changed. Review this person.",
  });
  await state.options.onFinish?.({});
  await flushPromises();
  expect(dialog().get("[role='alert']").text()).toContain("Permission changed");
  expect(dialog().element.contains(document.activeElement)).toBe(true);
  expect(
    dialog().get("button[type='submit']").attributes("disabled"),
  ).toBeUndefined();
});
it("localizes staff and hides the navigation for ordinary admins", async () => {
  state.page.props.auth.isOwner = false;
  state.page.props.locale = "id";
  const wrapper = screen();
  expect(wrapper.find("nav a[href='/admin/staff']").exists()).toBe(false);
  expect(wrapper.text()).toContain("Izin pemesanan");
});
it.each(["onHttpException", "onNetworkError"])(
  "keeps %s failures accessible and retryable",
  async (callback) => {
    const wrapper = screen();
    await wrapper.get("[data-staff-id='2'] button").trigger("click");
    await flushPromises();
    await dialog().get("form").trigger("submit");
    expect(state.options[callback]).toBeTypeOf("function");
    await state.options[callback]?.({});
    await state.options.onFinish?.({});
    await flushPromises();
    expect(dialog().get("[role='alert']").text()).toContain("could not save");
    expect(dialog().element.contains(document.activeElement)).toBe(true);
    expect(
      dialog().get("button[type='submit']").attributes("disabled"),
    ).toBeUndefined();
  },
);
