<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { ChevronLeft } from "lucide-vue-next";
import { computed, ref } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type {
  AdminRoomVariant,
  RoomEditData,
  RoomManagementAccess,
} from "@/types";

const props = defineProps<{
  management: RoomManagementAccess;
  room: RoomEditData | null;
  variants: readonly AdminRoomVariant[];
}>();
const { tr, language } = useLanguage();
const page = usePage();
const flash = computed(
  () => (page.props.flash as { status?: string } | undefined)?.status,
);
const valuesFor = (room: RoomEditData | null) => ({
  code: room?.code ?? "",
  name: room?.name ?? "",
  type: room?.type ?? "",
  capacity: room ? String(room.capacity) : "",
  cleaningMinutes: room ? String(room.cleaningMinutes) : "",
  isActive: room?.isActive ?? false,
  compatibleVariantIds: [...(room?.compatibleVariantIds ?? [])],
});
const values = ref(valuesFor(props.room));
const version = ref(props.room?.version);
const roomId = ref(props.room?.id);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const title = computed(() =>
  tr(roomId.value ? "ad.rooms.edit" : "ad.rooms.create"),
);
const numericFields = ["capacity", "cleaningMinutes"] as const;
const choiceErrors = computed(() =>
  Object.entries(errors.value)
    .filter(([field]) => field.startsWith("compatibleVariantIds"))
    .map(([, message]) => message),
);
function submit() {
  if (processing.value || !props.management.ready || !props.management.isOwner)
    return;
  processing.value = true;
  errors.value = {};
  const data = {
    ...values.value,
    compatibleVariantIds: [...values.value.compatibleVariantIds],
    ...(roomId.value ? { version: version.value } : {}),
  };
  const options = {
    preserveScroll: true,
    onError: (responseErrors: Record<string, string>) => {
      errors.value = responseErrors;
    },
    onSuccess: (response: { props: Record<string, unknown> }) => {
      const saved = response.props.room as RoomEditData | null;
      values.value = valuesFor(saved);
      version.value = saved?.version;
      roomId.value = saved?.id;
    },
    onFinish: () => {
      processing.value = false;
    },
  };
  if (roomId.value) router.patch(`/admin/rooms/${roomId.value}`, data, options);
  else router.post("/admin/rooms", data, options);
}
// Options listed under their package, in catalog order.
const variantGroups = computed(() => {
  const groups = new Map<
    string,
    { key: string; name: string; variants: AdminRoomVariant[] }
  >();
  for (const variant of props.variants) {
    const key = `${variant.serviceNameId}\u0000${variant.serviceNameEn}`;
    const group = groups.get(key) ?? {
      key,
      name:
        language.value === "id" ? variant.serviceNameId : variant.serviceNameEn,
      variants: [],
    };
    group.variants.push(variant);
    groups.set(key, group);
  }
  return [...groups.values()];
});
// Only what is not published needs saying; published is the normal case.
function catalogNotes(variant: AdminRoomVariant): string {
  return (
    [
      ["ad.rooms.variantLabel", variant.variantStatus],
      ["ad.rooms.serviceLabel", variant.serviceStatus],
      ["ad.rooms.categoryLabel", variant.categoryStatus],
    ] as const
  )
    .filter(([, status]) => status !== "published")
    .map(([label, status]) => `${tr(label)}: ${tr(`ad.rooms.${status}`)}`)
    .join(" · ");
}
function selectVariant(id: number, checked: boolean) {
  values.value = {
    ...values.value,
    compatibleVariantIds: checked
      ? [...values.value.compatibleVariantIds, id]
      : values.value.compatibleVariantIds.filter((value) => value !== id),
  };
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout :title="title" :subtitle="tr('ad.rooms.formSubtitle')">
      <div class="admin-content room-form-content">
        <a href="/admin/rooms" class="admin-back-link"
          ><ChevronLeft aria-hidden="true" />{{ tr("ad.rooms.back") }}</a
        >
        <p
          v-if="flash"
          role="status"
          class="admin-filter-panel text-[var(--admin-success)]"
        >
          {{ flash }}
        </p>
        <p
          v-if="!management.ready || !management.isOwner"
          role="status"
          class="admin-empty"
        >
          {{ tr("ad.rooms.writeUnavailable") }}
        </p>
        <form
          v-else
          class="space-y-5"
          :aria-busy="processing"
          @submit.prevent="submit"
        >
          <div
            v-if="errors.room || errors.version"
            role="alert"
            class="room-error-panel space-y-2"
          >
            <p v-if="errors.room">{{ errors.room }}</p>
            <p v-if="errors.version">{{ errors.version }}</p>
            <a
              v-if="errors.version"
              :href="`/admin/rooms/${roomId}/edit`"
              class="font-semibold underline"
              >{{ tr("ad.rooms.reloadDraft") }}</a
            >
          </div>
          <fieldset
            :disabled="processing"
            class="admin-filter-panel block min-w-0 space-y-5"
          >
            <legend class="sr-only">
              {{ tr("ad.rooms.physicalDetails") }}
            </legend>
            <h2 class="text-base font-bold">
              {{ tr("ad.rooms.physicalDetails") }}
            </h2>
            <div class="grid gap-5 sm:grid-cols-2">
              <div
                v-for="field in ['code', 'name'] as const"
                :key="field"
                class="min-w-0"
              >
                <label :for="field" class="mb-1 block text-sm font-semibold"
                  >{{ tr(`ad.rooms.${field}`) }}
                  <span aria-hidden="true">*</span></label
                >
                <input
                  :id="field"
                  v-model="values[field]"
                  required
                  :maxlength="field === 'code' ? 64 : 255"
                  class="admin-control"
                  :aria-invalid="!!errors[field]"
                  :aria-describedby="
                    errors[field] ? `${field}-error` : undefined
                  "
                />
                <p
                  v-if="errors[field]"
                  :id="`${field}-error`"
                  class="room-field-error"
                >
                  {{ errors[field] }}
                </p>
              </div>
              <div class="min-w-0 sm:col-span-2">
                <label for="type" class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.rooms.type") }}
                  {{ tr("ad.rooms.optional") }}</label
                >
                <input
                  id="type"
                  v-model="values.type"
                  maxlength="64"
                  class="admin-control"
                  aria-describedby="type-help type-error"
                  :aria-invalid="!!errors.type"
                />
                <p
                  id="type-help"
                  class="mt-1 text-xs text-[var(--admin-muted)]"
                >
                  {{ tr("ad.rooms.typeHelp") }}
                </p>
                <p v-if="errors.type" id="type-error" class="room-field-error">
                  {{ errors.type }}
                </p>
              </div>
              <div v-for="field in numericFields" :key="field" class="min-w-0">
                <label :for="field" class="mb-1 block text-sm font-semibold"
                  >{{
                    tr(
                      `ad.rooms.${field === "capacity" ? "capacity" : "cleaning"}`,
                    )
                  }}
                  <span aria-hidden="true">*</span></label
                >
                <input
                  :id="field"
                  v-model="values[field]"
                  type="number"
                  inputmode="numeric"
                  required
                  step="1"
                  :min="field === 'capacity' ? 1 : 0"
                  :max="field === 'capacity' ? 4294967295 : 720"
                  class="admin-control"
                  :aria-invalid="!!errors[field]"
                  :aria-describedby="`${field}-help ${field}-error`"
                />
                <p
                  :id="`${field}-help`"
                  class="mt-1 text-xs text-[var(--admin-muted)]"
                >
                  {{
                    tr(
                      field === "capacity"
                        ? "ad.rooms.capacityHelp"
                        : "ad.rooms.cleaningHelp",
                    )
                  }}
                </p>
                <p
                  v-if="errors[field]"
                  :id="`${field}-error`"
                  class="room-field-error"
                >
                  {{ errors[field] }}
                </p>
              </div>
            </div>
            <div>
              <label class="admin-check w-fit" for="isActive"
                ><input
                  id="isActive"
                  v-model="values.isActive"
                  type="checkbox"
                />{{ tr("ad.rooms.enableRoom") }}</label
              >
              <p class="mt-1 text-xs text-[var(--admin-muted)]">
                {{ tr("ad.rooms.activeHelp") }}
              </p>
              <p v-if="errors.isActive" class="room-field-error">
                {{ errors.isActive }}
              </p>
            </div>
          </fieldset>
          <fieldset
            :disabled="processing"
            class="admin-filter-panel block min-w-0 space-y-4"
            aria-describedby="compatibility-help"
          >
            <legend class="sr-only">{{ tr("ad.rooms.treatments") }}</legend>
            <h2 class="text-base font-bold">{{ tr("ad.rooms.treatments") }}</h2>
            <p
              id="compatibility-help"
              class="text-sm text-[var(--admin-muted)]"
            >
              {{ tr("ad.rooms.compatibilityHelp") }}
            </p>
            <p
              v-if="!values.compatibleVariantIds.length"
              role="status"
              class="text-sm text-[var(--admin-warning)]"
            >
              {{ tr("ad.rooms.emptyCompatibility") }}
            </p>
            <div
              v-if="choiceErrors.length"
              role="alert"
              class="room-field-error"
            >
              <p v-for="message in choiceErrors" :key="message">
                {{ message }}
              </p>
            </div>
            <p
              v-if="!variants.length"
              class="text-sm text-[var(--admin-muted)]"
            >
              {{ tr("ad.rooms.noCatalogChoices") }}
            </p>
            <div
              v-for="(group, index) in variantGroups"
              :key="group.key"
              class="admin-variant-group"
              role="group"
              :aria-labelledby="`variant-group-${index}`"
            >
              <p
                :id="`variant-group-${index}`"
                class="admin-variant-group__name"
              >
                {{ group.name }}
              </p>
              <div class="admin-variant-group__options">
                <label
                  v-for="variant in group.variants"
                  :key="variant.id"
                  class="admin-variant-option"
                  :title="variant.code"
                >
                  <input
                    type="checkbox"
                    :value="variant.id"
                    :checked="values.compatibleVariantIds.includes(variant.id)"
                    @change="
                      selectVariant(
                        variant.id,
                        ($event.target as HTMLInputElement).checked,
                      )
                    "
                  />
                  <span
                    >{{
                      tr(
                        variant.treatmentMode === "regular"
                          ? "ad.rooms.regular"
                          : "ad.rooms.doubleTherapist",
                      )
                    }}
                    · {{ variant.durationMinutes }}
                    {{ tr("ad.rooms.minutes") }}
                    <span
                      v-if="catalogNotes(variant)"
                      class="admin-variant-option__status"
                      >{{ catalogNotes(variant) }}</span
                    ></span
                  >
                </label>
              </div>
            </div>
          </fieldset>
          <div class="flex flex-wrap items-center gap-4 pb-6">
            <button
              type="submit"
              :disabled="processing"
              class="admin-button admin-button--primary disabled:cursor-wait disabled:opacity-60"
            >
              {{ tr(processing ? "ad.rooms.saving" : "ad.rooms.save") }}
            </button>
            <a href="/admin/rooms" class="admin-button">{{
              tr("ad.rooms.cancel")
            }}</a>
          </div>
        </form>
      </div>
    </AdminLayout>
  </div>
</template>

<style scoped>
.room-form-content {
  max-width: 64rem;
}
.room-field-error {
  margin-top: 0.375rem;
  font-size: 0.875rem;
  color: var(--admin-danger, #a12435);
}
.room-error-panel {
  padding: 1rem;
  border: 1px solid var(--admin-danger, #a12435);
  border-radius: 0.5rem;
  color: var(--admin-danger, #a12435);
}
</style>
