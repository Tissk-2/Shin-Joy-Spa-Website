import { mount } from "@vue/test-utils";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { beforeEach, describe, expect, it, vi } from "vitest";

import CatalogStatusBadge from "@/components/admin/catalog/CatalogStatusBadge.vue";
import CatalogIndex from "@/pages/admin/catalog/Index.vue";
import type { AppPageProps, Locale } from "@/types";

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

const categories = [
  {
    slug: "body-care",
    nameId: "Perawatan Tubuh",
    nameEn: "Body Care",
    image: null,
    status: "published" as const,
    packageCount: 2,
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
    slug: "face-care",
    nameId: "Perawatan Wajah",
    nameEn: "Face Care",
    image: null,
    status: "draft" as const,
    packageCount: 1,
    displayOrder: 2,
    can: {
      update: false,
      publish: true,
      archive: false,
      restore: false,
      delete: true,
    },
  },
] as const;

const packages = [
  {
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
      categories,
      packages,
      can: { createCategory: true, createPackage: true },
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
    expect(wrapper.get('[data-catalog-category="body-care"]').text()).toContain(
      "Body Care",
    );
    expect(wrapper.get('[data-catalog-package="warm-stone"]').text()).toContain(
      "Warm Stone",
    );
    expect(wrapper.text()).toContain("2 packages");
    expect(wrapper.text()).toContain("3 options");
    expect(wrapper.text()).toContain("VIP");

    inertia.page.props.locale = "id";
    await wrapper.vm.$nextTick();
    expect(wrapper.get('[data-catalog-category="body-care"]').text()).toContain(
      "Perawatan Tubuh",
    );
    expect(wrapper.get('[data-catalog-package="warm-stone"]').text()).toContain(
      "Batu Hangat",
    );
    expect(wrapper.text()).toContain("2 paket");
    expect(wrapper.text()).toContain("3 opsi");
  });

  it("renders a single category and package without relying on a many-record layout", () => {
    const wrapper = mountIndex({
      categories: categories.slice(0, 1),
      packages: packages.slice(0, 1),
    });

    expect(wrapper.findAll("[data-catalog-category]")).toHaveLength(1);
    expect(wrapper.findAll("[data-catalog-package]")).toHaveLength(1);
  });

  it("shows permitted controls only and warns when a published package has a non-published parent", () => {
    const wrapper = mountIndex();
    expect(
      wrapper.get('a[href="/admin/catalog/packages/create"]'),
    ).toBeTruthy();
    expect(
      wrapper.get('a[href="/admin/catalog/packages/warm-stone/edit"]'),
    ).toBeTruthy();
    expect(
      wrapper
        .find('a[href="/admin/catalog/packages/calm-facial/edit"]')
        .exists(),
    ).toBe(false);
    expect(
      wrapper.get('[data-catalog-warning="calm-facial"]').text(),
    ).toContain("hidden");
    expect(
      wrapper.get('[data-catalog-action="body-care-archive"]'),
    ).toBeTruthy();
    expect(
      wrapper.find('[data-catalog-action="body-care-publish"]').exists(),
    ).toBe(false);

    const restricted = mountIndex({
      can: { createCategory: false, createPackage: false },
    });
    expect(restricted.find('[data-catalog-create="category"]').exists()).toBe(
      false,
    );
    expect(
      restricted.find('a[href="/admin/catalog/packages/create"]').exists(),
    ).toBe(false);
  });

  it("announces accessible empty sections and creation hooks for zero catalog records", () => {
    const wrapper = mountIndex({ categories: [], packages: [] });
    expect(
      wrapper.get("[data-catalog-empty=categories]").attributes("role"),
    ).toBe("status");
    expect(
      wrapper.get("[data-catalog-empty=packages]").attributes("role"),
    ).toBe("status");
    expect(wrapper.get('[data-catalog-create="category"]')).toBeTruthy();
    expect(
      wrapper.get('a[href="/admin/catalog/packages/create"]'),
    ).toBeTruthy();
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
