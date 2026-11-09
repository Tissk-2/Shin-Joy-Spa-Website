<script setup lang="ts">
import { router, useForm, usePage } from "@inertiajs/vue3";
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type { AppPageProps } from "@/types";

interface ContentProps {
  sections: Record<string, string[]>;
  values: Record<string, { id?: string; en?: string }>;
  revision: number;
}

const props = defineProps<ContentProps>();
const page = usePage<AppPageProps>();
const { tr } = useLanguage();

const fieldLabelKeys: Record<string, string> = {
  "hero.eyebrow": "ad.content.field.eyebrow",
  "hero.heading": "ad.content.field.title",
  "hero.headingAccent": "ad.content.field.title2",
  "hero.sub": "ad.content.field.subtitle",
  "about.eyebrow": "ad.content.field.eyebrow",
  "about.title": "ad.content.field.title",
  "about.body": "ad.content.field.text",
  "gallery.eyebrow": "ad.content.field.eyebrow",
  "gallery.title": "ad.content.field.title",
  "gallery.sub": "ad.content.field.subtitle",
  "loc.address": "ad.content.field.address",
  "loc.hours": "ad.content.field.hours",
  "loc.phone": "ad.content.field.phone",
};

const sectionLabelKeys: Record<string, string> = {
  hero: "ad.content.section.hero",
  about: "ad.content.section.about",
  gallery: "ad.content.section.gallery",
  contact: "ad.content.section.contact",
};

const longFields = new Set([
  "hero.sub",
  "about.body",
  "gallery.sub",
  "loc.address",
]);

function editorValues(
  source: ContentProps["values"],
): Record<string, { id: string; en: string }> {
  const next: Record<string, { id: string; en: string }> = {};
  for (const keys of Object.values(props.sections)) {
    for (const key of keys) {
      next[key] = {
        id: source[key]?.id ?? "",
        en: source[key]?.en ?? "",
      };
    }
  }
  return next;
}

function cloneValues(
  source: Record<string, { id: string; en: string }>,
): Record<string, { id: string; en: string }> {
  return Object.fromEntries(
    Object.entries(source).map(([key, entry]) => [key, { ...entry }]),
  );
}

const form = useForm({
  revision: props.revision,
  values: editorValues(props.values),
});
const original = ref(cloneValues(form.values));

// The editor stays mounted across save/reset round trips, so the inputs follow
// the authoritative server state instead of the values typed before the post.
// A rejected save keeps the admin's typing (and the old revision, so a stale
// save stays stale) instead of overwriting it with the server's values.
watch(
  () => props.values,
  (next) => {
    if (Object.keys(page.props.errors ?? {}).length > 0) {
      return;
    }
    for (const key of Object.keys(form.values)) {
      form.values[key] = {
        id: next[key]?.id ?? "",
        en: next[key]?.en ?? "",
      };
    }
    form.revision = props.revision;
    original.value = cloneValues(form.values);
  },
);

const confirmingReset = ref(false);
const cancelButton = ref<HTMLButtonElement | null>(null);

function sectionLabel(sectionKey: string): string {
  return tr(sectionLabelKeys[sectionKey] ?? sectionKey);
}

function fieldLabel(key: string): string {
  return tr(fieldLabelKeys[key] ?? key);
}

function inputId(key: string, language: "id" | "en"): string {
  return `content-${key.replaceAll(".", "-")}-${language}`;
}

function errorFor(key: string, language: "id" | "en"): string | undefined {
  return form.errors[`values.${key}.${language}`];
}

function isLong(key: string): boolean {
  return longFields.has(key);
}

const dirty = computed(() => {
  const changed: Record<string, { id: string; en: string }> = {};
  for (const [key, entry] of Object.entries(form.values)) {
    const before = original.value[key] ?? { id: "", en: "" };
    if (entry.id !== before.id || entry.en !== before.en) {
      changed[key] = { id: entry.id, en: entry.en };
    }
  }
  return changed;
});
const hasChanges = computed(() => Object.keys(dirty.value).length > 0);
// Reset posts through the router, so its stale error lands in page errors.
const revisionError = computed(
  () => form.errors.revision ?? page.props.errors?.revision,
);

function submit(): void {
  if (!hasChanges.value) {
    return;
  }
  const values = dirty.value;
  form
    .transform(() => ({ revision: form.revision, values }))
    .post("/admin/content", { preserveScroll: true });
}

function openReset(): void {
  confirmingReset.value = true;
  nextTick(() => cancelButton.value?.focus());
}

function closeReset(): void {
  confirmingReset.value = false;
}

function confirmReset(): void {
  confirmingReset.value = false;
  router.post(
    "/admin/content/reset",
    { revision: form.revision },
    { preserveScroll: true },
  );
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.key === "Escape" && confirmingReset.value) {
    closeReset();
  }
}

onBeforeUnmount(() => {
  document.removeEventListener("keydown", handleKeydown);
});
document.addEventListener("keydown", handleKeydown);
</script>

<template>
  <AdminLayout
    :title="tr('ad.content.title')"
    :subtitle="tr('ad.content.subtitle')"
  >
    <div class="admin-content admin-content-editor">
      <p
        v-if="page.props.flash.status"
        class="admin-content-editor__flash"
        role="status"
        aria-live="polite"
      >
        {{ page.props.flash.status }}
      </p>
      <p class="admin-content-editor__note">{{ tr("ad.content.note") }}</p>
      <p v-if="form.errors.values" class="admin-filter-error" role="alert">
        {{ form.errors.values }}
      </p>
      <p v-if="revisionError" class="admin-filter-error" role="alert">
        {{ revisionError }}
      </p>

      <form novalidate :aria-busy="form.processing" @submit.prevent="submit">
        <section
          v-for="(keys, sectionKey) in props.sections"
          :key="sectionKey"
          class="admin-content-editor__section"
          :data-content-section="sectionKey"
        >
          <h2>{{ sectionLabel(sectionKey) }}</h2>

          <div
            v-for="key in keys"
            :key="key"
            class="admin-content-field"
            role="group"
            :aria-label="fieldLabel(key)"
            :data-content-field="key"
          >
            <span class="admin-content-field__label">{{
              fieldLabel(key)
            }}</span>
            <div class="admin-content-field__pair">
              <div class="admin-content-field__cell">
                <label
                  class="admin-content-field__lang"
                  :for="inputId(key, 'id')"
                  >{{ tr("ad.content.captionId") }}</label
                >
                <textarea
                  v-if="isLong(key)"
                  :id="inputId(key, 'id')"
                  v-model="form.values[key].id"
                  rows="3"
                  :maxlength="1000"
                  :aria-invalid="Boolean(errorFor(key, 'id'))"
                  :aria-describedby="
                    errorFor(key, 'id')
                      ? `${inputId(key, 'id')}-error`
                      : undefined
                  "
                ></textarea>
                <input
                  v-else
                  :id="inputId(key, 'id')"
                  v-model="form.values[key].id"
                  type="text"
                  :maxlength="1000"
                  :aria-invalid="Boolean(errorFor(key, 'id'))"
                  :aria-describedby="
                    errorFor(key, 'id')
                      ? `${inputId(key, 'id')}-error`
                      : undefined
                  "
                />
                <span
                  v-if="errorFor(key, 'id')"
                  :id="`${inputId(key, 'id')}-error`"
                  class="admin-filter-error"
                  role="alert"
                  >{{ errorFor(key, "id") }}</span
                >
              </div>
              <div class="admin-content-field__cell">
                <label
                  class="admin-content-field__lang"
                  :for="inputId(key, 'en')"
                  >{{ tr("ad.content.captionEn") }}</label
                >
                <textarea
                  v-if="isLong(key)"
                  :id="inputId(key, 'en')"
                  v-model="form.values[key].en"
                  rows="3"
                  :maxlength="1000"
                  :aria-invalid="Boolean(errorFor(key, 'en'))"
                  :aria-describedby="
                    errorFor(key, 'en')
                      ? `${inputId(key, 'en')}-error`
                      : undefined
                  "
                ></textarea>
                <input
                  v-else
                  :id="inputId(key, 'en')"
                  v-model="form.values[key].en"
                  type="text"
                  :maxlength="1000"
                  :aria-invalid="Boolean(errorFor(key, 'en'))"
                  :aria-describedby="
                    errorFor(key, 'en')
                      ? `${inputId(key, 'en')}-error`
                      : undefined
                  "
                />
                <span
                  v-if="errorFor(key, 'en')"
                  :id="`${inputId(key, 'en')}-error`"
                  class="admin-filter-error"
                  role="alert"
                  >{{ errorFor(key, "en") }}</span
                >
              </div>
            </div>
          </div>
        </section>

        <div class="admin-content-editor__actions">
          <button
            type="button"
            class="admin-button"
            data-content-reset
            :disabled="form.processing"
            @click="openReset"
          >
            {{ tr("ad.content.reset") }}
          </button>
          <button
            type="submit"
            class="admin-button admin-button--primary"
            data-content-save
            :disabled="form.processing || !hasChanges"
            :aria-busy="form.processing"
          >
            {{
              form.processing
                ? tr("ad.content.processing")
                : tr("ad.content.save")
            }}
          </button>
        </div>
      </form>

      <template v-if="confirmingReset">
        <div class="admin-dialog-overlay" @click="closeReset"></div>
        <div
          class="admin-dialog-content"
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="content-reset-title"
          aria-describedby="content-reset-body"
        >
          <h2 id="content-reset-title">{{ tr("ad.content.reset") }}</h2>
          <p id="content-reset-body">{{ tr("ad.content.resetConfirm") }}</p>
          <div class="admin-status-actions">
            <button
              ref="cancelButton"
              type="button"
              class="admin-button"
              data-content-reset-cancel
              @click="closeReset"
            >
              {{ tr("ad.catalog.form.cancel") }}
            </button>
            <button
              type="button"
              class="admin-button admin-button--primary"
              data-content-reset-confirm
              @click="confirmReset"
            >
              {{ tr("ad.content.reset") }}
            </button>
          </div>
        </div>
      </template>
    </div>
  </AdminLayout>
</template>
