<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import CatalogImageInput from "@/components/admin/catalog/CatalogImageInput.vue";
import CatalogLifecycleActions from "@/components/admin/catalog/CatalogLifecycleActions.vue";
import PackageChoiceRows from "@/components/admin/catalog/PackageChoiceRows.vue";
import PackageGalleryInput from "@/components/admin/catalog/PackageGalleryInput.vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type {
  AdminCatalogChoiceData,
  AdminCatalogPackageFormProps,
  CatalogChoiceInput,
} from "@/types";

const props = defineProps<AdminCatalogPackageFormProps>();
const { tr } = useLanguage();
const packageItem = props.package;
let choiceSequence = 0;
function choice(row: AdminCatalogChoiceData): CatalogChoiceInput {
  return {
    clientKey: `persisted-${row.id}-${++choiceSequence}`,
    id: row.id,
    code: row.code,
    mode: row.mode,
    durationMinutes: String(row.durationMinutes),
    priceIdr: String(row.priceIdr),
    isActive: row.isActive,
    displayOrder: String(row.displayOrder),
    isPublished: row.isPublished,
  };
}
const values = ref({
  categoryId:
    packageItem.categoryId === null ? "" : String(packageItem.categoryId),
  slug: packageItem.slug,
  nameId: packageItem.nameId,
  nameEn: packageItem.nameEn,
  descId: packageItem.descId ?? "",
  descEn: packageItem.descEn ?? "",
  longId: packageItem.longId ?? "",
  longEn: packageItem.longEn ?? "",
  benefitId: packageItem.benefitId ?? "",
  benefitEn: packageItem.benefitEn ?? "",
  isVip: packageItem.isVip,
  displayOrder: String(packageItem.displayOrder),
});
const variants = ref<readonly CatalogChoiceInput[]>(
  packageItem.variants.map(choice),
);
const extensions = ref<readonly CatalogChoiceInput[]>(
  packageItem.extensions.map(choice),
);
const cover = ref<File | null>(null);
const removeCover = ref(false);
const retained = ref([...packageItem.gallery]);
const uploads = ref<readonly File[]>([]);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const lockedSlug = packageItem.isPublished;
const isEdit = packageItem.version !== null;
const labelKeys: Readonly<Record<string, string>> = {
  categoryId: "categoryId",
  slug: "packageSlug",
  nameId: "packageNameId",
  nameEn: "packageNameEn",
};
function labelKey(field: string): string {
  return labelKeys[field] ?? field;
}
function renderedChoiceError(field: string): boolean {
  const match =
    /^(variants|extensions)\.(\d+)\.(id|code|mode|durationMinutes|priceIdr|isActive|displayOrder)$/.exec(
      field,
    );
  if (!match) return false;
  const rows = match[1] === "variants" ? variants.value : extensions.value;
  return Number(match[2]) < rows.length;
}
const extraErrors = computed(() =>
  Object.entries(errors.value).filter(
    ([field]) =>
      ![
        "version",
        "categoryId",
        "slug",
        "nameId",
        "nameEn",
        "descId",
        "descEn",
        "longId",
        "longEn",
        "benefitId",
        "benefitEn",
        "isVip",
        "displayOrder",
        "cover",
        "galleryUploads",
        "retainedGalleryIds",
        "removeCover",
      ].includes(field) && !renderedChoiceError(field),
  ),
);
function fieldError(field: string): string | undefined {
  return errors.value[field];
}
function appendChoiceRows(
  data: FormData,
  name: "variants" | "extensions",
  rows: readonly CatalogChoiceInput[],
): void {
  if (!rows.length) {
    data.append(name, "[]");
    return;
  }
  rows.forEach((row, index) => {
    const prefix = `${name}[${index}]`;
    if (isEdit && row.id !== undefined)
      data.append(`${prefix}[id]`, String(row.id));
    data.append(`${prefix}[code]`, row.code.trim());
    data.append(`${prefix}[mode]`, row.mode);
    data.append(`${prefix}[durationMinutes]`, row.durationMinutes);
    data.append(`${prefix}[priceIdr]`, row.priceIdr);
    data.append(`${prefix}[isActive]`, row.isActive ? "1" : "0");
    data.append(`${prefix}[displayOrder]`, row.displayOrder);
  });
}
function submit(): void {
  if (processing.value) return;
  const data = new FormData();
  if (isEdit) {
    data.append("_method", "patch");
    data.append("version", String(packageItem.version));
  }
  for (const field of [
    "categoryId",
    "slug",
    "nameId",
    "nameEn",
    "descId",
    "descEn",
    "longId",
    "longEn",
    "benefitId",
    "benefitEn",
    "displayOrder",
  ] as const)
    data.append(field, String(values.value[field]).trim());
  data.append("isVip", values.value.isVip ? "1" : "0");
  data.append("removeCover", isEdit && removeCover.value ? "1" : "0");
  if (cover.value) data.append("cover", cover.value);
  retained.value.forEach((item) =>
    data.append("retainedGalleryIds[]", item.retentionId),
  );
  uploads.value.forEach((file) => data.append("galleryUploads[]", file));
  appendChoiceRows(data, "variants", variants.value);
  appendChoiceRows(data, "extensions", extensions.value);
  errors.value = {};
  processing.value = true;
  router.post(
    isEdit
      ? `/admin/catalog/packages/${encodeURIComponent(packageItem.slug)}`
      : "/admin/catalog/packages",
    data,
    {
      preserveScroll: true,
      onError: (responseErrors) => {
        errors.value = { ...responseErrors };
      },
      onFinish: () => {
        processing.value = false;
      },
    },
  );
}
function retainedIds(ids: readonly string[]): void {
  retained.value = retained.value.filter((item) =>
    ids.includes(item.retentionId),
  );
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.catalog.title')"
      :subtitle="tr('ad.catalog.subtitle')"
    >
      <section class="catalog-editor catalog-package-form" data-package-form>
        <h1>
          {{
            tr(
              isEdit
                ? "ad.catalog.form.editPackageTitle"
                : "ad.catalog.form.createPackageTitle",
            )
          }}
        </h1>
        <form novalidate :aria-busy="processing" @submit.prevent="submit">
          <p v-if="errors.version" class="catalog-error" role="alert">
            {{ errors.version }}
          </p>
          <p
            v-for="[field, message] in extraErrors"
            :key="field"
            class="catalog-error"
            role="alert"
          >
            {{ message }}
          </p>
          <div class="catalog-form-grid">
            <div
              v-for="field in [
                'categoryId',
                'slug',
                'nameId',
                'nameEn',
                'descId',
                'descEn',
                'longId',
                'longEn',
                'benefitId',
                'benefitEn',
                'displayOrder',
              ]"
              :key="field"
              class="catalog-field"
            >
              <label :for="`catalog-package-${field}`">{{
                tr(`ad.catalog.form.${labelKey(field)}`)
              }}</label>
              <select
                v-if="field === 'categoryId'"
                :id="`catalog-package-${field}`"
                v-model="values.categoryId"
                :name="field"
                :disabled="processing"
                :aria-invalid="!!fieldError(field)"
              >
                <option value="">
                  {{ tr("ad.catalog.form.categoryPlaceholder") }}
                </option>
                <option
                  v-for="category in categories"
                  :key="category.id"
                  :value="String(category.id)"
                >
                  {{ category.nameId }} / {{ category.nameEn }}
                </option>
              </select>
              <textarea
                v-else-if="
                  ['descId', 'descEn', 'longId', 'longEn'].includes(field)
                "
                :id="`catalog-package-${field}`"
                v-model="
                  values[field as 'descId' | 'descEn' | 'longId' | 'longEn']
                "
                :name="field"
                :disabled="processing"
                :aria-invalid="!!fieldError(field)"
              />
              <input
                v-else
                :id="`catalog-package-${field}`"
                v-model="
                  values[
                    field as
                      | 'slug'
                      | 'nameId'
                      | 'nameEn'
                      | 'benefitId'
                      | 'benefitEn'
                      | 'displayOrder'
                  ]
                "
                :name="field"
                :type="field === 'displayOrder' ? 'number' : 'text'"
                :min="field === 'displayOrder' ? 0 : undefined"
                :readonly="field === 'slug' && lockedSlug"
                :disabled="processing"
                :aria-invalid="!!fieldError(field)"
              />
              <p v-if="fieldError(field)" class="catalog-error">
                {{ fieldError(field) }}
              </p>
            </div>
            <label class="catalog-choice-active"
              ><input
                v-model="values.isVip"
                name="isVip"
                type="checkbox"
                :disabled="processing"
              />
              {{ tr("ad.catalog.form.isVip") }}</label
            >
          </div>
          <CatalogImageInput
            id="catalog-package-cover"
            v-model:removed="removeCover"
            :current-image="packageItem.cover"
            :disabled="processing"
            :label="tr('ad.catalog.form.cover')"
            help-id="catalog-package-cover-help"
            error-id="catalog-package-cover-error"
            :error="errors.cover"
            @change="cover = $event"
          />
          <PackageGalleryInput
            :retained="retained"
            :uploads="uploads"
            :disabled="processing"
            :error="errors.galleryUploads ?? errors.retainedGalleryIds"
            @update:retained="retainedIds"
            @update:uploads="uploads = $event"
          />
          <PackageChoiceRows
            kind="variants"
            :rows="variants"
            :errors="errors"
            :disabled="processing"
            @update:rows="variants = $event"
          />
          <PackageChoiceRows
            kind="extensions"
            :rows="extensions"
            :errors="errors"
            :disabled="processing"
            @update:rows="extensions = $event"
          />
          <p v-if="processing" role="status">
            {{ tr("ad.catalog.processing") }}
          </p>
          <div class="catalog-form-actions">
            <button
              type="submit"
              class="catalog-control catalog-control--primary"
              :disabled="processing"
            >
              {{
                tr(
                  isEdit
                    ? "ad.catalog.form.saveChanges"
                    : "ad.catalog.form.saveDraft",
                )
              }}
            </button>
          </div>
        </form>
        <div v-if="isEdit" class="catalog-actions">
          <CatalogLifecycleActions
            :package="packageItem"
            :disabled="processing"
          />
        </div>
      </section>
    </AdminLayout>
  </div>
</template>
