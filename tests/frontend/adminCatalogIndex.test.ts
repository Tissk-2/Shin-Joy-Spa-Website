import { mount } from "@vue/test-utils";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { beforeEach, describe, expect, it, vi } from "vitest";

import CatalogStatusBadge from "@/components/admin/catalog/CatalogStatusBadge.vue";
import CatalogIndex from "@/pages/admin/catalog/Index.vue";
import type { Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/admin/catalog",
    props: {
      auth: { user: null, canAccessAdmin: true },
      locale: "en" as Locale,
      flash: {},
      catalog: { packages: [] },
    },
  },
  posts: [] as Array<{ url: string; data: Record<string, unknown> }>,
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  inertia.page = reactive(inertia.page);

  return {
    Head: "span",
    Link: "a",
    router: {
      post: vi.fn((url: string, data: Record<string, unknown>) => {
        inertia.posts.push({ url, data });
      }),
    },
    usePage: () => inertia.page,
  };
});

const packages = [
  {
    version: 13,
    slug: "warm-stone",
    categorySlug: "body-care",
    nameId: "Batu Hangat",
    nameEn: "Warm Stone",
    image: null,
    gallery: [],
    isVip: true,
    status: "published" as const,
    variantCount: 2,
    extensionCount: 1,
    optionCount: 3,
    displayOrder: 1,
    can: {
      update: true,
      publish: false,
      archive: true,
      restore: false,
      delete: false,
    },
  },
  {
    version: 14,
    slug: "calm-facial",
    categorySlug: "face-care",
    nameId: "Facial Tenang",
    nameEn: "Calm Facial",
    image: null,
    gallery: [],
    isVip: false,
    status: "published" as const,
    variantCount: 1,
    extensionCount: 0,
    optionCount: 1,
    displayOrder: 2,
    can: {
      update: false,
      publish: false,
      archive: false,
      restore: false,
      delete: false,
    },
  },
] as const;

function mountIndex(
  props: Partial<InstanceType<typeof CatalogIndex>["$props"]> = {},
  attach = false,
) {
  return mount(CatalogIndex, {
    props: {
      packages,
      ...props,
    },
    global: {
      stubs: {
        AdminLayout: {
          template: "<section><slot /></section>",
        },
      },
    },
    attachTo: attach ? document.body : undefined,
  });
}

describe("admin catalog index", () => {
  beforeEach(() => {
    inertia.page.props.locale = "en";
    inertia.posts = [];
  });

  it("labels every lifecycle state with text as well as status semantics", () => {
    for (const [status, label] of [
      ["draft", "Draft"],
      ["published", "Published"],
      ["archived", "Archived"],
    ] as const) {
      const wrapper = mount(CatalogStatusBadge, { props: { status } });
      expect(wrapper.text()).toBe(label);
      expect(wrapper.attributes("role")).toBe("status");
      expect(wrapper.attributes("data-catalog-status")).toBe(status);
    }
  });

  it("orders localized primary and secondary names with their counts", async () => {
    const wrapper = mountIndex();
    expect(wrapper.get('[data-catalog-package="warm-stone"]').text()).toContain(
      "Warm Stone",
    );
    expect(wrapper.text()).toContain("3 options");
    expect(wrapper.text()).toContain("VIP");

    inertia.page.props.locale = "id";
    await wrapper.vm.$nextTick();
    expect(wrapper.get('[data-catalog-package="warm-stone"]').text()).toContain(
      "Batu Hangat",
    );
    expect(wrapper.text()).toContain("3 opsi");
  });

  it("renders a single package without relying on a many-record layout", () => {
    const wrapper = mountIndex({
      packages: packages.slice(0, 1),
    });

    expect(wrapper.findAll("[data-catalog-package]")).toHaveLength(1);
  });

  it("shows permitted package controls only and offers no create or category actions", () => {
    const wrapper = mountIndex();
    expect(
      wrapper.get('a[href="/admin/catalog/packages/warm-stone/edit"]'),
    ).toBeTruthy();
    expect(
      wrapper
        .find('a[href="/admin/catalog/packages/calm-facial/edit"]')
        .exists(),
    ).toBe(false);
    expect(
      wrapper.get('[data-catalog-action="warm-stone-archive"]'),
    ).toBeTruthy();
    expect(
      wrapper.find('[data-catalog-action="calm-facial-publish"]').exists(),
    ).toBe(false);
    expect(
      wrapper.find('a[href="/admin/catalog/packages/create"]').exists(),
    ).toBe(false);
    expect(wrapper.find("[data-catalog-create]").exists()).toBe(false);
    expect(wrapper.find("[data-catalog-category]").exists()).toBe(false);
  });

  it("sends a package lifecycle action with the projected nonzero version", async () => {
    const wrapper = mountIndex({}, true);
    await wrapper
      .get('[data-catalog-action="warm-stone-archive"]')
      .trigger("click");
    document
      .querySelector<HTMLButtonElement>("[data-lifecycle-confirm]")!
      .click();
    expect(inertia.posts).toEqual([
      {
        url: "/admin/catalog/packages/warm-stone/archive",
        data: { version: 13 },
      },
    ]);
    wrapper.unmount();
  });

  it("announces an accessible empty state and offers no create hooks for zero packages", () => {
    const wrapper = mountIndex({ packages: [] });
    expect(
      wrapper.get("[data-catalog-empty=packages]").attributes("role"),
    ).toBe("status");
    expect(wrapper.find("[data-catalog-create]").exists()).toBe(false);
    expect(
      wrapper.find('a[href="/admin/catalog/packages/create"]').exists(),
    ).toBe(false);
  });

  it("keeps catalog interactions keyboard reachable and avoids disallowed client-owned behavior", () => {
    const wrapper = mountIndex({}, true);
    const controls = wrapper.findAll<HTMLElement>("a, button");
    for (const control of controls) {
      control.element.focus();
      expect(document.activeElement).toBe(control.element);
      expect(control.classes().join(" ")).toContain("catalog-control");
    }

    const source = readFileSync(
      resolve(
        import.meta.dirname,
        "../../resources/js/pages/admin/catalog/Index.vue",
      ),
      "utf8",
    );
    expect(source).not.toMatch(/localStorage|sessionStorage|vue-router|v-html/);
    wrapper.unmount();
  });
});
