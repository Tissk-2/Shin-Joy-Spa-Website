import { mount } from "@vue/test-utils";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { beforeEach, describe, expect, it, vi } from "vitest";

import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type { Locale } from "@/types";

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
      catalog: { packages: [] },
    },
  },
  posts: [] as Array<{
    url: string;
    data: Record<string, unknown>;
    options: Record<string, unknown>;
  }>,
  finishLogout: undefined as (() => void) | undefined,
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  const page = reactive(inertia.page);
  inertia.page = page;

  return {
    Head: "span",
    Link: "a",
    router: {
      post: vi.fn(
        (
          url: string,
          data: Record<string, unknown>,
          options: Record<string, unknown> = {},
        ) => {
          inertia.posts.push({ url, data, options });
          if (url === "/logout") {
            inertia.finishLogout = options.onFinish as () => void;
          }
        },
      ),
    },
    usePage: () => inertia.page,
  };
});

describe("administrator layout", () => {
  beforeEach(() => {
    inertia.page.url = "/admin/bookings";
    inertia.page.props.locale = "en";
    inertia.posts = [];
    inertia.finishLogout = undefined;
  });

  it("renders the administrator identity and the booking workspace landmarks", () => {
    const wrapper = mount(AdminLayout, {
      props: {
        title: "Bookings",
        subtitle: "Review and update guest reservations.",
      },
      slots: { default: "<p data-admin-content>Guest booking list</p>" },
    });

    expect(wrapper.get('a[href="#main"]').text()).toBe("Skip to content");
    expect(wrapper.get("aside").text()).toContain("Admin Panel");
    expect(wrapper.get("[data-admin-identity]").text()).toContain(
      "Rani Kusuma",
    );
    expect(wrapper.get("[data-admin-identity]").text()).toContain(
      "rani@shinjoy.test",
    );
    expect(
      wrapper.get('nav a[href="/admin/bookings"]').attributes("aria-current"),
    ).toBe("page");
    expect(wrapper.get('a[href="/"]').text()).toBe("View Website");
    expect(wrapper.get("h1").text()).toBe("Bookings");
    expect(wrapper.get("header p:last-of-type").text()).toBe(
      "Review and update guest reservations.",
    );
    expect(wrapper.get("main#main [data-admin-content]").text()).toBe(
      "Guest booking list",
    );
  });

  it("offers paired locale controls through the server locale action", async () => {
    const wrapper = mount(AdminLayout, { props: { title: "Bookings" } });
    const localeGroup = wrapper.get('[role="group"]');

    expect(localeGroup.attributes("aria-label")).toBe("Language selection");
    expect(
      wrapper.get('button[data-admin-locale="id"]').attributes("aria-pressed"),
    ).toBe("false");
    expect(
      wrapper.get('button[data-admin-locale="en"]').attributes("aria-pressed"),
    ).toBe("true");

    await wrapper.get('button[data-admin-locale="id"]').trigger("click");
    await wrapper.get('button[data-admin-locale="en"]').trigger("click");

    expect(inertia.posts.map(({ url, data }) => ({ url, data }))).toEqual([
      { url: "/locale", data: { locale: "id" } },
      { url: "/locale", data: { locale: "en" } },
    ]);
  });

  it("submits logout through Fortify and disables repeat submission", async () => {
    const wrapper = mount(AdminLayout, { props: { title: "Bookings" } });
    const logout = wrapper.get("button[data-admin-logout]");

    await logout.trigger("click");
    await logout.trigger("click");

    expect(inertia.posts.map(({ url, data }) => ({ url, data }))).toEqual([
      { url: "/logout", data: {} },
    ]);
    expect(logout.attributes("disabled")).toBeDefined();

    inertia.finishLogout?.();
    await wrapper.vm.$nextTick();
    expect(logout.attributes("disabled")).toBeUndefined();
  });

  it("keeps touch-sized controls and keyboard focus visible without client routing or storage", () => {
    const wrapper = mount(AdminLayout, { props: { title: "Bookings" } });
    const controls = wrapper.findAll("a, button");

    expect(controls.length).toBeGreaterThan(0);
    for (const control of controls) {
      expect(control.classes().join(" ")).toContain("min-h-11");
      expect(control.classes().join(" ")).toContain("focus-visible:");
    }

    const source = readFileSync(
      resolve(
        import.meta.dirname,
        "../../resources/js/layouts/admin/AdminLayout.vue",
      ),
      "utf8",
    );
    expect(source).not.toMatch(
      /localStorage|sessionStorage|vue-router|RouterLink|useRoute|useAuth/,
    );
  });
});
