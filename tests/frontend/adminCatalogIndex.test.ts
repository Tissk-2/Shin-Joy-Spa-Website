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
    isVip: true,
    status: "published" as const,
    variantCount: 2,
    extensionCount: 1,
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
    isVip: false,
    status: "published" as const,
    variantCount: 1,
    extensionCount: 0,
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
    // Staff review 2026-11-25: the total repeated what the two counts already say.
    const count = wrapper.get(
      '[data-catalog-package="warm-stone"] .catalog-count',
    );
    expect(count.text()).toBe("2 base options · 1 extension");
    expect(count.text()).not.toContain("3");
    expect(
      wrapper.get('[data-catalog-package="calm-facial"] .catalog-count').text(),
    ).toBe("1 base option · 0 extensions");
    expect(wrapper.text()).toContain("VIP");

    inertia.page.props.locale = "id";
    await wrapper.vm.$nextTick();
    expect(wrapper.get('[data-catalog-package="warm-stone"]').text()).toContain(
      "Batu Hangat",
    );
    expect(
      wrapper.get('[data-catalog-package="warm-stone"] .catalog-count').text(),
    ).toBe("2 pilihan utama · 1 tambahan");
  });

  // Staff review 2026-11-25: Katalog showed "Nezuko / Nezuko".
  it("shows the second name only when it differs", () => {
    const wrapper = mountIndex({
      packages: [
        packages[0],
        { ...packages[1], nameId: "Calm Facial", nameEn: "Calm Facial" },
      ],
    });
    expect(
      wrapper
        .find('[data-catalog-package="warm-stone"] .catalog-secondary')
        .text(),
    ).toBe("Batu Hangat");
    expect(
      wrapper
        .find('[data-catalog-package="calm-facial"] .catalog-secondary')
        .exists(),
    ).toBe(false);
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

  // Catches the duplicate section label returning and deletion looking like a safe step.
  it("offers a draft its publish and red delete actions under one heading", () => {
    const wrapper = mountIndex({
      packages: [
        {
          ...packages[0],
          slug: "new-draft",
          status: "draft" as const,
          can: {
            update: true,
            publish: true,
            archive: false,
            restore: false,
            delete: true,
          },
        },
      ],
    });
    expect(wrapper.findAll("h2").map((node) => node.text())).toEqual([
      "Packages",
    ]);
    const publish = wrapper.get('[data-catalog-action="new-draft-publish"]');
    const remove = wrapper.get('[data-catalog-action="new-draft-delete"]');
    expect(publish.classes()).not.toContain("catalog-control--danger");
    expect(remove.classes()).toContain("catalog-control--danger");
  });

  // Staff review 2026-11-25: Arsipkan looked like Ubah paket although it takes the package off the site.
  it("marks archive apart from edit", () => {
    const wrapper = mountIndex();
    expect(
      wrapper.get('[data-catalog-action="warm-stone-archive"]').classes(),
    ).toContain("catalog-control--danger");
    expect(
      wrapper
        .get('[data-catalog-package="warm-stone"] a.catalog-control')
        .classes(),
    ).not.toContain("catalog-control--danger");
  });
});
