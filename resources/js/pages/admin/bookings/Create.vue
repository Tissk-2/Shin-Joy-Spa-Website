<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { computed, nextTick, ref, watch } from "vue";

import PackageConfigurator from "@/components/PackageConfigurator.vue";
import RoomAvailability from "@/components/booking/RoomAvailability.vue";
import DateTimePicker from "@/components/booking/DateTimePicker.vue";
import { useLanguage } from "@/composables/useLanguage";
import { useRoomAvailability } from "@/composables/useRoomAvailability";
import {
  availabilityQueryKey,
  jakartaAppointment,
} from "@/lib/roomAvailability";
import { treatmentFitsBusinessHours, timeSlots } from "@/lib/booking";
import {
  emptySelection,
  guestCoverage,
  normalizeSelection,
  selectionTotal,
  type ServiceSelection,
} from "@/lib/serviceSelection";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type { PackageData, TreatmentMode } from "@/types";

type StaffBookingSource = "walk_in" | "phone" | "whatsapp";

interface StaffBookingValues {
  source: StaffBookingSource;
  name: string;
  phone: string;
  email: string;
  service: string;
  mode: TreatmentMode | "";
  duration: number | null;
  extension: string;
  date: string;
  time: string;
  guests: number;
  notes: string;
  approve: boolean;
  operationalConfirmed: boolean;
}

const props = defineProps<{
  packages: PackageData[];
  submissionKey: string;
  availabilityEnabled?: boolean;
  sources: readonly StaffBookingSource[];
}>();

const { language, tr } = useLanguage();
// Frozen for this page load: the redirect back after a validation error re-renders
// with a fresh key, and a retry must reuse the original one to stay idempotent.
const submissionKey = props.submissionKey;
const initialPackage = props.packages[0];
const initialSelection = initialPackage
  ? normalizeSelection(initialPackage, emptySelection(initialPackage.slug))
  : emptySelection("");
const values = ref<StaffBookingValues>({
  source: "walk_in",
  name: "",
  phone: "",
  email: "",
  service: initialPackage?.slug ?? "",
  mode: initialSelection.mode,
  duration: initialSelection.duration,
  extension: initialSelection.extension,
  date: "",
  time: "",
  guests: initialPackage ? guestCoverage(initialPackage).min : 1,
  notes: "",
  approve: false,
  operationalConfirmed: false,
});
const errors = ref<Record<string, string>>({});
const processing = ref(false);

const selected = computed<ServiceSelection>({
  get: () => ({
    service: values.value.service,
    mode: values.value.mode,
    duration: values.value.duration,
    extension: values.value.extension,
  }),
  set: (value) => {
    values.value.service = value.service;
    values.value.mode = value.mode;
    values.value.duration = value.duration;
    values.value.extension = value.extension;
  },
});
const servicePackage = computed(() =>
  props.packages.find((item) => item.slug === selected.value.service),
);
const coverage = computed(() =>
  servicePackage.value
    ? guestCoverage(servicePackage.value)
    : { min: 1, max: 4 },
);
const guestOptions = computed(() => {
  const options: number[] = [];
  for (let guest = coverage.value.min; guest <= coverage.value.max; guest++)
    options.push(guest);
  return options;
});
watch(
  coverage,
  (range) => {
    values.value.guests = Math.min(
      range.max,
      Math.max(range.min, values.value.guests),
    );
  },
  { flush: "sync" },
);
watch(
  () => values.value.service,
  (slug) => {
    const service = props.packages.find((item) => item.slug === slug);
    if (!service) return;
    const next = normalizeSelection(service, emptySelection(service.slug));
    values.value.mode = next.mode;
    values.value.duration = next.duration;
    values.value.extension = next.extension;
    values.value.time = "";
  },
);
const total = computed(() =>
  servicePackage.value
    ? selectionTotal(servicePackage.value, selected.value, values.value.guests)
    : { duration: 0, price: 0 },
);

const availabilityEnabled = computed(() => props.availabilityEnabled === true);
const availabilityQuery = computed(() => {
  const candidate = selected.value;
  const service = servicePackage.value;
  if (
    !service?.variants.some(
      (variant) =>
        variant.mode === candidate.mode &&
        variant.duration === candidate.duration,
    ) ||
    (candidate.extension &&
      !service.extensions.some(
        (extension) =>
          extension.mode === candidate.mode &&
          extension.code === candidate.extension,
      ))
  )
    return null;
  return { ...candidate, date: values.value.date, guests: values.value.guests };
});
const availability = useRoomAvailability(
  availabilityEnabled,
  availabilityQuery,
  "/admin/bookings/availability",
);
const {
  slots: roomSlots,
  status: availabilityStatus,
  generatedAt: availabilityGeneratedAt,
} = availability;
// Compare the query by value: re-sent props after a server error rebuild the
// query object without changing the selection, and must not wipe the time.
watch(
  [() => availabilityQueryKey(availabilityQuery.value), availabilityEnabled],
  () => {
    if (availabilityEnabled.value) values.value.time = "";
  },
  { flush: "sync" },
);
function refreshAvailability(): void {
  values.value.time = "";
  void availability.refresh();
}
const availabilityAllowsTime = computed(
  () =>
    !availabilityEnabled.value ||
    (availability.status.value === "success" &&
      roomSlots.value.some(
        (slot) =>
          jakartaAppointment(slot.start_at).date === values.value.date &&
          jakartaAppointment(slot.start_at).time === values.value.time &&
          slot.rooms.some((room) => room.available),
      )),
);
const availableTimeSlots = computed(() =>
  availabilityEnabled.value
    ? availability.status.value === "success"
      ? roomSlots.value
          .filter((slot) => slot.rooms.some((room) => room.available))
          .map((slot) => jakartaAppointment(slot.start_at).time)
      : []
    : timeSlots.filter((slot) =>
        total.value.duration > 0
          ? treatmentFitsBusinessHours(slot, total.value.duration)
          : (servicePackage.value?.variants.some((variant) =>
              treatmentFitsBusinessHours(slot, variant.duration),
            ) ?? false),
      ),
);
watch(
  availableTimeSlots,
  (slots) => {
    if (values.value.time && !slots.includes(values.value.time))
      values.value.time = "";
  },
  { flush: "sync" },
);
// Why the time list is empty, when the reason is not simply that nothing is free.
const timesEmptyText = computed(() =>
  !servicePackage.value
    ? tr("dtp.pickService")
    : !availabilityEnabled.value || !values.value.date
      ? undefined
      : availabilityStatus.value === "loading"
        ? tr("dtp.checking")
        : availabilityStatus.value !== "success"
          ? tr("dtp.seeBelow")
          : undefined,
);

const summaryFields = [
  "submission_key",
  "expected_total_price_idr",
  "operationalConfirmed",
  "room",
  "currentStatus",
  "nextStatus",
] as const;
const summaryErrors = computed(() =>
  summaryFields
    .map((field) => errors.value[field])
    .filter((message): message is string => Boolean(message)),
);
const restartRequired = computed(() =>
  Boolean(errors.value.submission_key || errors.value.expected_total_price_idr),
);
const selectionErrors = computed(() =>
  (["mode", "duration", "extension"] as const)
    .map((field) => errors.value[field])
    .filter((message): message is string => Boolean(message)),
);

async function focusFirstError(): Promise<void> {
  await nextTick();
  (
    document.querySelector<HTMLElement>('form [aria-invalid="true"]') ??
    document.querySelector<HTMLElement>("[data-staff-errors]")
  )?.focus();
}

function formatPrice(amount: number): string {
  return `Rp ${new Intl.NumberFormat("id-ID").format(amount)}`;
}

function submit(): void {
  if (processing.value || restartRequired.value) return;
  if (!availabilityAllowsTime.value) {
    errors.value = { time: tr("ad.manual.chooseTime") };
    void focusFirstError();
    return;
  }
  processing.value = true;
  errors.value = {};
  const email = values.value.email.trim();
  router.post(
    "/admin/bookings",
    {
      source: values.value.source,
      name: values.value.name,
      phone: values.value.phone,
      email: email === "" ? null : email,
      service: values.value.service,
      mode: values.value.mode,
      duration: values.value.duration,
      extension: values.value.extension,
      date: values.value.date,
      time: values.value.time,
      guests: values.value.guests,
      notes: values.value.notes,
      submission_key: submissionKey,
      expected_total_price_idr: total.value.price,
      approve: values.value.approve,
      ...(values.value.approve && availabilityEnabled.value
        ? { operationalConfirmed: values.value.operationalConfirmed }
        : {}),
    },
    {
      preserveScroll: true,
      onError: (responseErrors: Record<string, string>) => {
        errors.value = responseErrors;
        void focusFirstError();
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
      :title="tr('ad.manual.title')"
      :subtitle="tr('ad.manual.subtitle')"
    >
      <div class="admin-content booking-entry-content">
        <Link
          href="/admin/bookings"
          class="w-fit text-sm font-semibold text-[var(--admin-plum)] underline"
          >{{ tr("ad.manual.back") }}</Link
        >
        <p v-if="!packages.length" class="admin-empty">
          {{ tr("ad.manual.noPackages") }}
        </p>
        <template v-else>
          <div
            v-if="summaryErrors.length"
            role="alert"
            tabindex="-1"
            data-staff-errors
            class="room-error-panel space-y-2"
          >
            <p v-for="message in summaryErrors" :key="message">
              {{ message }}
            </p>
            <a
              v-if="restartRequired"
              href="/admin/bookings/create"
              class="font-semibold underline"
              data-staff-restart
              >{{ tr("ad.manual.restart") }}</a
            >
          </div>
          <form
            class="space-y-5"
            :aria-busy="processing"
            @submit.prevent="submit"
          >
            <div class="admin-filter-panel grid gap-5 sm:grid-cols-2">
              <div class="min-w-0">
                <label
                  for="staff-source"
                  class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.manual.source") }}</label
                >
                <select
                  id="staff-source"
                  v-model="values.source"
                  class="admin-control"
                  :aria-invalid="!!errors.source"
                  :aria-describedby="
                    errors.source ? 'staff-source-error' : undefined
                  "
                >
                  <option
                    v-for="source in sources"
                    :key="source"
                    :value="source"
                  >
                    {{ tr(`ad.source.${source}`) }}
                  </option>
                </select>
                <p
                  v-if="errors.source"
                  id="staff-source-error"
                  class="room-field-error"
                >
                  {{ errors.source }}
                </p>
              </div>
              <div class="min-w-0">
                <label for="staff-name" class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.manual.name") }}
                  <span aria-hidden="true">*</span></label
                >
                <input
                  id="staff-name"
                  v-model="values.name"
                  required
                  maxlength="120"
                  class="admin-control"
                  :aria-invalid="!!errors.name"
                  :aria-describedby="
                    errors.name ? 'staff-name-error' : undefined
                  "
                />
                <p
                  v-if="errors.name"
                  id="staff-name-error"
                  class="room-field-error"
                >
                  {{ errors.name }}
                </p>
              </div>
              <div class="min-w-0">
                <label
                  for="staff-phone"
                  class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.manual.phone") }}</label
                >
                <input
                  id="staff-phone"
                  v-model="values.phone"
                  type="tel"
                  maxlength="32"
                  class="admin-control"
                  :aria-invalid="!!errors.phone"
                  :aria-describedby="
                    errors.phone
                      ? 'staff-contact-help staff-phone-error'
                      : 'staff-contact-help'
                  "
                />
                <p
                  v-if="errors.phone"
                  id="staff-phone-error"
                  class="room-field-error"
                >
                  {{ errors.phone }}
                </p>
              </div>
              <div class="min-w-0">
                <label
                  for="staff-email"
                  class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.manual.email") }}</label
                >
                <input
                  id="staff-email"
                  v-model="values.email"
                  type="email"
                  maxlength="254"
                  class="admin-control"
                  :aria-invalid="!!errors.email"
                  :aria-describedby="
                    errors.email
                      ? 'staff-contact-help staff-email-error'
                      : 'staff-contact-help'
                  "
                />
                <p
                  id="staff-contact-help"
                  class="mt-1 text-xs text-[var(--admin-muted)]"
                >
                  {{ tr("ad.manual.contactHelp") }}
                </p>
                <p
                  v-if="errors.email"
                  id="staff-email-error"
                  class="room-field-error"
                >
                  {{ errors.email }}
                </p>
              </div>
              <div class="min-w-0 sm:col-span-2">
                <label
                  for="staff-service"
                  class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.manual.package") }}</label
                >
                <select
                  id="staff-service"
                  v-model="values.service"
                  class="admin-control"
                  :aria-invalid="!!errors.service"
                  :aria-describedby="
                    errors.service ? 'staff-service-error' : undefined
                  "
                >
                  <option
                    v-for="service in packages"
                    :key="service.slug"
                    :value="service.slug"
                  >
                    {{ language === "id" ? service.nameId : service.nameEn }}
                  </option>
                </select>
                <p
                  v-if="errors.service"
                  id="staff-service-error"
                  class="room-field-error"
                >
                  {{ errors.service }}
                </p>
              </div>
              <div class="min-w-0 sm:col-span-2">
                <PackageConfigurator
                  v-if="servicePackage"
                  v-model="selected"
                  :service-package="servicePackage"
                  :guests="values.guests"
                  :show-booking-link="false"
                />
                <p
                  v-if="selectionErrors.length"
                  id="staff-selection-error"
                  class="room-field-error"
                >
                  {{ selectionErrors.join(" ") }}
                </p>
              </div>
              <div class="min-w-0">
                <label
                  for="staff-guests"
                  class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.manual.guests") }}</label
                >
                <select
                  id="staff-guests"
                  v-model="values.guests"
                  class="admin-control"
                  :aria-invalid="!!errors.guests"
                  :aria-describedby="
                    errors.guests ? 'staff-guests-error' : undefined
                  "
                >
                  <option
                    v-for="guest in guestOptions"
                    :key="guest"
                    :value="guest"
                  >
                    {{ guest }}
                  </option>
                </select>
                <p
                  v-if="errors.guests"
                  id="staff-guests-error"
                  class="room-field-error"
                >
                  {{ errors.guests }}
                </p>
              </div>
              <div class="min-w-0 sm:col-span-2">
                <DateTimePicker
                  v-model:date="values.date"
                  v-model:time="values.time"
                  tone="admin"
                  :times="availableTimeSlots"
                  date-id="staff-date"
                  time-id="staff-time"
                  :date-label="tr('ad.manual.date')"
                  :time-label="tr('ad.manual.time')"
                  :date-invalid="!!errors.date"
                  :time-invalid="!!errors.time"
                  :date-described-by="
                    errors.date ? 'staff-date-error' : undefined
                  "
                  :time-described-by="
                    errors.time ? 'staff-time-error' : undefined
                  "
                  :empty-text="timesEmptyText"
                />
                <p
                  v-if="errors.date"
                  id="staff-date-error"
                  class="room-field-error"
                >
                  {{ errors.date }}
                </p>
                <p
                  v-if="errors.time"
                  id="staff-time-error"
                  class="room-field-error"
                >
                  {{ errors.time }}
                </p>
              </div>
              <div class="min-w-0 sm:col-span-2">
                <RoomAvailability
                  v-if="availabilityEnabled"
                  :language="language"
                  :status="availabilityStatus"
                  :slots="roomSlots"
                  :selected-time="values.time"
                  :generated-at="availabilityGeneratedAt"
                  @refresh="refreshAvailability"
                />
              </div>
              <div class="min-w-0 sm:col-span-2">
                <label
                  for="staff-notes"
                  class="mb-1 block text-sm font-semibold"
                  >{{ tr("ad.manual.notes") }}</label
                >
                <textarea
                  id="staff-notes"
                  v-model="values.notes"
                  maxlength="1000"
                  class="admin-control"
                  :aria-invalid="!!errors.notes"
                  :aria-describedby="
                    errors.notes ? 'staff-notes-error' : undefined
                  "
                ></textarea>
                <p
                  v-if="errors.notes"
                  id="staff-notes-error"
                  class="room-field-error"
                >
                  {{ errors.notes }}
                </p>
              </div>
            </div>
            <p class="admin-record-secondary">{{ tr("ad.manual.total") }}</p>
            <p data-staff-total class="admin-price">
              {{ formatPrice(total.price) }}
            </p>
            <label
              class="flex w-fit items-center gap-3 text-sm font-semibold"
              for="staff-approve"
              ><input
                id="staff-approve"
                v-model="values.approve"
                type="checkbox"
                class="h-4 w-4 rounded"
              />{{ tr("ad.manual.approve") }}</label
            >
            <p
              v-if="errors.approve"
              id="staff-approve-error"
              class="room-field-error"
            >
              {{ errors.approve }}
            </p>
            <label
              v-if="values.approve && availabilityEnabled"
              class="flex w-fit items-start gap-3 text-sm font-semibold"
              for="staff-operational"
              ><input
                id="staff-operational"
                v-model="values.operationalConfirmed"
                type="checkbox"
                class="mt-1 h-4 w-4 shrink-0 rounded"
              />{{ tr("ad.confirm.operational") }}</label
            >
            <div class="flex flex-wrap items-center gap-4 pb-6">
              <button
                type="submit"
                :disabled="processing"
                class="admin-button disabled:cursor-wait disabled:opacity-60"
              >
                {{
                  tr(
                    values.approve ? "ad.manual.saveApprove" : "ad.manual.save",
                  )
                }}
              </button>
            </div>
          </form>
        </template>
      </div>
    </AdminLayout>
  </div>
</template>

<style scoped>
.booking-entry-content {
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
