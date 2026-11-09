import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import Index from "@/pages/admin/staff/Index.vue";
import type {
  AdminFreshTelegramLink,
  AdminStaffPage,
  AdminTelegramLinkChange,
  Locale,
} from "@/types";

const state = vi.hoisted(() => ({
  page: {
    url: "/admin/staff",
    props: {
      locale: "en" as Locale,
      auth: { user: { name: "Owner", email: "owner@test" }, isOwner: true },
      flash: {},
      errors: {} as Record<string, string>,
    },
  },
  calls: [] as Array<{ method: string; url: string }>,
  options: {} as Record<string, (...args: any[]) => unknown>,
}));
vi.mock("@inertiajs/vue3", async (original) => {
  const actual = await original<typeof import("@inertiajs/vue3")>();
  const { reactive, defineComponent, h } = await import("vue");
  state.page = reactive(state.page);
  vi.spyOn(actual.router, "post").mockImplementation((url, _data, options) => {
    state.calls.push({ method: "post", url: String(url) });
    state.options = options as typeof state.options;
  });
  vi.spyOn(actual.router, "delete").mockImplementation((url, options) => {
    state.calls.push({ method: "delete", url: String(url) });
    state.options = options as typeof state.options;
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
    id: 10,
    name: "None",
    email: "none@test",
    role: "admin" as const,
    verified: true,
    canManageBookings: true,
    telegram: { state: "none" as const },
  },
  {
    id: 11,
    name: "Waiting",
    email: "waiting@test",
    role: "admin" as const,
    verified: true,
    canManageBookings: true,
    telegram: {
      state: "waiting" as const,
      enrollmentId: 7,
      expiresAt: "2026-11-08T03:25:00Z",
    },
  },
  {
    id: 12,
    name: "Claimed",
    email: "claimed@test",
    role: "admin" as const,
    verified: true,
    canManageBookings: true,
    telegram: {
      state: "claimed" as const,
      enrollmentId: 8,
      identity: "1111",
      claimedAt: "2026-11-08T03:00:00Z",
    },
  },
  {
    id: 13,
    name: "Linked",
    email: "linked@test",
    role: "admin" as const,
    verified: true,
    canManageBookings: true,
    telegram: {
      state: "linked" as const,
      identity: "0123",
      linkedAt: "2026-11-08T03:00:00Z",
    },
  },
  {
    id: 14,
    name: "Demoted",
    email: "demoted@test",
    role: "admin" as const,
    verified: true,
    canManageBookings: false,
    telegram: {
      state: "linked" as const,
      identity: "4444",
      linkedAt: "2026-11-08T03:00:00Z",
    },
  },
  {
    id: 15,
    name: "NoPermission",
    email: "nopermission@test",
    role: "admin" as const,
    verified: true,
    canManageBookings: false,
    telegram: { state: "none" as const },
  },
  {
    id: 16,
    name: "NoTelegram",
    email: "notelegram@test",
    role: "admin" as const,
    verified: true,
    canManageBookings: true,
  },
];
const page: AdminStaffPage = {
  data: rows,
  current_page: 1,
  last_page: 1,
  total: rows.length,
  prev_page_url: null,
  next_page_url: null,
};
const wrappers: ReturnType<typeof mount>[] = [];

function screen(
  overrides: {
    staff?: AdminStaffPage;
    telegramHistory?: AdminTelegramLinkChange[];
    telegramBotConfigured?: boolean;
    freshTelegramLink?: AdminFreshTelegramLink | null;
  } = {},
) {
  const wrapper = mount(Index, {
    props: {
      staff: page,
      telegramHistory: [],
      telegramBotConfigured: true,
      freshTelegramLink: null,
      ...overrides,
    },
    attachTo: document.body,
  });
  wrappers.push(wrapper);
  return wrapper;
}

beforeEach(() => {
  state.page.props.locale = "en";
  state.page.props.errors = {};
  state.calls = [];
  state.options = {};
});

afterEach(async () => {
  wrappers.splice(0).forEach((w) => w.unmount());
  await flushPromises();
  document.body.innerHTML = "";
});

it("shows each Telegram state with the right controls", () => {
  const wrapper = screen();

  const claimed = wrapper.get(
    "[data-staff-id='12'] [data-telegram-state='claimed']",
  );
  expect(claimed.text()).toContain("ending 1111");
  expect(claimed.text()).toContain("Check with Claimed");

  const linked = wrapper.get(
    "[data-staff-id='13'] [data-telegram-state='linked']",
  );
  expect(linked.text()).toContain("ending 0123");
  expect(
    wrapper.find("[data-staff-id='13'] [data-telegram-issue]").exists(),
  ).toBe(false);

  expect(
    wrapper.find("[data-staff-id='14'] [data-telegram-warning]").exists(),
  ).toBe(true);
  expect(
    wrapper.find("[data-staff-id='13'] [data-telegram-warning]").exists(),
  ).toBe(false);

  expect(wrapper.get("[data-staff-id='10'] [data-telegram-issue]").text()).toBe(
    "Create Telegram link",
  );
  expect(wrapper.get("[data-staff-id='11'] [data-telegram-issue]").text()).toBe(
    "Create a new Telegram link",
  );
  expect(wrapper.get("[data-staff-id='12'] [data-telegram-issue]").text()).toBe(
    "Create a new Telegram link",
  );
  expect(
    wrapper.find("[data-staff-id='15'] [data-telegram-issue]").exists(),
  ).toBe(false);

  expect(
    wrapper.find("[data-staff-id='12'] [data-telegram-activate]").exists(),
  ).toBe(true);
  for (const id of [10, 11, 13, 14, 15]) {
    expect(
      wrapper.find(`[data-staff-id='${id}'] [data-telegram-activate]`).exists(),
    ).toBe(false);
  }

  for (const id of [11, 12, 13, 14]) {
    expect(
      wrapper.find(`[data-staff-id='${id}'] [data-telegram-revoke]`).exists(),
    ).toBe(true);
  }
  expect(
    wrapper.find("[data-staff-id='10'] [data-telegram-revoke]").exists(),
  ).toBe(false);

  expect(
    wrapper.find("[data-staff-id='16'] [data-telegram-state]").exists(),
  ).toBe(false);
});

it("activates only after a confirmation that names the account", async () => {
  const wrapper = screen();

  await wrapper
    .get("[data-staff-id='12'] [data-telegram-activate]")
    .trigger("click");
  await flushPromises();

  expect(state.calls).toEqual([]);
  const confirm = wrapper.get("[data-staff-id='12'] [data-telegram-confirm]");
  expect(confirm.text()).toContain(
    "Activate the Telegram account ending 1111 for Claimed?",
  );

  await confirm.get("[data-telegram-confirm-button]").trigger("click");

  expect(state.calls).toEqual([
    { method: "post", url: "/admin/telegram-enrollments/8/activate" },
  ]);
});

it("removes after confirmation and creates links at once", async () => {
  const wrapper = screen();

  await wrapper
    .get("[data-staff-id='13'] [data-telegram-revoke]")
    .trigger("click");
  await flushPromises();
  expect(state.calls).toEqual([]);
  expect(wrapper.get("[data-telegram-confirm]").text()).toContain(
    "Remove the Telegram link for Linked?",
  );
  await wrapper
    .get("[data-staff-id='13'] [data-telegram-confirm-button]")
    .trigger("click");

  expect(state.calls).toEqual([
    { method: "delete", url: "/admin/staff/13/telegram-link" },
  ]);

  await state.options.onFinish?.({});
  await flushPromises();

  const issue = wrapper.get("[data-staff-id='10'] [data-telegram-issue]");
  await issue.trigger("click");

  expect(state.calls).toEqual([
    { method: "delete", url: "/admin/staff/13/telegram-link" },
    { method: "post", url: "/admin/staff/10/telegram-enrollment" },
  ]);
  expect(
    wrapper.find("[data-staff-id='10'] [data-telegram-confirm]").exists(),
  ).toBe(false);
});

it("shows the one-time link only for its staff member", () => {
  const fresh: AdminFreshTelegramLink = {
    staffId: 11,
    command: "/start abc",
    url: "https://t.me/ShinJoyStaffBot?start=abc",
    expiresAt: "2026-11-08T03:25:00Z",
  };
  const wrapper = screen({ freshTelegramLink: fresh });

  expect(wrapper.findAll("[data-telegram-fresh]")).toHaveLength(1);
  expect(
    wrapper.find("[data-staff-id='10'] [data-telegram-fresh]").exists(),
  ).toBe(false);
  const panel = wrapper.get("[data-staff-id='11'] [data-telegram-fresh]");
  expect(panel.text()).toContain("/start abc");
  expect(panel.text()).toContain("expires on 8 Nov 2026, 10:25 WIB");
  expect(panel.text()).toContain("Or send the bot this message:");
  const link = panel.get("a");
  expect(link.attributes("href")).toBe(
    "https://t.me/ShinJoyStaffBot?start=abc",
  );
  expect(link.attributes("rel")).toBe("noopener noreferrer");

  const noUrl = screen({
    freshTelegramLink: { ...fresh, url: null },
    telegramBotConfigured: false,
  });
  const plain = noUrl.get("[data-staff-id='11'] [data-telegram-fresh]");
  expect(plain.find("a").exists()).toBe(false);
  expect(plain.text()).toContain("Set TELEGRAM_BOT_USERNAME");
  expect(plain.text()).toContain("Send the bot this message:");
  expect(plain.text()).not.toContain("Or send");
});

it("asks to cancel a link that was never activated", async () => {
  const wrapper = screen();

  await wrapper
    .get("[data-staff-id='12'] [data-telegram-revoke]")
    .trigger("click");
  await flushPromises();

  expect(wrapper.get("[data-telegram-confirm]").text()).toContain(
    "Cancel the Telegram link for Claimed?",
  );
});

it("offers activation only to accounts that can be linked", () => {
  const blocked = { ...rows[2], canManageBookings: false };
  const wrapper = screen({ staff: { ...page, data: [blocked] } });

  expect(wrapper.find("[data-telegram-activate]").exists()).toBe(false);
  expect(wrapper.get("[data-telegram-cannot-activate]").text()).toContain(
    "verified and allowed to manage bookings",
  );
  expect(wrapper.find("[data-telegram-revoke]").exists()).toBe(true);
  expect(
    screen()
      .find("[data-staff-id='12'] [data-telegram-cannot-activate]")
      .exists(),
  ).toBe(false);
});

it("shows a refusal or failure in the row that was acted on", async () => {
  const wrapper = screen();

  await wrapper
    .get("[data-staff-id='10'] [data-telegram-issue]")
    .trigger("click");
  state.page.props.errors = { staff: "Refused here." };
  await state.options.onFinish?.({});
  await flushPromises();

  expect(wrapper.findAll("[data-telegram-row-error]")).toHaveLength(1);
  expect(
    wrapper.get("[data-staff-id='10'] [data-telegram-row-error]").text(),
  ).toBe("Refused here.");
  expect(wrapper.find("[data-telegram-errors]").exists()).toBe(false);

  state.page.props.errors = {};
  await wrapper
    .get("[data-staff-id='10'] [data-telegram-issue]")
    .trigger("click");
  expect(state.options.onHttpException?.({})).toBe(false);
  await state.options.onFinish?.({});
  await flushPromises();
  expect(
    wrapper.get("[data-staff-id='10'] [data-telegram-row-error]").text(),
  ).toContain("We could not save this change.");

  await wrapper
    .get("[data-staff-id='13'] [data-telegram-revoke]")
    .trigger("click");
  await flushPromises();
  await wrapper.get("[data-telegram-confirm-button]").trigger("click");
  expect(
    wrapper.find("[data-staff-id='10'] [data-telegram-row-error]").exists(),
  ).toBe(false);
  expect(state.options.onNetworkError?.(new Error("offline"))).toBe(false);
  await state.options.onFinish?.({});
  await flushPromises();
  expect(
    wrapper.get("[data-staff-id='13'] [data-telegram-row-error]").text(),
  ).toContain("We could not save this change.");
});

it("returns focus to the control that started the action", async () => {
  const wrapper = screen();
  const activate = wrapper.get("[data-staff-id='12'] [data-telegram-activate]");

  await activate.trigger("click");
  await flushPromises();
  await wrapper
    .get("[data-telegram-confirm-button]")
    .trigger("keydown", { key: "Escape" });
  await flushPromises();
  expect(wrapper.find("[data-telegram-confirm]").exists()).toBe(false);
  expect(document.activeElement).toBe(activate.element);

  const revoke = wrapper.get("[data-staff-id='13'] [data-telegram-revoke]");
  await revoke.trigger("click");
  await flushPromises();
  await wrapper.get("[data-telegram-cancel]").trigger("click");
  await flushPromises();
  expect(document.activeElement).toBe(revoke.element);
  expect(state.calls).toEqual([]);

  await activate.trigger("click");
  await flushPromises();
  await wrapper.get("[data-telegram-confirm-button]").trigger("click");
  await wrapper.setProps({
    staff: {
      ...page,
      data: rows.map((row) =>
        row.id === 12
          ? {
              ...row,
              telegram: {
                state: "linked" as const,
                identity: "1111",
                linkedAt: "2026-11-08T03:05:00Z",
              },
            }
          : row,
      ),
    },
  });
  await state.options.onFinish?.({});
  await flushPromises();
  expect(document.activeElement).toBe(
    wrapper.get("[data-staff-id='12'] [data-telegram-state]").element,
  );
});

it("shows refusals and history", () => {
  state.page.props.errors = {
    telegram:
      "This Telegram account is already linked to another staff account.",
  };
  const wrapper = screen();
  expect(wrapper.get("[data-telegram-errors]").text()).toContain(
    "This Telegram account is already linked to another staff account.",
  );

  const withHistory = screen({
    telegramHistory: [
      {
        action: "revoked",
        targetName: "Gone",
        targetDeleted: true,
        identity: "9999",
        actorName: "Owner",
        at: "2026-11-08T03:00:00Z",
      },
    ],
  });
  const history = withHistory.get("[data-telegram-history]").text();
  expect(history).toContain(
    "Owner removed the Telegram link ending 9999 for Gone",
  );
  expect(history).toContain("(account deleted)");

  const empty = screen();
  expect(empty.get("[data-telegram-history]").text()).toContain(
    "No changes yet.",
  );
});
