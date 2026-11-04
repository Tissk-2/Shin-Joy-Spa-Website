import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import PackageChoiceRows from "@/components/admin/catalog/PackageChoiceRows.vue";
import CatalogImageInput from "@/components/admin/catalog/CatalogImageInput.vue";
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
  pricingBasis: null,
  minGuests: null,
  maxGuests: null,
  pricingVerified: false,
  pricingVerifiedBy: null,
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
    attestPricing: false,
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
  inertia.page.props.locale = "en";
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
  it("uses package-specific bilingual identity and slug labels", async () => {
    const wrapper = mountForm();
    expect(wrapper.get('label[for="catalog-package-nameId"]').text()).toBe(
      "Indonesian package name",
    );
    expect(wrapper.get('label[for="catalog-package-nameEn"]').text()).toBe(
      "English package name",
    );
    expect(wrapper.get('label[for="catalog-package-slug"]').text()).toBe(
      "Package slug",
    );

    inertia.page.props.locale = "id";
    await nextTick();
    expect(wrapper.get('label[for="catalog-package-nameId"]').text()).toBe(
      "Nama paket dalam Bahasa Indonesia",
    );
    expect(wrapper.get('label[for="catalog-package-nameEn"]').text()).toBe(
      "Nama paket dalam Bahasa Inggris",
    );
    expect(wrapper.get('label[for="catalog-package-slug"]').text()).toBe(
      "Slug paket",
    );
  });

  it("submits only the approved update multipart contract and exact empty collection sentinels", async () => {
    const wrapper = mountForm({
      ...draftPackage,
      version: 0,
      categoryId: 5,
      slug: "warm-stone",
      nameId: "Batu Hangat",
      nameEn: "Warm Stone",
      isPublished: false,
    });
    await wrapper.get('[name="categoryId"]').setValue("5");
    await wrapper.get('[name="slug"]').setValue(" warm-stone ");
    await wrapper.get('[name="nameId"]').setValue(" Batu Hangat ");
    await wrapper.get('[name="nameEn"]').setValue(" Warm Stone ");
    await wrapper.get('[name="isVip"]').setValue(true);
    await wrapper.get('[name="displayOrder"]').setValue("2");
    await wrapper.get("form").trigger("submit");

    const request = inertia.requests[0];
    expect(request.url).toBe("/admin/catalog/packages/warm-stone");
    expect(Object.fromEntries(request.data.entries())).toEqual({
      _method: "patch",
      version: "0",
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
      pricingBasis: "",
      minGuests: "",
      maxGuests: "",
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

  it("posts owner attestation with the current version and pricing coverage", async () => {
    const wrapper = mountForm({
      ...draftPackage,
      version: 4,
      slug: "priced-package",
      nameId: "Paket",
      nameEn: "Package",
      can: { ...draftPackage.can, attestPricing: true },
    });

    await wrapper.get('[name="pricingBasis"]').setValue("per_person");
    await wrapper.get('[name="minGuests"]').setValue("1");
    await wrapper.get('[name="maxGuests"]').setValue("4");
    await wrapper.get("[data-pricing-attest]").trigger("click");

    const request = inertia.requests[0];
    expect(request.url).toBe("/admin/catalog/packages/priced-package/pricing");
    expect(request.data).toEqual({
      version: 4,
      pricingBasis: "per_person",
      minGuests: 1,
      maxGuests: 4,
    });
  });

  it("shows attestation only to authorized owners and exposes the verifier", () => {
    const verifiedPackage = {
      ...draftPackage,
      version: 2,
      slug: "priced-package",
      nameId: "Paket",
      nameEn: "Package",
      pricingBasis: "per_booking" as const,
      minGuests: 2,
      maxGuests: 4,
      pricingVerified: true,
      pricingVerifiedBy: {
        id: 3,
        name: "Owner",
        email: "owner@example.test",
      },
    };

    const admin = mountForm(verifiedPackage);
    expect(admin.find("[data-pricing-attest]").exists()).toBe(false);
    expect(admin.text()).toContain("Verified");
    expect(admin.text()).toContain("owner@example.test");

    const owner = mountForm({
      ...verifiedPackage,
      can: { ...draftPackage.can, attestPricing: true },
    });
    expect(owner.find("[data-pricing-attest]").exists()).toBe(true);
  });

  it.each(["variant", "extension", "metadata", "cover", "gallery"])(
    "requires saving unsaved %s edits before attesting stored prices",
    async (edit) => {
      const row = {
        id: 7,
        code: "base",
        mode: "regular",
        durationMinutes: 60,
        priceIdr: 100000,
        isActive: true,
        displayOrder: 0,
        isPublished: true,
      };
      const wrapper = mountForm({
        ...draftPackage,
        version: 1,
        slug: "priced-package",
        nameId: "Paket",
        nameEn: "Package",
        variants: [row],
        extensions: [{ ...row, id: 8, code: "extra", durationMinutes: 30 }],
        can: { ...draftPackage.can, attestPricing: true },
      });
      if (edit === "variant" || edit === "extension") {
        await wrapper.get(`[name="${edit}s.0.priceIdr"]`).setValue("200000");
      } else if (edit === "metadata") {
        await wrapper.get('[name="nameEn"]').setValue("Changed package");
      } else if (edit === "cover") {
        wrapper
          .getComponent(CatalogImageInput)
          .vm.$emit("change", new File(["x"], "cover.png"));
      } else {
        wrapper
          .getComponent(PackageGalleryInput)
          .vm.$emit("update:uploads", [new File(["x"], "gallery.png")]);
      }
      await nextTick();
      const button = wrapper.get<HTMLButtonElement>("[data-pricing-attest]");
      expect(button.element.disabled).toBe(true);
      expect(wrapper.text()).toContain(
        "Save your other changes, then reopen this package before verifying pricing.",
      );
      inertia.page.props.locale = "id";
      await nextTick();
      expect(wrapper.text()).toContain(
        "Simpan perubahan lainnya, lalu buka kembali paket ini sebelum memverifikasi harga.",
      );
      button.element.removeAttribute("disabled");
      await button.trigger("click");
      expect(inertia.requests).toHaveLength(0);
    },
  );

  it("maps attestation validation errors to the pricing section", async () => {
    const wrapper = mountForm({
      ...draftPackage,
      version: 1,
      slug: "priced-package",
      nameId: "Paket",
      nameEn: "Package",
      can: { ...draftPackage.can, attestPricing: true },
    });

    await wrapper.get("[data-pricing-attest]").trigger("click");
    inertia.requests[0].options.onError({
      maxGuests: "Guests must be between 1 and 4.",
    });
    inertia.requests[0].options.onFinish();
    await nextTick();

    expect(wrapper.text()).toContain("Guests must be between 1 and 4.");
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

  it("keeps nested identifier and active-state errors visible and associated", () => {
    const wrapper = mount(PackageChoiceRows, {
      props: {
        kind: "extensions",
        rows: [
          {
            clientKey: "existing-extension",
            id: 8,
            code: "extra",
            mode: "regular",
            durationMinutes: "30",
            priceIdr: "50000",
            isActive: true,
            displayOrder: "0",
            isPublished: false,
          },
        ],
        errors: {
          "extensions.0.id": "This option does not belong to this package.",
          "extensions.0.isActive": "This option cannot be active.",
        },
      },
    });
    expect(wrapper.text()).toContain("This option does not belong");
    const row = wrapper.get('[data-choice-row="extensions.0"]');
    expect(row.attributes("aria-invalid")).toBe("true");
    expect(row.attributes("aria-describedby")).toContain(
      "extensions.0.id-error",
    );
    const active = wrapper.get<HTMLInputElement>(
      '[name="extensions.0.isActive"]',
    );
    expect(active.attributes("aria-invalid")).toBe("true");
    expect(active.attributes("aria-describedby")).toContain(
      "extensions.0.isActive-error",
    );
    expect(wrapper.get("#extensions\\.0\\.isActive-error").text()).toContain(
      "This option cannot be active",
    );
  });

  it("keeps variant identifier and active-state errors visible and associated", () => {
    const wrapper = mount(PackageChoiceRows, {
      props: {
        kind: "variants",
        rows: [
          {
            clientKey: "existing-variant",
            id: 6,
            code: "base",
            mode: "regular",
            durationMinutes: "60",
            priceIdr: "120000",
            isActive: false,
            displayOrder: "0",
            isPublished: false,
          },
        ],
        errors: {
          "variants.0.id": "This option does not belong to this package.",
          "variants.0.isActive": "This option cannot be active.",
        },
      },
    });
    expect(wrapper.get("#variants\\.0\\.id-error").text()).toContain(
      "This option does not belong",
    );
    expect(
      wrapper.get('[name="variants.0.isActive"]').attributes("aria-invalid"),
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
    expect(wrapper.text()).toContain("Taken");
  });
});
