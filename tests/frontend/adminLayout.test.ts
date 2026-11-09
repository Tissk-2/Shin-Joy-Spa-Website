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
        isOwner: false,
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
    inertia.page.props.auth.isOwner = false;
    inertia.page.props.locale = "en";
    inertia.posts = [];
    inertia.finishLogout = undefined;
    document.body.innerHTML = "";
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

  it("shows booking settings only for the verified owner navigation ability", async () => {
    const wrapper = mount(AdminLayout, { props: { title: "Bookings" } });
    expect(wrapper.find('a[href="/admin/settings/booking"]').exists()).toBe(
      false,
    );
    inertia.page.props.auth.isOwner = true;
    inertia.page.url = "/admin/settings/booking";
    await wrapper.vm.$nextTick();
    expect(
      wrapper
        .get('a[href="/admin/settings/booking"]')
        .attributes("aria-current"),
    ).toBe("page");
    expect(wrapper.get('a[href="/admin/settings/booking"]').text()).toBe(
      "Booking settings",
    );
  });

  it("keeps Catalog as a separate admin destination and marks only the current section", async () => {
    inertia.page.url = "/admin/catalog";
    const wrapper = mount(AdminLayout, { props: { title: "Catalog" } });

    const bookings = wrapper.get('nav a[href="/admin/bookings"]');
    const catalog = wrapper.get('nav a[href="/admin/catalog"]');

    expect(bookings.attributes("aria-current")).toBeUndefined();
    expect(catalog.text()).toBe("Catalog");
    expect(catalog.attributes("aria-current")).toBe("page");

    inertia.page.url = "/admin/bookings";
    await wrapper.vm.$nextTick();
    expect(bookings.attributes("aria-current")).toBe("page");
    expect(catalog.attributes("aria-current")).toBeUndefined();
  });

  it("keeps the matching section current for bounded child paths only", async () => {
    const wrapper = mount(AdminLayout, { props: { title: "Catalog" } });
    const bookings = wrapper.get('nav a[href="/admin/bookings"]');
    const catalog = wrapper.get('nav a[href="/admin/catalog"]');

    inertia.page.url = "/admin/bookings/SJ-2026-001";
    await wrapper.vm.$nextTick();
    expect(bookings.attributes("aria-current")).toBe("page");
    expect(catalog.attributes("aria-current")).toBeUndefined();

    inertia.page.url = "/admin/catalog/packages/create";
    await wrapper.vm.$nextTick();
    expect(bookings.attributes("aria-current")).toBeUndefined();
    expect(catalog.attributes("aria-current")).toBe("page");

    inertia.page.url = "/admin/catalogue";
    await wrapper.vm.$nextTick();
    expect(bookings.attributes("aria-current")).toBeUndefined();
    expect(catalog.attributes("aria-current")).toBeUndefined();
  });

  it("keeps exactly one section current across supported booking and catalog URL variants", async () => {
    const wrapper = mount(AdminLayout, { props: { title: "Catalog" } });
    const bookings = wrapper.get('nav a[href="/admin/bookings"]');
    const catalog = wrapper.get('nav a[href="/admin/catalog"]');

    for (const [url, current] of [
      ["/admin/bookings?status=pending", bookings],
      ["/admin/catalog?status=published", catalog],
      ["/admin/catalog/packages/example/edit", catalog],
    ] as const) {
      inertia.page.url = url;
      await wrapper.vm.$nextTick();

      expect(current.attributes("aria-current")).toBe("page");
      expect(
        current === bookings
          ? catalog.attributes("aria-current")
          : bookings.attributes("aria-current"),
      ).toBeUndefined();
    }
  });

  it("links to the room schedule and marks it current", () => {
    inertia.page.url = "/admin/schedule?date=2026-12-01";
    const wrapper = mount(AdminLayout, { props: { title: "Schedule" } });

    const schedule = wrapper.get('nav a[href="/admin/schedule"]');
    const bookings = wrapper.get('nav a[href="/admin/bookings"]');

    expect(schedule.text()).toBe("Schedule");
    expect(schedule.attributes("aria-current")).toBe("page");
    expect(bookings.attributes("aria-current")).toBeUndefined();
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

  it("provides a real keyboard focus path and a focusable skip-link target", () => {
    const wrapper = mount(AdminLayout, {
      props: { title: "Bookings" },
      attachTo: document.body,
    });
    const skipLink = wrapper.get<HTMLAnchorElement>('a[href="#main"]');
    const bookingsLink = wrapper.get<HTMLAnchorElement>(
      'nav a[href="/admin/bookings"]',
    );
    const localeButton = wrapper.get<HTMLButtonElement>(
      'button[data-admin-locale="id"]',
    );
    const logoutButton = wrapper.get<HTMLButtonElement>(
      "button[data-admin-logout]",
    );
    const main = wrapper.get<HTMLElement>("main#main");

    expect(skipLink.attributes("href")).toBe("#main");
    expect(main.attributes("tabindex")).toBe("-1");

    skipLink.element.focus();
    expect(document.activeElement).toBe(skipLink.element);

    // JSDOM does not move focus for native fragment navigation. The concrete
    // contract is a matching fragment target that can receive programmatic focus.
    main.element.focus();
    expect(document.activeElement).toBe(main.element);

    for (const control of [bookingsLink, localeButton, logoutButton]) {
      control.element.focus();
      expect(document.activeElement).toBe(control.element);
    }

    wrapper.unmount();
  });

  it("declares the touch-size and visible-focus styling JSDOM cannot measure", () => {
    const wrapper = mount(AdminLayout, { props: { title: "Bookings" } });
    const controls = wrapper.findAll("a, button");
    const styles = readFileSync("resources/css/admin.css", "utf8");

    expect(controls.length).toBeGreaterThan(0);
    expect(styles).toMatch(
      /\.admin-control,\s*\n\.admin-button,\s*\n\.admin-nav-link\s*\{[^}]*min-height:\s*44px/,
    );
    expect(styles).toMatch(
      /\.admin-shell :is\(a, button, input, select\):focus-visible\s*\{[^}]*outline:/,
    );
  });

  it("keeps client routing and browser auth storage out of the layout", () => {
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
