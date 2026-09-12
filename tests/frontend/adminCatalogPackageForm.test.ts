import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import PackageChoiceRows from "@/components/admin/catalog/PackageChoiceRows.vue";
import PackageGalleryInput from "@/components/admin/catalog/PackageGalleryInput.vue";
import PackageForm from "@/pages/admin/catalog/packages/Form.vue";

type Options = {
  onError: (errors: Record<string, string>) => void;
  onFinish: () => void;
};
const inertia = vi.hoisted(() => ({
  page: { props: { locale: "en" } },
  requests: [] as Array<{ url: string; data: FormData; options: Options }>,
}));
vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  inertia.page = reactive(inertia.page);
  return {
    Link: "a",
    usePage: () => inertia.page,
    router: {
      post: (url: string, data: FormData, options: Options) =>
        inertia.requests.push({ url, data, options }),
      delete: vi.fn(),
    },
  };
});

const categories = [
  {
    id: 5,
    slug: "body-care",
    nameId: "Tubuh",
    nameEn: "Body",
    status: "published",
  },
] as const;
const draftPackage = {
  version: null,
  categoryId: null,
  slug: "",
  nameId: "",
  nameEn: "",
  descId: null,
  descEn: null,
  longId: null,
  longEn: null,
  benefitId: null,
  benefitEn: null,
  isVip: false,
  displayOrder: 0,
  cover: null,
  gallery: [],
  variants: [],
  extensions: [],
  status: "draft",
  isPublished: false,
  can: {
    update: false,
    publish: false,
    archive: false,
    restore: false,
    delete: false,
  },
} as const;
let wrappers: VueWrapper[] = [];
function mountForm(packageItem = draftPackage) {
  const wrapper = mount(PackageForm, {
    attachTo: document.body,
    props: { categories, package: packageItem },
    global: {
      stubs: { AdminLayout: { template: "<section><slot /></section>" } },
    },
  });
  wrappers.push(wrapper);
  return wrapper;
}
beforeEach(() => {
  inertia.requests = [];
  let count = 0;
  vi.stubGlobal(
    "URL",
    Object.assign(URL, {
      createObjectURL: vi.fn(() => `blob:preview-${++count}`),
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

describe("admin package form", () => {
  it("submits only the approved create multipart contract and exact empty collection sentinels", async () => {
    const wrapper = mountForm();
    await wrapper.get('[name="categoryId"]').setValue("5");
    await wrapper.get('[name="slug"]').setValue(" warm-stone ");
    await wrapper.get('[name="nameId"]').setValue(" Batu Hangat ");
    await wrapper.get('[name="nameEn"]').setValue(" Warm Stone ");
    await wrapper.get('[name="isVip"]').setValue(true);
    await wrapper.get('[name="displayOrder"]').setValue("2");
    await wrapper.get("form").trigger("submit");

    const request = inertia.requests[0];
    expect(request.url).toBe("/admin/catalog/packages");
    expect(Object.fromEntries(request.data.entries())).toEqual({
      categoryId: "5",
      slug: "warm-stone",
      nameId: "Batu Hangat",
      nameEn: "Warm Stone",
      descId: "",
      descEn: "",
      longId: "",
      longEn: "",
      benefitId: "",
      benefitEn: "",
      isVip: "1",
      displayOrder: "2",
      removeCover: "0",
      variants: "[]",
      extensions: "[]",
    });
  });

  it("keeps published server identifiers immutable and posts the original update route and version", async () => {
    const wrapper = mountForm({
      ...draftPackage,
      version: 3,
      slug: "published-package",
      categoryId: 5,
      nameId: "Paket",
      nameEn: "Package",
      isPublished: true,
      variants: [
        {
          id: 7,
          code: "locked-code",
          mode: "regular",
          durationMinutes: 60,
          priceIdr: 120000,
          isActive: true,
          displayOrder: 0,
          isPublished: true,
        },
      ],
    });
    expect(
      wrapper.get<HTMLInputElement>('[name="slug"]').element.readOnly,
    ).toBe(true);
    expect(
      wrapper.get<HTMLInputElement>('[name="variants.0.code"]').element
        .readOnly,
    ).toBe(true);
    await wrapper.get("form").trigger("submit");
    const request = inertia.requests[0];
    expect(request.url).toBe("/admin/catalog/packages/published-package");
    expect(request.data.get("_method")).toBe("patch");
    expect(request.data.get("version")).toBe("3");
    expect(request.data.get("variants[0][id]")).toBe("7");
    expect(request.data.get("variants[0][code]")).toBe("locked-code");
  });

  it("uses package lifecycle endpoints with the current version", async () => {
    const wrapper = mountForm({
      ...draftPackage,
      version: 9,
      slug: "draft-package",
      nameId: "Paket",
      nameEn: "Package",
      can: { ...draftPackage.can, publish: true },
    });
    await wrapper
      .get('[data-catalog-action="draft-package-publish"]')
      .trigger("click");
    const confirm = document.querySelector<HTMLButtonElement>(
      "[data-lifecycle-confirm]",
    )!;
    confirm.click();
    expect(inertia.requests[0]).toMatchObject({
      url: "/admin/catalog/packages/draft-package/publish",
      data: { version: 9 },
    });
  });

  it("emits immutable choice arrays and maps exact nested errors to their field", async () => {
    const wrapper = mount(PackageChoiceRows, {
      props: {
        kind: "variants",
        rows: [],
        errors: { "variants.0.code": "Taken" },
      },
    });
    await wrapper.get("[data-choice-add]").trigger("click");
    const first = wrapper.emitted(
      "update:rows",
    )?.[0]?.[0] as readonly unknown[];
    await wrapper.setProps({ rows: first });
    await wrapper.get('[name="variants.0.code"]').setValue(" new-code ");
    const second = wrapper.emitted("update:rows")?.[1]?.[0] as readonly {
      code: string;
    }[];
    expect(second).not.toBe(first);
    expect(second[0]).not.toBe((first as readonly object[])[0]);
    expect(second[0].code).toBe(" new-code ");
    expect(
      wrapper.get('[name="variants.0.code"]').attributes("aria-invalid"),
    ).toBe("true");
  });

  it("caps gallery uploads, keeps opaque retained IDs separate, and revokes only local previews", async () => {
    const wrapper = mount(PackageGalleryInput, {
      attachTo: document.body,
      props: {
        retained: [{ retentionId: "a".repeat(64), url: "/server.jpg" }],
        uploads: [],
      },
    });
    wrappers.push(wrapper);
    const input = wrapper.get<HTMLInputElement>('input[type="file"]');
    Object.defineProperty(input.element, "files", {
      configurable: true,
      value: [new File(["a"], "a.jpg", { type: "image/jpeg" })],
    });
    await input.trigger("change");
    expect(wrapper.emitted("update:uploads")?.[0]?.[0]).toHaveLength(1);
    await wrapper.get("[data-gallery-remove-upload]").trigger("click");
    expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:preview-1");
    await wrapper.get("[data-gallery-remove-retained]").trigger("click");
    expect(wrapper.emitted("update:retained")?.[0]?.[0]).toEqual([]);
    expect(URL.revokeObjectURL).not.toHaveBeenCalledWith("/server.jpg");
  });

  it("keeps stale nested errors visible and locks repeat submission while processing", async () => {
    const wrapper = mountForm();
    await wrapper.get("form").trigger("submit");
    await wrapper.get("form").trigger("submit");
    expect(inertia.requests).toHaveLength(1);
    inertia.requests[0].options.onError({
      version: "Stale version",
      "variants.0.code": "Taken",
    });
    inertia.requests[0].options.onFinish();
    await nextTick();
    expect(wrapper.text()).toContain("Stale version");
  });
});
