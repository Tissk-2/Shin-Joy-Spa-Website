<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { DialogContent, DialogOverlay, DialogPortal } from "radix-vue";
import { computed, nextTick, ref } from "vue";
import { Dialog, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { useLanguage } from "@/composables/useLanguage";
import type {
  AdminCatalogCategoryData,
  AdminCatalogPackageData,
  AdminCatalogPackageFormData,
} from "@/types";

const props = withDefaults(
  defineProps<{
    category?: AdminCatalogCategoryData;
    package?: AdminCatalogPackageData | AdminCatalogPackageFormData;
    disabled?: boolean;
  }>(),
  { disabled: false },
);
const { language, tr } = useLanguage();
const actions = ["publish", "archive", "restore", "delete"] as const;
type Action = (typeof actions)[number];
const availableActions = computed(() =>
  actions.filter((action) => record.value.can[action]),
);
const record = computed(() => props.category ?? props.package!);
const recordType = computed(() => (props.category ? "category" : "package"));
const selection = ref<{
  action: Action;
  record:
    | AdminCatalogCategoryData
    | AdminCatalogPackageData
    | AdminCatalogPackageFormData;
} | null>(null);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const heading = ref<HTMLHeadingElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const name = computed(() =>
  language.value === "id"
    ? selection.value?.record.nameId
    : selection.value?.record.nameEn,
);

function open(action: Action, event: MouseEvent): void {
  if (processing.value || props.disabled || !record.value.can[action]) return;
  trigger.value = event.currentTarget as HTMLButtonElement;
  errors.value = {};
  selection.value = { action, record: record.value };
}

function close(open = false): void {
  if (!open && !processing.value) selection.value = null;
}

function restoreFocus(event: Event): void {
  event.preventDefault();
  void nextTick(() => {
    if (trigger.value?.isConnected && !trigger.value.disabled)
      trigger.value.focus();
  });
}

function confirm(): void {
  if (processing.value || !selection.value) return;
  const { action, record: selectedRecord } = selection.value;
  const collection =
    recordType.value === "category" ? "categories" : "packages";
  const url = `/admin/catalog/${collection}/${encodeURIComponent(selectedRecord.slug)}`;
  const data = { version: selectedRecord.version };
  errors.value = {};
  processing.value = true;
  const options = {
    preserveScroll: true,
    onError: (responseErrors: Record<string, string>) => {
      errors.value = { ...responseErrors };
    },
    onSuccess: () => {
      selection.value = null;
    },
    onFinish: () => {
      processing.value = false;
    },
  };
  if (action === "delete") router.delete(url, { ...options, data });
  else router.post(`${url}/${action}`, data, options);
}
</script>

<template>
  <button
    v-for="action in availableActions"
    :key="action"
    type="button"
    class="catalog-control"
    :disabled="disabled || processing"
    :data-catalog-action="`${record.slug}-${action}`"
    @click="open(action, $event)"
  >
    {{ tr(`ad.catalog.${action}`) }}
  </button>
  <Dialog :open="selection !== null" @update:open="close">
    <DialogPortal>
      <DialogOverlay class="catalog-dialog-overlay" />
      <DialogContent
        class="admin-shell catalog-dialog"
        role="alertdialog"
        @open-auto-focus="
          (event) => {
            event.preventDefault();
            heading?.focus();
          }
        "
        @close-auto-focus="restoreFocus"
        @interact-outside.prevent
        @escape-key-down="
          (event) => {
            if (processing) event.preventDefault();
          }
        "
      >
        <DialogTitle as-child
          ><h2 ref="heading" tabindex="-1">
            {{
              selection &&
              tr(`ad.catalog.lifecycle.${recordType}.${selection.action}.title`)
            }}
          </h2></DialogTitle
        >
        <DialogDescription>{{
          selection &&
          tr(`ad.catalog.lifecycle.${recordType}.${selection.action}.body`, {
            name: name ?? "",
          })
        }}</DialogDescription>
        <form :aria-busy="processing" @submit.prevent="confirm">
          <p
            v-for="(message, field) in errors"
            :key="field"
            class="catalog-error"
            role="alert"
          >
            {{ message }}
          </p>
          <p v-if="processing" role="status">
            {{ tr("ad.catalog.processing") }}
          </p>
          <div class="catalog-form-actions">
            <button
              data-lifecycle-cancel
              type="button"
              class="catalog-control"
              :disabled="processing"
              @click="close()"
            >
              {{ tr("ad.catalog.form.cancel") }}
            </button>
            <button
              data-lifecycle-confirm
              type="submit"
              class="catalog-control catalog-control--primary"
              :disabled="processing"
            >
              {{
                selection &&
                tr(
                  `ad.catalog.lifecycle.${recordType}.${selection.action}.confirm`,
                )
              }}
            </button>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </Dialog>
</template>
