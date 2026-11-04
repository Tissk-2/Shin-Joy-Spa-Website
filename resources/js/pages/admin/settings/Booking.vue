<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type { BookingSettingsData } from "@/types";

const props = defineProps<{
  state: "configured" | "unconfigured" | "invalid" | "unavailable";
  settings: BookingSettingsData | null;
  version: number | null;
}>();
const { tr } = useLanguage();
const page = usePage();
const flash = computed(
  () => (page.props.flash as { status?: string } | undefined)?.status,
);
const fields = [
  "booking_horizon_days",
  "minimum_lead_minutes",
  "review_target_minutes",
  "minimum_checkout_minutes",
] as const;
const bounds = {
  booking_horizon_days: [1, 365],
  minimum_lead_minutes: [0, 525600],
  review_target_minutes: [1, 10080],
  minimum_checkout_minutes: [1, 60],
} as const;
const valuesFor = (settings: BookingSettingsData | null) => ({
  booking_horizon_days: settings ? String(settings.booking_horizon_days) : "",
  minimum_lead_minutes: settings ? String(settings.minimum_lead_minutes) : "",
  review_target_minutes: settings ? String(settings.review_target_minutes) : "",
  minimum_checkout_minutes: settings
    ? String(settings.minimum_checkout_minutes)
    : "",
  review_intervals: (settings?.review_intervals ?? []).map((row) => ({
    weekday: String(row.weekday),
    start: row.start,
    end: row.end,
  })),
});
const values = ref(valuesFor(props.settings));
const version = ref(props.version);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const canSave = computed(
  () => props.state !== "unavailable" && version.value !== null,
);
const intervalError = (index: number, field: string) =>
  errors.value[`review_intervals.${index}.${field}`];
function addInterval() {
  values.value = {
    ...values.value,
    review_intervals: [
      ...values.value.review_intervals,
      { weekday: "", start: "", end: "" },
    ],
  };
}
function removeInterval(index: number) {
  values.value = {
    ...values.value,
    review_intervals: values.value.review_intervals.filter(
      (_, position) => position !== index,
    ),
  };
}
function submit() {
  if (processing.value || !canSave.value) return;
  processing.value = true;
  errors.value = {};
  router.patch(
    "/admin/settings/booking",
    {
      ...values.value,
      review_intervals: values.value.review_intervals.map((row) => ({
        ...row,
      })),
      version: version.value,
    },
    {
      preserveScroll: true,
      onError: (responseErrors) => {
        errors.value = responseErrors;
      },
      onSuccess: (response) => {
        values.value = valuesFor(
          response.props.settings as BookingSettingsData | null,
        );
        version.value = response.props.version as number | null;
      },
      onFinish: () => {
        processing.value = false;
      },
    },
  );
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.settings.title')"
      :subtitle="tr('ad.settings.purpose')"
    >
      <div class="admin-content settings-content">
        <p
          v-if="flash"
          role="status"
          class="admin-filter-panel text-[var(--admin-success)]"
        >
          {{ flash }}
        </p>
        <p
          v-if="state !== 'configured'"
          role="status"
          class="admin-filter-panel"
        >
          {{ tr(`ad.settings.${state}`) }}
        </p>
        <form
          v-if="canSave"
          :aria-busy="processing"
          class="space-y-5"
          @submit.prevent="submit"
        >
          <div
            v-if="Object.keys(errors).length"
            role="alert"
            class="settings-errors space-y-2"
          >
            <p v-for="(message, field) in errors" :key="field">{{ message }}</p>
            <a
              v-if="errors.version"
              href="/admin/settings/booking"
              class="font-semibold underline"
              >{{ tr("ad.settings.reload") }}</a
            >
          </div>
          <fieldset
            :disabled="processing"
            class="admin-filter-panel block min-w-0 space-y-5"
          >
            <legend class="sr-only">{{ tr("ad.settings.limits") }}</legend>
            <h2 class="font-bold">{{ tr("ad.settings.limits") }}</h2>
            <div class="grid gap-5 sm:grid-cols-2">
              <div v-for="field in fields" :key="field" class="min-w-0">
                <label :for="field" class="mb-1 block text-sm font-semibold">{{
                  tr(`ad.settings.${field}`)
                }}</label>
                <input
                  :id="field"
                  v-model="values[field]"
                  type="number"
                  inputmode="numeric"
                  required
                  step="1"
                  :min="bounds[field][0]"
                  :max="bounds[field][1]"
                  class="admin-control"
                  :aria-invalid="!!errors[field]"
                  :aria-describedby="`${field}-help ${field}-error`"
                />
                <p
                  :id="`${field}-help`"
                  class="mt-1 text-xs text-[var(--admin-muted)]"
                >
                  {{ tr(`ad.settings.${field}Help`) }}
                </p>
                <p
                  v-if="errors[field]"
                  :id="`${field}-error`"
                  class="settings-field-error"
                >
                  {{ errors[field] }}
                </p>
              </div>
            </div>
          </fieldset>
          <fieldset
            :disabled="processing"
            class="admin-filter-panel block min-w-0 space-y-4"
          >
            <legend class="sr-only">{{ tr("ad.settings.hours") }}</legend>
            <h2 class="font-bold">{{ tr("ad.settings.hours") }}</h2>
            <p class="text-sm text-[var(--admin-muted)]">
              {{ tr("ad.settings.hoursHelp") }}
            </p>
            <p v-if="!values.review_intervals.length" class="text-sm">
              {{ tr("ad.settings.noHours") }}
            </p>
            <div
              v-for="(row, index) in values.review_intervals"
              :key="index"
              data-review-interval
              class="interval-row grid min-w-0 gap-3 rounded-md border border-[var(--admin-line)] p-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
            >
              <div class="min-w-0">
                <label
                  :for="`interval-${index}-weekday`"
                  class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.settings.weekday") }} {{ index + 1 }}</label
                >
                <select
                  :id="`interval-${index}-weekday`"
                  v-model="row.weekday"
                  required
                  class="admin-control"
                  :aria-invalid="!!intervalError(index, 'weekday')"
                  :aria-describedby="
                    intervalError(index, 'weekday')
                      ? `interval-${index}-weekday-error`
                      : undefined
                  "
                >
                  <option value="">{{ tr("ad.settings.chooseDay") }}</option>
                  <option v-for="day in 7" :key="day" :value="String(day)">
                    {{ tr(`ad.settings.day${day}`) }}
                  </option>
                </select>
                <p
                  v-if="intervalError(index, 'weekday')"
                  :id="`interval-${index}-weekday-error`"
                  class="settings-field-error"
                >
                  {{ intervalError(index, "weekday") }}
                </p>
              </div>
              <div
                v-for="field in ['start', 'end'] as const"
                :key="field"
                class="min-w-0"
              >
                <label
                  :for="`interval-${index}-${field}`"
                  class="mb-1 block text-sm font-semibold"
                  >{{ tr(`ad.settings.${field}`) }} {{ index + 1 }}</label
                >
                <input
                  :id="`interval-${index}-${field}`"
                  v-model="row[field]"
                  type="text"
                  inputmode="text"
                  maxlength="5"
                  required
                  pattern="[0-2][0-9]:[0-5][0-9]"
                  class="admin-control"
                  :aria-invalid="!!intervalError(index, field)"
                  :aria-describedby="
                    intervalError(index, field)
                      ? `interval-${index}-${field}-error`
                      : undefined
                  "
                />
                <p
                  v-if="intervalError(index, field)"
                  :id="`interval-${index}-${field}-error`"
                  class="settings-field-error"
                >
                  {{ intervalError(index, field) }}
                </p>
              </div>
              <button
                type="button"
                class="admin-button self-end"
                :data-remove-interval="index"
                :aria-label="`${tr('ad.settings.remove')} ${index + 1}`"
                @click="removeInterval(index)"
              >
                {{ tr("ad.settings.remove") }}
              </button>
            </div>
            <button
              type="button"
              data-add-interval
              class="admin-button"
              :disabled="values.review_intervals.length >= 168"
              @click="addInterval"
            >
              {{ tr("ad.settings.add") }}
            </button>
          </fieldset>
          <button
            type="submit"
            :disabled="processing"
            class="admin-button admin-button--primary disabled:cursor-wait disabled:opacity-60"
          >
            {{ tr(processing ? "ad.settings.saving" : "ad.settings.save") }}
          </button>
        </form>
      </div>
    </AdminLayout>
  </div>
</template>

<style scoped>
.settings-content {
  max-width: 64rem;
  padding-bottom: 2rem;
}
.settings-errors {
  padding: 1rem;
  border: 1px solid var(--admin-danger, #a12435);
  border-radius: 0.5rem;
  color: var(--admin-danger, #a12435);
}
.settings-field-error {
  margin-top: 0.375rem;
  font-size: 0.875rem;
  color: var(--admin-danger, #a12435);
}
</style>
