import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import CatalogIndex from "@/pages/admin/catalog/Index.vue";
import type { AdminCatalogCategoryData, Locale } from "@/types";

type Options = {
  onSuccess: () => void;
  onError: (errors: Record<string, string>) => void;
  onFinish: () => void;
  data?: Record<string, unknown>;
};
const inertia = vi.hoisted(() => ({
  page: { props: { locale: "en" as Locale } },
  requests: [] as Array<{
    method: string;
    url: string;
    data: FormData | Record<string, unknown>;
    options: Options;
  }>,
}));
vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  inertia.page = reactive(inertia.page);
  return {
    Link: "a",
    usePage: () => inertia.page,
    router: {
      post: (
        url: string,
        data: FormData | Record<string, unknown>,
        options: Options,
      ) => inertia.requests.push({ method: "post", url, data, options }),
      delete: (url: string, options: Options) =>
        inertia.requests.push({
          method: "delete",
          url,
          data: options.data!,
          options,
        }),
    },
  };
});
const category: AdminCatalogCategoryData = {
  slug: "body-care",
  version: 7,
  nameId: "Perawatan Tubuh",
  nameEn: "Body Care",
  image: "/storage/catalog/categories/current.jpg",
  status: "draft",
  displayOrder: 2,
  packageCount: 0,
  can: {
    update: true,
    publish: true,
    archive: true,
    restore: true,
    delete: true,
  },
};
let wrappers: VueWrapper[] = [];
function mountIndex(record = category) {
  const wrapper = mount(CatalogIndex, {
    attachTo: document.body,
    props: {
      categories: [record],
      packages: [],
      can: { createCategory: true, createPackage: true },
    },
    global: {
      stubs: { AdminLayout: { template: "<section><slot /></section>" } },
    },
  });
  wrappers.push(wrapper);
  return wrapper;
}
async function editor(edit = false, record = category) {
  const wrapper = mountIndex(record);
  await wrapper
    .get(
      edit
        ? `[data-catalog-action="${record.slug}-edit"]`
        : '[data-catalog-create="category"]',
    )
    .trigger("click");
  return wrapper;
}
async function selectFile(wrapper: VueWrapper, file: File) {
  const input = wrapper.get<HTMLInputElement>('input[type="file"]');
  Object.defineProperty(input.element, "files", {
    configurable: true,
    value: [file],
  });
  await input.trigger("change");
}
function dialog() {
  return document.querySelector<HTMLElement>('[role="alertdialog"]')!;
}
async function clickDialog(selector: string) {
  dialog().querySelector<HTMLElement>(selector)!.click();
  await nextTick();
}
beforeEach(() => {
  inertia.requests = [];
  inertia.page.props.locale = "en";
  let sequence = 0;
  vi.stubGlobal(
    "URL",
    Object.assign(URL, {
      createObjectURL: vi.fn(() => `blob:preview-${++sequence}`),
      revokeObjectURL: vi.fn(),
    }),
  );
});
afterEach(() => {
  wrappers.forEach((wrapper) => wrapper.unmount());
  wrappers = [];
  document.body.innerHTML = "";
  vi.unstubAllGlobals();
});

describe("category editor request and media boundary", () => {
  it("posts only trimmed create fields and an optional file, then closes and resets on success", async () => {
    const wrapper = await editor();
    await wrapper.get('[name="slug"]').setValue(" new-care ");
    await wrapper.get('[name="nameId"]').setValue(" Perawatan Baru ");
    await wrapper.get('[name="nameEn"]').setValue(" New Care ");
    await wrapper.get('[name="displayOrder"]').setValue("0");
    const file = new File(["image"], "cover.webp", { type: "image/webp" });
    await selectFile(wrapper, file);
    await wrapper.get("[data-category-editor] form").trigger("submit");
    const request = inertia.requests[0];
    expect(request.url).toBe("/admin/catalog/categories");
    expect(request.method).toBe("post");
    expect(request.data).toBeInstanceOf(FormData);
    expect(Object.fromEntries((request.data as FormData).entries())).toEqual({
      slug: "new-care",
      nameId: "Perawatan Baru",
      nameEn: "New Care",
      displayOrder: "0",
      removeImage: "0",
      image: file,
    });
    request.options.onSuccess();
    request.options.onFinish();
    await nextTick();
    expect(wrapper.find("[data-category-editor]").exists()).toBe(false);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:preview-1");
    await wrapper.get('[data-catalog-create="category"]').trigger("click");
    expect(wrapper.get<HTMLInputElement>('[name="nameEn"]').element.value).toBe(
      "",
    );
  });
  it("omits image on create without a selection", async () => {
    const wrapper = await editor();
    await wrapper.get("[data-category-editor] form").trigger("submit");
    expect([...(inertia.requests[0].data as FormData).keys()].sort()).toEqual([
      "displayOrder",
      "nameEn",
      "nameId",
      "removeImage",
      "slug",
    ]);
  });
  it("does not mark a create-mode local image clear as removal of a server image", async () => {
    const wrapper = await editor();
    await selectFile(
      wrapper,
      new File(["image"], "draft.jpg", { type: "image/jpeg" }),
    );
    await wrapper.get("[data-image-remove]").trigger("click");
    expect(wrapper.text()).not.toContain("Image removal is pending");
    await wrapper.get("[data-category-editor] form").trigger("submit");
    expect((inertia.requests[0].data as FormData).get("removeImage")).toBe("0");
  });
  it.each(["draft", "published", "archived"] as const)(
    "uses exact update multipart contract and the %s slug rule",
    async (status) => {
      const wrapper = await editor(true, { ...category, status });
      const slug = wrapper.get<HTMLInputElement>('[name="slug"]');
      expect(slug.element.readOnly).toBe(status !== "draft");
      expect(
        wrapper
          .get<HTMLInputElement>('[name="displayOrder"]')
          .attributes("min"),
      ).toBe("0");
      await wrapper.get("[data-image-remove]").trigger("click");
      await wrapper.get("[data-category-editor] form").trigger("submit");
      expect(inertia.requests[0].url).toBe(
        "/admin/catalog/categories/body-care",
      );
      expect(
        Object.fromEntries((inertia.requests[0].data as FormData).entries()),
      ).toEqual({
        _method: "patch",
        version: "7",
        slug: "body-care",
        nameId: "Perawatan Tubuh",
        nameEn: "Body Care",
        displayOrder: "2",
        removeImage: "1",
      });
    },
  );
  it("updates a draft using its original encoded URL when the slug changes", async () => {
    const wrapper = await editor(true, { ...category, slug: "body/care" });
    await wrapper.get('[name="slug"]').setValue(" renamed-care ");
    await wrapper.get("[data-category-editor] form").trigger("submit");
    expect(inertia.requests[0].url).toBe(
      "/admin/catalog/categories/body%2Fcare",
    );
    expect((inertia.requests[0].data as FormData).get("slug")).toBe(
      "renamed-care",
    );
  });
  it("revokes local previews on replacement, removal and unmount but never the server URL", async () => {
    const wrapper = await editor(true);
    expect(wrapper.get("[data-image-preview]").attributes("src")).toBe(
      category.image,
    );
    expect(wrapper.get('input[type="file"]').attributes("accept")).toBe(
      "image/jpeg,image/png,image/webp",
    );
    await selectFile(wrapper, new File(["a"], "a.jpg", { type: "image/jpeg" }));
    expect(wrapper.get("[data-image-preview]").attributes("alt")).toContain(
      "Selected",
    );
    await selectFile(wrapper, new File(["b"], "b.png", { type: "image/png" }));
    expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:preview-1");
    await wrapper.get("[data-image-remove]").trigger("click");
    expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:preview-2");
    expect(wrapper.find("[data-image-preview]").exists()).toBe(false);
    const replacement = new File(["c"], "c.webp", { type: "image/webp" });
    await selectFile(wrapper, replacement);
    await wrapper.get("[data-category-editor] form").trigger("submit");
    expect((inertia.requests[0].data as FormData).get("removeImage")).toBe("0");
    expect((inertia.requests[0].data as FormData).get("image")).toBe(
      replacement,
    );
    wrapper.unmount();
    wrappers = [];
    expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:preview-3");
    expect(URL.revokeObjectURL).not.toHaveBeenCalledWith(category.image);
  });
  it("keeps server field/conflict errors associated and persistent until retry and blocks all processing controls", async () => {
    const wrapper = await editor(true);
    await wrapper.get("[data-category-editor] form").trigger("submit");
    await wrapper.get("[data-category-editor] form").trigger("submit");
    expect(inertia.requests).toHaveLength(1);
    for (const input of wrapper.findAll<HTMLInputElement>(
      "[data-category-editor] input, [data-category-editor] button",
    ))
      expect(input.element.disabled).toBe(true);
    inertia.requests[0].options.onError({
      nameEn: "Server name error",
      slug: "Server slug error",
      image: "Server image error",
      version: "Server version conflict",
      displayOrder: "Server order error",
      removeImage: "Server removal error",
    });
    inertia.requests[0].options.onFinish();
    await nextTick();
    for (const name of ["nameEn", "slug", "image", "displayOrder"]) {
      const input = wrapper.get(`[name="${name}"]`);
      expect(input.attributes("aria-invalid")).toBe("true");
      const ids = input.attributes("aria-describedby").split(" ");
      expect(
        ids.some((id) =>
          document.getElementById(id)?.textContent?.includes("Server"),
        ),
      ).toBe(true);
    }
    expect(wrapper.get("#catalog-category-version-error").text()).toBe(
      "Server version conflict",
    );
    expect(wrapper.text()).toContain("Server removal error");
    await wrapper.get('[name="nameEn"]').setValue("Changed");
    expect(wrapper.text()).toContain("Server name error");
    await wrapper.get("[data-category-editor] form").trigger("submit");
    expect(wrapper.text()).not.toContain("Server name error");
    expect((inertia.requests[1].data as FormData).has("errors")).toBe(false);
  });
});

describe("category lifecycle confirmations", () => {
  it.each([
    ["publish", "post", "/admin/catalog/categories/body%2Fcare/publish"],
    ["archive", "post", "/admin/catalog/categories/body%2Fcare/archive"],
    ["restore", "post", "/admin/catalog/categories/body%2Fcare/restore"],
    ["delete", "delete", "/admin/catalog/categories/body%2Fcare"],
  ])(
    "confirms %s with only version at the correct endpoint",
    async (action, method, url) => {
      const wrapper = mountIndex({ ...category, slug: "body/care" });
      await wrapper
        .get(`[data-catalog-action="body/care-${action}"]`)
        .trigger("click");
      expect(inertia.requests).toHaveLength(0);
      expect(dialog()).not.toBeNull();
      expect(document.activeElement?.tagName).toBe("H2");
      expect(dialog().textContent).toContain("Body Care");
      expect(
        document.getElementById(dialog().getAttribute("aria-labelledby")!)
          ?.textContent,
      ).toBeTruthy();
      expect(
        document.getElementById(dialog().getAttribute("aria-describedby")!)
          ?.textContent,
      ).toBeTruthy();
      if (action === "archive")
        expect(dialog().textContent).toMatch(/temporarily.*public/i);
      if (action === "delete")
        expect(dialog().textContent).toMatch(/permanently.*draft/i);
      await clickDialog("[data-lifecycle-confirm]");
      expect(inertia.requests[0]).toMatchObject({
        method,
        url,
        data: { version: 7 },
      });
      expect(Object.keys(inertia.requests[0].data)).toEqual(["version"]);
      inertia.requests[0].options.onSuccess();
      inertia.requests[0].options.onFinish();
      await flushPromises();
      expect(dialog()).toBeNull();
    },
  );
  it("traps Tab in both directions, closes on Escape and restores the exact trigger", async () => {
    const wrapper = mountIndex();
    const trigger = wrapper.get<HTMLElement>(
      '[data-catalog-action="body-care-archive"]',
    );
    trigger.element.focus();
    await trigger.trigger("click");
    const buttons = [...dialog().querySelectorAll<HTMLButtonElement>("button")];
    buttons.at(-1)!.focus();
    buttons.at(-1)!.dispatchEvent(
      new KeyboardEvent("keydown", {
        key: "Tab",
        bubbles: true,
        cancelable: true,
      }),
    );
    await nextTick();
    expect(document.activeElement).toBe(buttons[0]);
    buttons[0].dispatchEvent(
      new KeyboardEvent("keydown", {
        key: "Tab",
        shiftKey: true,
        bubbles: true,
        cancelable: true,
      }),
    );
    await nextTick();
    expect(document.activeElement).toBe(buttons.at(-1));
    dialog().dispatchEvent(
      new KeyboardEvent("keydown", { key: "Escape", bubbles: true }),
    );
    await flushPromises();
    expect(dialog()).toBeNull();
    expect(document.activeElement).toBe(trigger.element);
  });
  it("prevents repeat or dismissal while processing and keeps server errors until retry", async () => {
    const wrapper = mountIndex();
    await wrapper
      .get('[data-catalog-action="body-care-publish"]')
      .trigger("click");
    await clickDialog("[data-lifecycle-confirm]");
    await clickDialog("[data-lifecycle-confirm]");
    await clickDialog("[data-lifecycle-cancel]");
    dialog().dispatchEvent(
      new KeyboardEvent("keydown", { key: "Escape", bubbles: true }),
    );
    await nextTick();
    expect(dialog()).not.toBeNull();
    expect(inertia.requests).toHaveLength(1);
    expect(
      [...dialog().querySelectorAll("button")].every(
        (button) => button.disabled,
      ),
    ).toBe(true);
    inertia.requests[0].options.onError({
      version: "Stale version",
      status: "Cannot publish",
    });
    inertia.requests[0].options.onFinish();
    await nextTick();
    expect(dialog().textContent).toContain("Stale version");
    expect(dialog().textContent).toContain("Cannot publish");
    expect(wrapper.get('[data-catalog-status="draft"]').text()).toBe("Draft");
    await clickDialog("[data-lifecycle-confirm]");
    expect(dialog().textContent).not.toContain("Stale version");
  });
  it("switches category form and confirmation copy with the active locale", async () => {
    const wrapper = await editor();
    expect(wrapper.get("[data-category-editor]").text()).toContain(
      "English category name",
    );
    inertia.page.props.locale = "id";
    await nextTick();
    expect(wrapper.get("[data-category-editor]").text()).toContain(
      "Nama kategori dalam Bahasa Inggris",
    );
    await wrapper.get("[data-category-cancel]").trigger("click");
    await wrapper
      .get('[data-catalog-action="body-care-delete"]')
      .trigger("click");
    expect(dialog().textContent).toContain("Perawatan Tubuh");
    expect(dialog().textContent).toMatch(/permanen.*draf/i);
  });
  it("keeps disallowed storage, HTML and routing dependencies outside the components", () => {
    for (const name of [
      "CatalogImageInput",
      "CatalogLifecycleActions",
      "CategoryEditor",
    ]) {
      const source = readFileSync(
        resolve(
          import.meta.dirname,
          `../../resources/js/components/admin/catalog/${name}.vue`,
        ),
        "utf8",
      );
      expect(source).not.toMatch(
        /localStorage|sessionStorage|vue-router|v-html|payment|image_path/,
      );
    }
  });
});
