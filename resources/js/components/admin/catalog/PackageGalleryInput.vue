<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import type { AdminCatalogGalleryItem } from "@/types";

const props = withDefaults(
  defineProps<{
    retained: readonly AdminCatalogGalleryItem[];
    uploads: readonly File[];
    disabled?: boolean;
    error?: string;
  }>(),
  { disabled: false, error: undefined },
);
const emit = defineEmits<{
  "update:retained": [ids: readonly string[]];
  "update:uploads": [files: readonly File[]];
}>();
const { tr } = useLanguage();
const input = ref<HTMLInputElement | null>(null);
const localUploads = ref<readonly File[]>([...props.uploads]);
const local = new Map<File, { key: string; url: string }>();
let sequence = 0;
const feedback = ref<string | null>(null);
const canAdd = computed(
  () => props.retained.length + localUploads.value.length < 6,
);
watch(
  () => props.uploads,
  (uploads) => {
    localUploads.value = [...uploads];
  },
);
function preview(file: File): string {
  return local.get(file)?.url ?? "";
}
function clearInput(): void {
  if (input.value) input.value.value = "";
}
function select(event: Event): void {
  if (props.disabled) return;
  feedback.value = null;
  const selected = [...((event.target as HTMLInputElement).files ?? [])];
  const valid = selected.filter((file) =>
    ["image/jpeg", "image/png", "image/webp"].includes(file.type),
  );
  if (valid.length !== selected.length)
    feedback.value = tr("ad.catalog.form.galleryTypeError");
  const allowed = valid.slice(
    0,
    Math.max(0, 6 - props.retained.length - localUploads.value.length),
  );
  if (allowed.length !== valid.length)
    feedback.value = tr("ad.catalog.form.galleryLimit");
  for (const file of allowed)
    local.set(file, {
      key: `upload-${++sequence}`,
      url: URL.createObjectURL(file),
    });
  localUploads.value = [...localUploads.value, ...allowed];
  emit("update:uploads", localUploads.value);
  clearInput();
}
function removeUpload(file: File): void {
  const item = local.get(file);
  if (item) URL.revokeObjectURL(item.url);
  local.delete(file);
  localUploads.value = localUploads.value.filter((upload) => upload !== file);
  emit("update:uploads", localUploads.value);
}
function removeRetained(id: string): void {
  emit(
    "update:retained",
    props.retained
      .filter((item) => item.retentionId !== id)
      .map((item) => item.retentionId),
  );
}
onBeforeUnmount(() => {
  for (const item of local.values()) URL.revokeObjectURL(item.url);
  local.clear();
});
</script>

<template>
  <section class="catalog-gallery-input">
    <label for="catalog-gallery-upload">{{
      tr("ad.catalog.form.gallery")
    }}</label>
    <p class="catalog-help">{{ tr("ad.catalog.form.galleryHelp") }}</p>
    <input
      id="catalog-gallery-upload"
      ref="input"
      type="file"
      multiple
      accept="image/jpeg,image/png,image/webp"
      :disabled="disabled || !canAdd"
      @change="select"
    />
    <p v-if="feedback" class="catalog-error" role="alert">{{ feedback }}</p>
    <p v-if="error" class="catalog-error" role="alert">{{ error }}</p>
    <div class="catalog-gallery-previews">
      <figure v-for="item in retained" :key="item.retentionId">
        <img :src="item.url" alt="" /><button
          type="button"
          class="catalog-control"
          :disabled="disabled"
          data-gallery-remove-retained
          @click="removeRetained(item.retentionId)"
        >
          {{ tr("ad.catalog.form.removeImage") }}
        </button>
      </figure>
      <figure v-for="file in localUploads" :key="local.get(file)?.key">
        <img :src="preview(file)" alt="" /><button
          type="button"
          class="catalog-control"
          :disabled="disabled"
          data-gallery-remove-upload
          @click="removeUpload(file)"
        >
          {{ tr("ad.catalog.form.removeImage") }}
        </button>
      </figure>
    </div>
  </section>
</template>
