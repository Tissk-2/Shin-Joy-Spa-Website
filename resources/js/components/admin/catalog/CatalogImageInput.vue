<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from "vue";
import { useLanguage } from "@/composables/useLanguage";

const props = withDefaults(
  defineProps<{
    currentImage: string | null;
    disabled?: boolean;
    id: string;
    label: string;
    helpId: string;
    errorId: string;
    error?: string;
    removed?: boolean;
  }>(),
  { disabled: false, removed: false, error: undefined },
);
const emit = defineEmits<{
  change: [file: File | null];
  "update:removed": [removed: boolean];
}>();
const { tr } = useLanguage();
const input = ref<HTMLInputElement | null>(null);
const localPreview = ref<string | null>(null);
const preview = computed(
  () => localPreview.value ?? (props.removed ? null : props.currentImage),
);

function revokePreview(): void {
  if (localPreview.value) URL.revokeObjectURL(localPreview.value);
  localPreview.value = null;
}

function selectImage(event: Event): void {
  if (props.disabled) return;
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  revokePreview();
  localPreview.value = URL.createObjectURL(file);
  emit("change", file);
  emit("update:removed", false);
}

function removeImage(): void {
  if (props.disabled) return;
  revokePreview();
  if (input.value) input.value.value = "";
  emit("change", null);
  emit("update:removed", true);
}

onBeforeUnmount(revokePreview);
</script>

<template>
  <div class="catalog-field catalog-image-input">
    <label :for="id">{{ label }}</label>
    <p :id="helpId" class="catalog-help">{{ tr("ad.catalog.upload.help") }}</p>
    <input
      :id="id"
      ref="input"
      name="image"
      type="file"
      accept="image/jpeg,image/png,image/webp"
      :disabled="disabled"
      :aria-describedby="`${helpId}${error ? ` ${errorId}` : ''}`"
      :aria-invalid="!!error"
      @change="selectImage"
    />
    <img
      v-if="preview"
      data-image-preview
      class="catalog-image-preview"
      :src="preview"
      :alt="
        tr(
          localPreview
            ? 'ad.catalog.form.selectedPreview'
            : 'ad.catalog.form.currentPreview',
        )
      "
    />
    <div class="catalog-form-actions">
      <button
        v-if="preview"
        type="button"
        class="catalog-control"
        :disabled="disabled"
        @click="input?.click()"
      >
        {{ tr("ad.catalog.form.replaceImage") }}
      </button>
      <button
        v-if="preview"
        data-image-remove
        type="button"
        class="catalog-control"
        :disabled="disabled"
        @click="removeImage"
      >
        {{ tr("ad.catalog.form.removeImage") }}
      </button>
      <template v-if="removed">
        <p class="catalog-help" role="status">
          {{ tr("ad.catalog.form.imageRemoved") }}
        </p>
        <button
          v-if="currentImage"
          type="button"
          class="catalog-control"
          :disabled="disabled"
          @click="emit('update:removed', false)"
        >
          {{ tr("ad.catalog.form.undoRemove") }}
        </button>
      </template>
    </div>
    <p v-if="error" :id="errorId" class="catalog-error">{{ error }}</p>
  </div>
</template>
