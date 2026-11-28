import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import { staffGuide } from "@/data/staffGuide";
import Guide from "@/pages/admin/Guide.vue";
import type { Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/admin/guide",
    props: {
      auth: {
        user: { name: "Rani", email: "rani@shinjoy.test" },
        canAccessAdmin: true,
        isOwner: false,
      },
      locale: "id" as Locale,
      flash: {},
      errors: {},
      adminNav: { pendingBookings: 0 },
    },
  },
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive, defineComponent, h } = await import("vue");
  inertia.page = reactive(inertia.page);
  return {
    Head: "span",
    usePage: () => inertia.page,
    router: { post: vi.fn() },
    Link: defineComponent({
      props: { href: String },
      setup:
        (props, { slots }) =>
        () =>
          h("a", { href: props.href }, slots.default?.()),
    }),
  };
});

function mountGuide(
  props: Partial<{
    roomSchedulingEnabled: boolean;
    customerNoticesEnabled: boolean;
    telegramActionsEnabled: boolean;
    canCreateBooking: boolean;
  }> = {},
) {
  return mount(Guide, {
    props: {
      roomSchedulingEnabled: true,
      customerNoticesEnabled: true,
      telegramActionsEnabled: false,
      canCreateBooking: true,
      ...props,
    },
  });
}

beforeEach(() => {
  inertia.page.props.locale = "id";
  inertia.page.props.auth.isOwner = false;
});

describe("admin staff guide page", () => {
  it("shows live feature flags and operational procedures in Indonesian and English", async () => {
    const wrapper = mountGuide();

    expect(wrapper.get("h1").text()).toBe("Panduan staf");
    expect(wrapper.get('[data-guide-flag="scheduling"]').text()).toContain(
      "Aktif",
    );
    expect(wrapper.get('[data-guide-flag="telegram"]').text()).toContain(
      "Nonaktif",
    );
    expect(wrapper.find('[data-guide-section="requests"]').exists()).toBe(true);
    expect(wrapper.find('[data-guide-section="owner"]').exists()).toBe(false);
    expect(wrapper.find('a[href="/admin/bookings/create"]').exists()).toBe(
      true,
    );

    inertia.page.props.locale = "en";
    await nextTick();
    expect(wrapper.get("h1").text()).toBe("Staff guide");
    expect(wrapper.get('[data-guide-flag="scheduling"]').text()).toContain(
      "On",
    );
    expect(wrapper.get('[data-guide-flag="telegram"]').text()).toContain("Off");
  });

  it("shows owner-only procedures only for owners and hides manual-booking link when unauthorized", () => {
    inertia.page.props.auth.isOwner = true;
    const ownerView = mountGuide({ canCreateBooking: false });

    expect(ownerView.find('[data-guide-section="owner"]').exists()).toBe(true);
    expect(ownerView.find('a[href="/admin/staff"]').exists()).toBe(true);
    expect(ownerView.find('a[href="/admin/bookings/create"]').exists()).toBe(
      false,
    );
  });

  it("keeps terminal and developer commands out of staff-facing copy", () => {
    for (const locale of ["id", "en"] as const) {
      const text = JSON.stringify(staffGuide[locale]);
      expect(text).not.toMatch(/php artisan|composer|migrate|systemctl/i);
    }
  });
});
