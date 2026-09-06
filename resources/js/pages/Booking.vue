<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import PackageConfigurator from "@/components/PackageConfigurator.vue";
import { useLanguage } from "@/composables/useLanguage";
import {
  buildBookingSubmissionPayload,
  timeSlots,
  todayIso,
  validateBooking,
  type BookingErrors,
  type BookingSubmissionInput,
} from "@/lib/booking";
import {
  emptySelection,
  selectionTotal,
  type ServiceSelection,
} from "@/lib/serviceSelection";
import MainLayout from "@/layouts/MainLayout.vue";
import type { AppPageProps, PackageData } from "@/types";

interface Details {
  name: string;
  phone: string;
  date: string;
  time: string;
  guests: number;
  notes: string;
}
type BookingForm = Details & ServiceSelection & { submission_key: string };
type SubmittedField = keyof Details | keyof ServiceSelection | "submission_key";
const errorIds: Record<SubmittedField, string> = {
  name: "f-name-error",
  phone: "f-phone-error",
  date: "f-date-error",
  time: "f-time-error",
  guests: "f-guests-error",
  notes: "f-notes-error",
  service: "booking-service-error",
  mode: "booking-selection-error",
  duration: "booking-selection-error",
  extension: "booking-selection-error",
  submission_key: "booking-submit-error",
};
const selectionErrorFields = ["mode", "duration", "extension"] as const;

const props = defineProps<{
  packages: PackageData[];
  selection: ServiceSelection;
  submissionKey: string;
}>();
const page = usePage<AppPageProps>();
const { language, tr } = useLanguage();
const user = page.props.auth.user;
const bookingForm = useForm<BookingForm>({
  name: user?.name ?? "",
  phone: user?.phone ?? "",
  service: props.selection.service,
  mode: props.selection.mode,
  duration: props.selection.duration,
  extension: props.selection.extension,
  date: "",
  time: "",
  guests: 1,
  notes: "",
  submission_key: props.submissionKey,
});
const localErrors = ref<BookingErrors>({});
const reviewing = ref(false);
const hasServerErrors = computed(
  () => Object.keys(bookingForm.errors).length > 0,
);
const selected = computed<ServiceSelection>({
  get: () => ({
    service: bookingForm.service,
    mode: bookingForm.mode,
    duration: bookingForm.duration,
    extension: bookingForm.extension,
  }),
  set: (value) => {
    bookingForm.service = value.service;
    bookingForm.mode = value.mode;
    bookingForm.duration = value.duration;
    bookingForm.extension = value.extension;
    localErrors.value = {};
  },
});
const servicePackage = computed(() =>
  props.packages.find((item) => item.slug === selected.value.service),
);
const total = computed(() =>
  servicePackage.value
    ? selectionTotal(servicePackage.value, selected.value)
    : { duration: 0, price: 0 },
);
const packageName = computed(() =>
  servicePackage.value
    ? language.value === "id"
      ? servicePackage.value.nameId
      : servicePackage.value.nameEn
    : tr("b.sumempty"),
);
const modeLabel = computed(() =>
  selected.value.mode === "regular"
    ? language.value === "id"
      ? "Reguler"
      : "Regular"
    : selected.value.mode === "double_therapist"
      ? language.value === "id"
        ? "Dua terapis"
        : "Double therapist"
      : tr("b.sumempty"),
);
const labels = computed(() =>
  language.value === "id"
    ? {
        choose: "Pilih paket",
        review: "Tinjau reservasi Anda",
        action: "Tinjau reservasi",
        noReservation: "Reservasi belum dikirim",
        edit: "Ubah detail",
        confirm: "Konfirmasi reservasi",
        pending: "Mengirim reservasi",
        retry: "Periksa kembali detail reservasi Anda.",
        email: "Email akun",
        mode: "Jenis perawatan",
        base: "Durasi dasar",
        extension: "Tambahan",
        noExtension: "Tanpa tambahan",
      }
    : {
        choose: "Choose a package",
        review: "Review your booking",
        action: "Review booking",
        noReservation: "No reservation has been submitted yet",
        edit: "Edit details",
        confirm: "Confirm booking",
        pending: "Submitting booking",
        retry: "Please review your booking details.",
        email: "Account email",
        mode: "Treatment type",
        base: "Base duration",
        extension: "Extension",
        noExtension: "No extension",
      },
);
const extensionLabel = computed(() => {
  const extension = servicePackage.value?.extensions.find(
    (item) => item.code === selected.value.extension,
  );
  return extension
    ? `${extension.duration} ${tr("d.min")} · Rp ${extension.price.toLocaleString("id-ID")}`
    : labels.value.noExtension;
});
function choosePackage(slug: string): void {
  selected.value = emptySelection(slug);
  localErrors.value = {};
}
function translateError(message?: string): string {
  if (!message) return "";
  return message.startsWith("b.") ? tr(message) : message;
}
function fieldError(field: SubmittedField): string {
  const serverError = bookingForm.errors[field];
  if (serverError) return translateError(serverError);
  if (field === "submission_key") return "";
  return translateError(localErrors.value[field as keyof BookingErrors]);
}
const selectionErrors = computed(() => {
  const messages: string[] = [];
  for (const field of selectionErrorFields) {
    const message = fieldError(field);
    if (message && !messages.includes(message)) {
      messages.push(message);
    }
  }
  return messages;
});
function describedBy(
  field: SubmittedField,
  extraIds: string[] = [],
): string | undefined {
  const ids = [...extraIds];
  if (fieldError(field)) {
    ids.push(errorIds[field]);
  }
  return ids.length ? ids.join(" ") : undefined;
}
function submit(): void {
  if (bookingForm.processing) return;

  localErrors.value = validateBooking(
    {
      name: bookingForm.name,
      phone: bookingForm.phone,
      service: bookingForm.service,
      mode: bookingForm.mode,
      duration: bookingForm.duration,
      extension: bookingForm.extension,
      date: bookingForm.date,
      time: bookingForm.time,
      guests: bookingForm.guests,
      notes: bookingForm.notes,
    },
    props.packages,
  );
  if (!Object.keys(localErrors.value).length) reviewing.value = true;
}
function edit(): void {
  if (bookingForm.processing) return;
  reviewing.value = false;
}
function confirm(): void {
  if (bookingForm.processing) return;

  const submissionInput: BookingSubmissionInput = {
    name: bookingForm.name,
    phone: bookingForm.phone,
    service: bookingForm.service,
    mode: bookingForm.mode,
    duration: bookingForm.duration,
    extension: bookingForm.extension,
    date: bookingForm.date,
    time: bookingForm.time,
    guests: bookingForm.guests,
    notes: bookingForm.notes,
    submission_key: bookingForm.submission_key,
  };
  const payload = buildBookingSubmissionPayload(submissionInput);
  bookingForm
    .transform(() => payload)
    .post("/bookings", {
      preserveScroll: true,
      onError: () => {
        reviewing.value = false;
      },
    });
}
</script>

<template>
  <Head :title="`${tr('b.title')} · Shin Joy Spa Malang`" />
  <MainLayout>
    <section class="page-hero">
      <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <Link class="ulink" href="/">{{ tr("nav.home") }}</Link
          ><span aria-hidden="true">›</span><span>{{ tr("nav.book") }}</span>
        </nav>
        <h1>{{ tr("b.title") }}</h1>
        <p class="lead">{{ tr("menu.bookingReview") }}</p>
      </div>
    </section>
    <section class="section">
      <div class="container">
        <section
          v-if="reviewing"
          class="form-success show"
          role="status"
          aria-live="polite"
          aria-atomic="true"
        >
          <h2 class="h3">{{ labels.review }}</h2>
          <p class="muted">
            {{ bookingForm.processing ? labels.pending : labels.noReservation }}
          </p>
          <div class="booking-recap">
            <div class="summary-row">
              <span>{{ tr("b.sumservice") }}</span
              ><span>{{ packageName }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.mode }}</span
              ><span>{{ modeLabel }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.base }}</span
              ><span>{{ selected.duration }} {{ tr("d.min") }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.extension }}</span
              ><span>{{ extensionLabel }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumduration") }}</span
              ><span>{{ total.duration }} {{ tr("d.min") }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumdate") }}</span
              ><span>{{ bookingForm.date }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumtime") }}</span
              ><span>{{ bookingForm.time }} WIB</span>
            </div>
            <div class="summary-total">
              <span>{{ tr("b.total") }}</span
              ><span class="amt"
                >Rp {{ total.price.toLocaleString("id-ID") }}</span
              >
            </div>
          </div>
          <p
            v-if="fieldError('submission_key')"
            id="booking-submit-error"
            class="err"
            role="alert"
            aria-live="assertive"
          >
            {{ fieldError("submission_key") }}
          </p>
          <button
            type="button"
            class="btn btn--primary"
            data-booking-edit
            :disabled="bookingForm.processing"
            @click="edit"
          >
            {{ labels.edit }}
          </button>
          <button
            type="button"
            class="btn btn--primary"
            data-booking-confirm
            :disabled="bookingForm.processing"
            @click="confirm"
          >
            {{ bookingForm.processing ? labels.pending : labels.confirm }}
          </button>
        </section>
        <div v-else class="booking-layout">
          <form novalidate @submit.prevent="submit">
            <div class="form-grid">
              <p
                v-if="hasServerErrors"
                id="booking-submit-error"
                class="err full"
                role="alert"
                aria-live="assertive"
              >
                {{ labels.retry }} {{ fieldError("submission_key") }}
              </p>
              <div class="field full">
                <label for="f-name">{{ tr("b.fname") }}</label
                ><input
                  id="f-name"
                  v-model="bookingForm.name"
                  type="text"
                  autocomplete="name"
                  :aria-invalid="fieldError('name') ? 'true' : undefined"
                  :aria-describedby="describedBy('name')"
                /><span id="f-name-error" class="err">{{
                  fieldError("name")
                }}</span>
              </div>
              <div class="field">
                <label for="f-phone">{{ tr("b.fphone") }}</label
                ><input
                  id="f-phone"
                  v-model="bookingForm.phone"
                  type="tel"
                  autocomplete="tel"
                  :aria-invalid="fieldError('phone') ? 'true' : undefined"
                  :aria-describedby="describedBy('phone')"
                /><span id="f-phone-error" class="err">{{
                  fieldError("phone")
                }}</span>
              </div>
              <div class="field">
                <label for="f-email">{{ labels.email }}</label
                ><input
                  id="f-email"
                  :value="user?.email ?? ''"
                  type="email"
                  readonly
                />
              </div>
              <fieldset
                class="field full"
                data-booking-service-group
                :aria-invalid="fieldError('service') ? 'true' : undefined"
                :aria-describedby="describedBy('service')"
              >
                <legend>{{ labels.choose }}</legend>
                <div class="package-choice-buttons">
                  <button
                    v-for="item in props.packages"
                    :key="item.slug"
                    type="button"
                    :aria-pressed="selected.service === item.slug"
                    @click="choosePackage(item.slug)"
                  >
                    {{ language === "id" ? item.nameId : item.nameEn }}
                  </button>
                </div>
                <span id="booking-service-error" class="err">{{
                  fieldError("service")
                }}</span>
              </fieldset>
              <div
                v-if="servicePackage"
                class="full"
                role="group"
                :aria-invalid="selectionErrors.length ? 'true' : undefined"
                :aria-describedby="
                  selectionErrors.length ? 'booking-selection-error' : undefined
                "
              >
                <PackageConfigurator
                  v-model="selected"
                  :service-package="servicePackage"
                  :show-booking-link="false"
                />
                <ul
                  v-if="selectionErrors.length"
                  id="booking-selection-error"
                  class="err"
                  role="list"
                >
                  <li v-for="message in selectionErrors" :key="message">
                    {{ message }}
                  </li>
                </ul>
              </div>
              <div class="field">
                <label for="f-date">{{ tr("b.fdate") }}</label
                ><input
                  id="f-date"
                  v-model="bookingForm.date"
                  type="date"
                  :min="todayIso()"
                  :aria-invalid="fieldError('date') ? 'true' : undefined"
                  :aria-describedby="describedBy('date')"
                /><span id="f-date-error" class="err">{{
                  fieldError("date")
                }}</span>
              </div>
              <div class="field">
                <label for="f-time">{{ tr("b.ftime") }}</label
                ><select
                  id="f-time"
                  v-model="bookingForm.time"
                  :aria-invalid="fieldError('time') ? 'true' : undefined"
                  :aria-describedby="describedBy('time')"
                >
                  <option value="">{{ tr("b.ftime") }}</option>
                  <option v-for="slot in timeSlots" :key="slot" :value="slot">
                    {{ slot }}
                  </option></select
                ><span id="f-time-error" class="err">{{
                  fieldError("time")
                }}</span>
              </div>
              <div class="field full">
                <label for="f-guests">{{ tr("b.fguests") }}</label
                ><select
                  id="f-guests"
                  v-model.number="bookingForm.guests"
                  :aria-invalid="fieldError('guests') ? 'true' : undefined"
                  :aria-describedby="describedBy('guests')"
                >
                  <option v-for="guest in 4" :key="guest" :value="guest">
                    {{ tr(`b.guest${guest}`) }}
                  </option>
                </select>
                <span id="f-guests-error" class="err">{{
                  fieldError("guests")
                }}</span>
              </div>
              <div class="field full">
                <label for="f-notes">{{ tr("b.fnotes") }}</label
                ><textarea
                  id="f-notes"
                  v-model="bookingForm.notes"
                  :placeholder="tr('b.fnotesph')"
                  :aria-invalid="fieldError('notes') ? 'true' : undefined"
                  :aria-describedby="describedBy('notes')"
                ></textarea>
                <span id="f-notes-error" class="err">{{
                  fieldError("notes")
                }}</span>
              </div>
              <div class="full">
                <button
                  type="submit"
                  class="btn btn--primary btn--lg btn--block"
                  :disabled="bookingForm.processing"
                >
                  {{ bookingForm.processing ? labels.pending : labels.action }}
                </button>
              </div>
            </div>
          </form>
          <aside class="summary-card" :aria-label="tr('b.summary')">
            <h3>{{ tr("b.summary") }}</h3>
            <div class="summary-row">
              <span>{{ tr("b.sumservice") }}</span
              ><span>{{ packageName }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.mode }}</span
              ><span>{{ modeLabel }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.base }}</span
              ><span>{{
                selected.duration ? `${selected.duration} ${tr("d.min")}` : "—"
              }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.extension }}</span
              ><span>{{ extensionLabel }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumduration") }}</span
              ><span>{{
                total.duration ? `${total.duration} ${tr("d.min")}` : "—"
              }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumdate") }}</span
              ><span>{{ bookingForm.date || tr("b.sumempty") }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumtime") }}</span
              ><span>{{ bookingForm.time || tr("b.sumempty") }}</span>
            </div>
            <div class="summary-total">
              <span>{{ tr("b.total") }}</span
              ><span class="amt">{{
                total.duration
                  ? `Rp ${total.price.toLocaleString("id-ID")}`
                  : "—"
              }}</span>
            </div>
          </aside>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
