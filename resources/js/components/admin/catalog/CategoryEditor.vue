<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, onMounted, ref } from "vue";
import CatalogImageInput from "@/components/admin/catalog/CatalogImageInput.vue";
import { useLanguage } from "@/composables/useLanguage";
import type { AdminCatalogCategoryData } from "@/types";

const props = defineProps<{ category: AdminCatalogCategoryData | null }>();
const emit = defineEmits<{
  saved: [];
  cancel: [];
  processing: [value: boolean];
}>();
const { tr } = useLanguage();
// Keep the opened record's version and route together until the editor is remounted.
const category = props.category;
const values = ref({
  slug: category?.slug ?? "",
  nameId: category?.nameId ?? "",
  nameEn: category?.nameEn ?? "",
  displayOrder: String(category?.displayOrder ?? 0),
});
const image = ref<File | null>(null);
const removeImage = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const heading = ref<HTMLHeadingElement | null>(null);
const lockedSlug = category !== null && category.status !== "draft";
const fields = ["slug", "nameId", "nameEn", "displayOrder"] as const;
const fieldLabels = {
  slug: "form.slug",
  nameId: "form.categoryNameId",
  nameEn: "form.categoryNameEn",
  displayOrder: "form.displayOrder",
};
const extraErrors = computed(() =>
  Object.entries(errors.value).filter(
    ([key]) => ![...fields, "image", "version"].includes(key),
  ),
);

function helpKey(field: (typeof fields)[number]): string {
  if (field === "slug") return lockedSlug ? "form.slugLocked" : "form.slugHelp";
  return field === "displayOrder" ? "form.orderHelp" : "form.requiredHelp";
}

function setProcessing(value: boolean): void {
  processing.value = value;
  emit("processing", value);
}

function submit(): void {
  if (processing.value) return;
  const data = new FormData();
  if (category) {
    data.append("_method", "patch");
    data.append("version", String(category.version));
  }
  for (const field of fields)
    data.append(field, String(values.value[field]).trim());
  data.append("removeImage", category && removeImage.value ? "1" : "0");
  if (image.value) data.append("image", image.value);
  errors.value = {};
  setProcessing(true);
  router.post(
    category
      ? `/admin/catalog/categories/${encodeURIComponent(category.slug)}`
      : "/admin/catalog/categories",
    data,
    {
      preserveScroll: true,
      onError: (responseErrors) => {
        errors.value = { ...responseErrors };
      },
      onSuccess: () => {
        setProcessing(false);
        emit("saved");
      },
      onFinish: () => setProcessing(false),
    },
  );
}

onMounted(() => heading.value?.focus());
</script>

<template>
  <section
    data-category-editor
    class="catalog-editor"
    aria-labelledby="catalog-category-editor-title"
  >
    <h2 id="catalog-category-editor-title" ref="heading" tabindex="-1">
      {{
        tr(
          category
            ? "ad.catalog.form.editTitle"
            : "ad.catalog.form.createTitle",
        )
      }}
    </h2>
    <form
      novalidate
      :aria-busy="processing"
      :aria-describedby="
        errors.version ? 'catalog-category-version-error' : undefined
      "
      @submit.prevent="submit"
    >
      <p
        v-if="errors.version"
        id="catalog-category-version-error"
        class="catalog-error"
        role="alert"
      >
        {{ errors.version }}
      </p>
      <p
        v-for="[field, message] in extraErrors"
        :id="`catalog-category-${field}-error`"
        :key="field"
        class="catalog-error"
        role="alert"
      >
        {{ message }}
      </p>
      <div class="catalog-form-grid">
        <div v-for="field in fields" :key="field" class="catalog-field">
          <label :for="`catalog-category-${field}`">{{
            tr(`ad.catalog.${fieldLabels[field]}`)
          }}</label>
          <input
            :id="`catalog-category-${field}`"
            v-model="values[field]"
            :name="field"
            :type="field === 'displayOrder' ? 'number' : 'text'"
            :min="field === 'displayOrder' ? 0 : undefined"
            :step="field === 'displayOrder' ? 1 : undefined"
            :readonly="field === 'slug' && lockedSlug"
            :disabled="processing"
            :aria-invalid="!!errors[field]"
            :aria-describedby="`catalog-category-${field}-help${errors[field] ? ` catalog-category-${field}-error` : ''}`"
          />
          <p :id="`catalog-category-${field}-help`" class="catalog-help">
            {{ tr(`ad.catalog.${helpKey(field)}`) }}
          </p>
          <p
            v-if="errors[field]"
            :id="`catalog-category-${field}-error`"
            class="catalog-error"
          >
            {{ errors[field] }}
          </p>
        </div>
      </div>
      <CatalogImageInput
        id="catalog-category-image"
        v-model:removed="removeImage"
        :current-image="category?.image ?? null"
        :disabled="processing"
        :label="tr('ad.catalog.form.image')"
        help-id="catalog-category-image-help"
        error-id="catalog-category-image-error"
        :error="errors.image"
        @change="image = $event"
      />
      <p v-if="processing" role="status">{{ tr("ad.catalog.processing") }}</p>
      <div class="catalog-form-actions">
        <button
          type="submit"
          class="catalog-control catalog-control--primary"
          :disabled="processing"
        >
          {{
            tr(
              category
                ? "ad.catalog.form.saveChanges"
                : "ad.catalog.form.create",
            )
          }}
        </button>
        <button
          data-category-cancel
          type="button"
          class="catalog-control"
          :disabled="processing"
          @click="!processing && emit('cancel')"
        >
          {{ tr("ad.catalog.form.cancel") }}
        </button>
      </div>
    </form>
  </section>
</template>
