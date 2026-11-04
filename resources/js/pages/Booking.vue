<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { ArrowRight, ExternalLink, ShieldCheck } from "lucide-vue-next";
import { computed, nextTick, ref, watch } from "vue";
import PackageConfigurator from "@/components/PackageConfigurator.vue";
import RoomAvailability from "@/components/booking/RoomAvailability.vue";
import { useContactInformation } from "@/composables/useContactInformation";
import { useLanguage } from "@/composables/useLanguage";
import { useRoomAvailability } from "@/composables/useRoomAvailability";
import { jakartaAppointment } from "@/lib/roomAvailability";
import {
  buildBookingSubmissionPayload,
  treatmentFitsBusinessHours,
  timeSlots,
  todayIso,
  validateBooking,
  type BookingErrors,
  type BookingSubmissionInput,
} from "@/lib/booking";
import {
  guestCoverage,
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
type BookingForm = Details &
  ServiceSelection & {
    submission_key: string;
    expected_total_price_idr: number;
  };
type SubmittedField = keyof BookingForm;
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
  expected_total_price_idr: "booking-submit-error",
};
const selectionErrorFields = ["mode", "duration", "extension"] as const;

const props = defineProps<{
  packages: PackageData[];
  selection: ServiceSelection;
  submissionKey: string;
  availabilityEnabled?: boolean;
}>();
const page = usePage<AppPageProps>();
const { language, tr } = useLanguage();
const contactInformation = useContactInformation();
const user = page.props.auth.user;
const initialPackage = props.packages.find(
  (item) => item.slug === props.selection.service,
);
const initialCoverage = initialPackage
  ? guestCoverage(initialPackage)
  : { min: 1, max: 4 };
const bookingForm = useForm<BookingForm>({
  name: user?.name ?? "",
  phone: user?.phone ?? "",
  service: props.selection.service,
  mode: props.selection.mode,
  duration: props.selection.duration,
  extension: props.selection.extension,
  date: "",
  time: "",
  guests: initialCoverage.min,
  notes: "",
  submission_key: props.submissionKey,
  expected_total_price_idr: 0,
});
const localErrors = ref<BookingErrors>({});
const reviewing = ref(false);
const reviewHeading = ref<HTMLHeadingElement | null>(null);
const nameInput = ref<HTMLInputElement | null>(null);
const recoveryRegion = ref<HTMLDivElement | null>(null);
const serverErrorSummary = ref<HTMLParagraphElement | null>(null);
const serverErrorTargets: Partial<Record<SubmittedField, string>> = {
  name: "#f-name",
  phone: "#f-phone",
  date: "#f-date",
  time: "#f-time",
  guests: "#f-guests",
  notes: "#f-notes",
  service: "[data-booking-service-group]",
  mode: "[data-booking-selection-group]",
  duration: "[data-booking-selection-group]",
  extension: "[data-booking-selection-group]",
};

async function focusAfterRender(
  target: () => HTMLElement | null,
): Promise<void> {
  await nextTick();
  target()?.focus();
}

async function focusSubmissionError(
  errors: Record<string, string>,
): Promise<void> {
  await nextTick();
  if (errors.submission_key || errors.expected_total_price_idr) {
    recoveryRegion.value?.focus();
    return;
  }

  const invalidField = Object.keys(errors).find(
    (field): field is SubmittedField => field in serverErrorTargets,
  );
  const selector = invalidField ? serverErrorTargets[invalidField] : undefined;
  const target = selector
    ? document.querySelector<HTMLElement>(selector)
    : serverErrorSummary.value;
  (target ?? serverErrorSummary.value)?.focus();
}
const hasServerErrors = computed(
  () => Object.keys(bookingForm.errors).length > 0,
);
const recoveryRequired = computed(() =>
  Boolean(
    bookingForm.errors.submission_key ||
    bookingForm.errors.expected_total_price_idr,
  ),
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
    bookingForm.guests = Math.min(
      range.max,
      Math.max(range.min, bookingForm.guests),
    );
  },
  { flush: "sync" },
);
const total = computed(() =>
  servicePackage.value
    ? selectionTotal(servicePackage.value, selected.value, bookingForm.guests)
    : { duration: 0, price: 0 },
);
const pricingVerified = computed(
  () => servicePackage.value?.pricingVerified === true,
);
const pricingBasisLabel = computed(() =>
  tr("b.totalGuests", { count: bookingForm.guests }),
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
  return { ...candidate, date: bookingForm.date, guests: bookingForm.guests };
});
const availability = useRoomAvailability(
  availabilityEnabled,
  availabilityQuery,
);
const {
  slots: roomSlots,
  status: availabilityStatus,
  generatedAt: availabilityGeneratedAt,
} = availability;
// Clear immediately, including before Vue renders a changed selection or refresh.
watch(
  [availabilityQuery, availabilityEnabled],
  () => {
    if (availabilityEnabled.value) bookingForm.time = "";
  },
  { flush: "sync" },
);
function refreshAvailability(): void {
  bookingForm.time = "";
  void availability.refresh();
}
const availabilityAllowsTime = computed(
  () =>
    !availabilityEnabled.value ||
    (availability.status.value === "success" &&
      roomSlots.value.some(
        (slot) =>
          jakartaAppointment(slot.start_at).date === bookingForm.date &&
          jakartaAppointment(slot.start_at).time === bookingForm.time &&
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
    if (bookingForm.time && !slots.includes(bookingForm.time))
      bookingForm.time = "";
  },
  { flush: "sync" },
);
const restartHref = computed(() => {
  const params = new URLSearchParams({
    service: bookingForm.service,
    mode: bookingForm.mode,
    duration: String(bookingForm.duration ?? ""),
    extension: bookingForm.extension,
  });
  return `/booking?${params.toString()}`;
});
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
        pickPrompt: "Pilih paket terlebih dahulu dari menu perawatan.",
        pickLink: "Pilih paket",
        review: "Tinjau reservasi Anda",
        action: "Tinjau reservasi",
        noReservation: "Reservasi belum dikirim",
        edit: "Ubah detail",
        confirm: "Konfirmasi reservasi",
        pending: "Mengirim reservasi",
        retry: "Periksa kembali detail reservasi Anda.",
        restart: "Mulai reservasi baru",
        existing: "Lihat reservasi saya",
        refreshPrice: "Muat harga terbaru",
        mode: "Jenis perawatan",
        base: "Durasi dasar",
        extension: "Tambahan",
        noExtension: "Tanpa tambahan",
        notProvided: "Tidak dicantumkan",
        noNotes: "Belum ada catatan",
        estimateScope:
          "Estimasi ini mencakup perawatan yang dipilih dan tambahan apa pun. Jumlah tamu tidak mengalikan jumlah tersebut; total untuk semua tamu belum dikonfirmasi.",
        s1: "Paket & Perawatan",
        s1sub: "Konfigurasi jenis treatment, durasi, dan tambahan sesi Anda.",
        s2: "Jadwal Kunjungan",
        s2sub: "Pilih hari, waktu mulai, dan jumlah tamu.",
        s3: "Data Tamu & Catatan",
        s3sub: "Kami gunakan nomor ini untuk mengonfirmasi reservasi.",
        step1: "Layanan",
        step2: "Jadwal & Tamu",
        step3: "Selesai",
        help: "Perlu bantuan reservasi?",
        concierge: "WhatsApp Concierge",
      }
    : {
        pickPrompt: "Choose a package from the treatment menu first.",
        pickLink: "Choose a package",
        review: "Review your booking",
        action: "Review booking",
        noReservation: "No reservation has been submitted yet",
        edit: "Edit details",
        confirm: "Confirm booking",
        pending: "Submitting booking",
        retry: "Please review your booking details.",
        restart: "Start a new booking",
        existing: "View my bookings",
        refreshPrice: "Load current prices",
        mode: "Treatment type",
        base: "Base duration",
        extension: "Extension",
        noExtension: "No extension",
        notProvided: "Not provided",
        noNotes: "No notes provided",
        estimateScope:
          "This estimate covers the selected treatment and any extension. Guest count does not multiply it; the total for all guests has not been confirmed.",
        s1: "Package & Treatment",
        s1sub:
          "Configure the treatment type, duration, and add-ons for your session.",
        s2: "Visit Schedule",
        s2sub: "Choose the day, start time, and number of guests.",
        s3: "Guest Details & Notes",
        s3sub: "We will use this number to confirm your reservation.",
        step1: "Service",
        step2: "Schedule & Guests",
        step3: "Done",
        help: "Need booking help?",
        concierge: "WhatsApp Concierge",
      },
);
const scopeCopy = computed(() =>
  pricingVerified.value
    ? tr(
        servicePackage.value?.pricingBasis === "per_person"
          ? "b.scopePerPerson"
          : "b.scopePerBooking",
      )
    : labels.value.estimateScope,
);
const extensionLabel = computed(() => {
  const extension = servicePackage.value?.extensions.find(
    (item) => item.code === selected.value.extension,
  );
  return extension
    ? `${extension.duration} ${tr("d.min")} · Rp ${extension.price.toLocaleString("id-ID")}`
    : labels.value.noExtension;
});
const reviewPhone = computed(
  () => bookingForm.phone.trim() || labels.value.notProvided,
);
const reviewNotes = computed(
  () => (bookingForm.notes.trim() && bookingForm.notes) || labels.value.noNotes,
);
function translateError(message?: string): string {
  if (!message) return "";
  return message.startsWith("b.") ? tr(message) : message;
}
function fieldError(field: SubmittedField): string {
  const serverError = bookingForm.errors[field];
  if (serverError) return translateError(serverError);
  if (field === "submission_key" || field === "expected_total_price_idr")
    return "";
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
  if (bookingForm.processing || recoveryRequired.value) return;
  if (!availabilityAllowsTime.value) {
    void focusAfterRender(() => document.querySelector<HTMLElement>("#f-time"));
    return;
  }

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
  if (!Object.keys(localErrors.value).length) {
    bookingForm.expected_total_price_idr = total.value.price;
    reviewing.value = true;
    void focusAfterRender(() => reviewHeading.value);
    return;
  }

  const invalidField = Object.keys(localErrors.value).find(
    (field): field is keyof BookingErrors => field in localErrors.value,
  );
  const selector = invalidField
    ? serverErrorTargets[invalidField as SubmittedField]
    : undefined;
  void focusAfterRender(() =>
    selector ? document.querySelector<HTMLElement>(selector) : null,
  );
}
function edit(): void {
  if (bookingForm.processing) return;
  reviewing.value = false;
  void focusAfterRender(() => nameInput.value);
}
function confirm(): void {
  if (
    bookingForm.processing ||
    recoveryRequired.value ||
    !availabilityAllowsTime.value
  )
    return;

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
    expected_total_price_idr: bookingForm.expected_total_price_idr,
  };
  const payload = buildBookingSubmissionPayload(submissionInput);
  bookingForm
    .transform(() => payload)
    .post("/bookings", {
      preserveScroll: true,
      onError: (errors) => {
        reviewing.value = false;
        void focusSubmissionError(errors);
      },
    });
}
</script>

<template>
  <Head :title="`${tr('b.title')} · Shin Joy Spa Malang`" />
  <MainLayout>
    <div class="bk-steps">
      <div class="container bk-steps__inner">
        <h1 class="sr-only">{{ tr("b.title") }}</h1>
        <nav class="bk-steps__crumb" aria-label="Breadcrumb">
          <Link class="ulink" href="/">{{ tr("nav.home") }}</Link>
          <span aria-hidden="true">/</span>
          <span>{{ tr("nav.book") }}</span>
        </nav>
        <ol class="bk-steps__flow">
          <li class="bk-steps__item is-done">
            <span class="bk-steps__dot" aria-hidden="true">✓</span>
            <span class="bk-steps__label">{{ labels.step1 }}</span>
          </li>
          <li class="bk-steps__line" aria-hidden="true"></li>
          <li class="bk-steps__item is-active" aria-current="step">
            <span class="bk-steps__dot">2</span>
            <span class="bk-steps__label">{{ labels.step2 }}</span>
          </li>
          <li class="bk-steps__line" aria-hidden="true"></li>
          <li class="bk-steps__item">
            <span class="bk-steps__dot">3</span>
            <span class="bk-steps__label">{{ labels.step3 }}</span>
          </li>
        </ol>
      </div>
    </div>

    <section class="section bk-main">
      <div class="container">
        <section
          v-if="reviewing"
          class="form-success show"
          role="status"
          aria-live="polite"
          aria-atomic="true"
        >
          <h2 ref="reviewHeading" class="h3" tabindex="-1">
            {{ labels.review }}
          </h2>
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
            <div class="summary-row">
              <span>{{ tr("b.fguests") }}</span
              ><span>{{ tr(`b.guest${bookingForm.guests}`) }}</span>
            </div>
            <div class="summary-row summary-row--name">
              <span>{{ tr("b.fname") }}</span
              ><span>{{ bookingForm.name }}</span>
            </div>
            <div class="summary-row summary-row--phone">
              <span>{{ tr("b.fphone") }}</span
              ><span>{{ reviewPhone }}</span>
            </div>
            <div class="summary-row summary-row--notes">
              <span>{{ tr("b.fnotes") }}</span
              ><span>{{ reviewNotes }}</span>
            </div>
            <div class="summary-total">
              <span
                >{{ tr("b.total")
                }}<template v-if="pricingVerified">
                  · {{ pricingBasisLabel }}</template
                ></span
              ><span class="amt"
                >Rp {{ total.price.toLocaleString("id-ID") }}</span
              >
            </div>
            <p class="estimate-scope">{{ scopeCopy }}</p>
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
          <div class="bk-review__actions">
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
              :disabled="bookingForm.processing || !availabilityAllowsTime"
              @click="confirm"
            >
              {{ bookingForm.processing ? labels.pending : labels.confirm }}
              <ArrowRight
                v-if="!bookingForm.processing"
                class="bk-submit__icon"
                aria-hidden="true"
              />
            </button>
          </div>
          <RoomAvailability
            v-if="availabilityEnabled"
            :language="language"
            :status="availabilityStatus"
            :slots="roomSlots"
            :selected-time="bookingForm.time"
            :generated-at="availabilityGeneratedAt"
            @refresh="refreshAvailability"
          />
        </section>
        <div v-else class="booking-layout">
          <form novalidate class="bk-form" @submit.prevent="submit">
            <div
              v-if="recoveryRequired"
              ref="recoveryRegion"
              class="bk-recovery"
              data-booking-recovery
              role="group"
              aria-labelledby="booking-submit-error"
              tabindex="-1"
            >
              <p
                id="booking-submit-error"
                class="err"
                role="alert"
                aria-live="assertive"
              >
                {{ labels.retry }} {{ fieldError("submission_key") }}
                {{ fieldError("expected_total_price_idr") }}
              </p>
              <div class="bk-review__actions">
                <Link
                  v-if="bookingForm.errors.submission_key"
                  data-booking-existing
                  class="btn btn--primary"
                  href="/bookings"
                  >{{ labels.existing }}</Link
                >
                <Link
                  data-booking-restart
                  class="btn btn--primary"
                  :href="restartHref"
                >
                  {{
                    bookingForm.errors.submission_key
                      ? labels.restart
                      : labels.refreshPrice
                  }}
                </Link>
              </div>
            </div>
            <p
              v-else-if="hasServerErrors"
              ref="serverErrorSummary"
              id="booking-submit-error"
              class="err"
              role="alert"
              aria-live="assertive"
              tabindex="-1"
            >
              {{ labels.retry }}
            </p>

            <section class="bk-step">
              <header class="bk-step__head">
                <h2>1. {{ labels.s1 }}</h2>
                <p>{{ labels.s1sub }}</p>
              </header>
              <div
                class="field bk-step__block"
                data-booking-service-group
                tabindex="-1"
                :aria-invalid="fieldError('service') ? 'true' : undefined"
                :aria-describedby="describedBy('service')"
              >
                <span id="booking-service-error" class="err">{{
                  fieldError("service")
                }}</span>
                <div
                  v-if="servicePackage"
                  role="group"
                  data-booking-selection-group
                  tabindex="-1"
                  :aria-invalid="selectionErrors.length ? 'true' : undefined"
                  :aria-describedby="
                    selectionErrors.length
                      ? 'booking-selection-error'
                      : undefined
                  "
                >
                  <PackageConfigurator
                    v-model="selected"
                    :service-package="servicePackage"
                    :guests="bookingForm.guests"
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
                <p v-else class="bk-step__empty">
                  {{ labels.pickPrompt }}
                  <Link class="ulink" href="/services">{{
                    labels.pickLink
                  }}</Link>
                </p>
              </div>
            </section>

            <section class="bk-step">
              <header class="bk-step__head">
                <h2>2. {{ labels.s2 }}</h2>
                <p>{{ labels.s2sub }}</p>
              </header>
              <div class="bk-fields">
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
                    :disabled="
                      availabilityEnabled && availabilityStatus !== 'success'
                    "
                    :aria-invalid="fieldError('time') ? 'true' : undefined"
                    :aria-describedby="
                      describedBy(
                        'time',
                        availabilityEnabled
                          ? [
                              'room-availability-status',
                              'room-availability-caveat',
                            ]
                          : [],
                      )
                    "
                  >
                    <option value="">{{ tr("b.ftime") }}</option>
                    <option
                      v-for="slot in availableTimeSlots"
                      :key="slot"
                      :value="slot"
                    >
                      {{ slot }}
                    </option></select
                  ><span id="f-time-error" class="err">{{
                    fieldError("time")
                  }}</span>
                </div>
                <div class="field bk-fields__wide">
                  <label for="f-guests">{{ tr("b.fguests") }}</label
                  ><select
                    id="f-guests"
                    v-model.number="bookingForm.guests"
                    :aria-invalid="fieldError('guests') ? 'true' : undefined"
                    :aria-describedby="describedBy('guests')"
                  >
                    <option
                      v-for="guest in guestOptions"
                      :key="guest"
                      :value="guest"
                    >
                      {{ tr(`b.guest${guest}`) }}
                    </option>
                  </select>
                  <span id="f-guests-error" class="err">{{
                    fieldError("guests")
                  }}</span>
                </div>
              </div>
              <RoomAvailability
                v-if="availabilityEnabled"
                :language="language"
                :status="availabilityStatus"
                :slots="roomSlots"
                :selected-time="bookingForm.time"
                :generated-at="availabilityGeneratedAt"
                @refresh="refreshAvailability"
              />
            </section>

            <section class="bk-step">
              <header class="bk-step__head">
                <h2>3. {{ labels.s3 }}</h2>
                <p>{{ labels.s3sub }}</p>
              </header>
              <div class="bk-fields">
                <div class="field">
                  <label for="f-name">{{ tr("b.fname") }}</label
                  ><input
                    id="f-name"
                    ref="nameInput"
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
                <div class="field bk-fields__wide">
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
              </div>
              <p class="bk-guarantee">
                <ShieldCheck class="bk-guarantee__icon" aria-hidden="true" />
                <span>{{ tr("menu.hotWaterPolicy") }}</span>
              </p>
            </section>

            <button
              type="submit"
              class="btn btn--primary btn--lg btn--block bk-submit"
              :disabled="bookingForm.processing || recoveryRequired"
            >
              {{ bookingForm.processing ? labels.pending : labels.action }}
              <ArrowRight class="bk-submit__icon" aria-hidden="true" />
            </button>
          </form>
          <aside class="summary-card" :aria-label="tr('b.summary')">
            <div class="summary-card__head">
              <span class="summary-card__eyebrow">{{ tr("nav.book") }}</span>
              <h3>{{ packageName }}</h3>
              <p>{{ modeLabel }}</p>
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
              <span
                >{{ tr("b.total")
                }}<template v-if="pricingVerified">
                  · {{ pricingBasisLabel }}</template
                ></span
              ><span class="amt">{{
                total.duration
                  ? `Rp ${total.price.toLocaleString("id-ID")}`
                  : "—"
              }}</span>
            </div>
            <p v-if="total.duration" class="estimate-scope">
              {{ scopeCopy }}
            </p>
            <div
              v-if="contactInformation.whatsappUrl"
              class="summary-card__help"
            >
              <span>{{ labels.help }}</span>
              <a
                :href="contactInformation.whatsappUrl"
                target="_blank"
                rel="noopener noreferrer"
              >
                {{ labels.concierge }}
                <ExternalLink aria-hidden="true" />
              </a>
            </div>
          </aside>
        </div>
      </div>
    </section>
  </MainLayout>
</template>

<style scoped>
.bk-steps {
  padding-top: 78px;
  background: #fff;
  border-bottom: 1px solid var(--line);
}
.bk-steps__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem 1.5rem;
  padding-block: 1rem;
}
.bk-steps__crumb {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--text-muted);
}
.bk-steps__crumb span:last-child {
  color: var(--ink);
  letter-spacing: 0.08em;
}
.bk-steps__flow {
  display: flex;
  align-items: center;
  gap: clamp(0.5rem, 2vw, 1rem);
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.75rem;
}
.bk-steps__item {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--text-muted);
}
.bk-steps__item.is-active {
  color: var(--ink);
  font-weight: 600;
}
.bk-steps__dot {
  display: grid;
  place-items: center;
  width: 1.1rem;
  height: 1.1rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  font-size: 0.6rem;
  line-height: 1;
}
.bk-steps__item.is-active .bk-steps__dot {
  background: var(--rose);
  border-color: var(--rose);
  color: #fff;
}
.bk-steps__line {
  width: clamp(1rem, 4vw, 2.75rem);
  height: 1px;
  background: var(--line);
}
.bk-steps__label {
  display: none;
  letter-spacing: 0.06em;
}
.bk-steps__item.is-active .bk-steps__label {
  display: inline;
}
@media (min-width: 640px) {
  .bk-steps__label {
    display: inline;
  }
}
.bk-main {
  padding-top: clamp(2.5rem, 5vw, 4rem);
}
.bk-form {
  display: grid;
  gap: clamp(1.75rem, 4vw, 2.75rem);
  min-width: 0;
}
.bk-step {
  display: grid;
  gap: 1.1rem;
  min-width: 0;
}
.bk-step + .bk-step {
  padding-top: clamp(1.5rem, 3vw, 2rem);
  border-top: 1px solid rgba(140, 59, 67, 0.1);
}
.bk-step__head h2 {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(1.25rem, 2.4vw, 1.5rem);
  letter-spacing: -0.01em;
  color: var(--ink);
}
.bk-step__head p {
  margin: 0.35rem 0 0;
  font-size: 0.8rem;
  color: var(--text-muted);
}
.bk-step__block {
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}
.bk-step__empty {
  margin: 0;
  padding: 0.9rem 1rem;
  border: 1px dashed var(--line);
  border-radius: 0.75rem;
  background: var(--surface);
  color: var(--text-muted);
  font-size: 0.85rem;
  line-height: 1.7;
}
.bk-step__empty a {
  color: var(--rose-strong);
  font-weight: 600;
}
.bk-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.1rem;
}
.bk-fields__wide {
  grid-column: 1 / -1;
}
.bk-form .field label {
  display: block;
  margin-bottom: 0.4rem;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--text-muted);
}
.bk-form .field input,
.bk-form .field select,
.bk-form .field textarea {
  width: 100%;
  min-height: 44px;
  padding: 0.7rem 0.9rem;
  border: 1px solid #e4dcd3;
  border-radius: 0.75rem;
  background: #fdfbf7;
  color: var(--ink);
  font-size: 0.9rem;
}
.bk-form .field textarea {
  min-height: 7rem;
  resize: vertical;
}
.bk-form .field input:focus,
.bk-form .field select:focus,
.bk-form .field textarea:focus {
  outline: none;
  border-color: rgba(140, 59, 67, 0.45);
  box-shadow: 0 0 0 3px rgba(140, 59, 67, 0.12);
}
.bk-form .field input[aria-invalid="true"],
.bk-form .field select[aria-invalid="true"],
.bk-form .field textarea[aria-invalid="true"] {
  border-color: var(--rose-strong);
}
.bk-guarantee {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  margin: 0;
  padding: 0.85rem 0.95rem;
  border: 1px solid var(--line);
  border-radius: 0.75rem;
  background: var(--surface);
  color: var(--text-muted);
  font-size: 0.78rem;
  line-height: 1.6;
}
.bk-guarantee__icon {
  flex: none;
  width: 1rem;
  height: 1rem;
  margin-top: 0.15rem;
  color: var(--rose);
}
.bk-submit {
  gap: 0.55em;
  letter-spacing: 0.15em;
}
.bk-submit__icon {
  width: 1rem;
  height: 1rem;
}
.bk-review__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.8rem;
  margin-top: 1.5rem;
}
.bk-recovery {
  scroll-margin-top: 96px;
}
.bk-form [tabindex="-1"] {
  scroll-margin-top: 96px;
}
.bk-recovery > .err {
  margin: 0;
}
.bk-recovery .bk-review__actions {
  margin-top: 1rem;
}
.form-success {
  text-align: left;
  padding: clamp(1.5rem, 4vw, 2.25rem);
  border-radius: 1.25rem;
}
.form-success .h3,
.form-success h2 {
  margin-top: 0;
}
.form-success h2 {
  scroll-margin-top: 96px;
}
.booking-recap .summary-row {
  display: grid;
  grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
  align-items: start;
}
.booking-recap .summary-row span {
  min-width: 0;
}
.booking-recap .summary-row span:last-child {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.booking-recap .summary-row--notes {
  grid-template-columns: minmax(0, 1fr);
  gap: 0.35rem;
}
.booking-recap .summary-row--notes span:last-child {
  text-align: left;
}
.booking-recap .summary-total {
  gap: 0.75rem;
}
.booking-recap .summary-total .amt {
  flex: 0 0 auto;
  white-space: nowrap;
  text-align: right;
}
.estimate-scope {
  margin: 0.65rem 0 0;
  color: var(--text-muted);
  font-size: 0.78rem;
  line-height: 1.5;
}
#f-name {
  scroll-margin-top: 96px;
}
.summary-card {
  padding: 1.5rem;
  border-radius: 1.25rem;
  top: 96px;
}
.summary-card__head {
  display: grid;
  gap: 0.15rem;
  padding-bottom: 1rem;
  margin-bottom: 0.35rem;
  border-bottom: 1px solid var(--line);
}
.summary-card__eyebrow {
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--text-muted);
}
.summary-card__head h3 {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 1.25rem;
  color: var(--ink);
}
.summary-card__head p {
  margin: 0.1rem 0 0;
  font-size: 0.8rem;
  color: var(--text-muted);
}
.summary-card .summary-row {
  font-size: 0.82rem;
  padding: 0.6rem 0;
  border-bottom: none;
}
.summary-card .summary-row + .summary-row {
  border-top: 1px dashed var(--line);
}
.summary-card .summary-row span:last-child {
  font-weight: 600;
}
.summary-card .summary-total {
  margin-top: 0.9rem;
  padding-top: 0.9rem;
  border-top: 1px solid var(--line);
}
.summary-card .summary-total span:first-child {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--text-muted);
}
.summary-card .summary-total .amt {
  font-size: 1.7rem;
}
.summary-card__help {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 0.75rem;
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid var(--line);
  font-size: 0.78rem;
  color: var(--text-muted);
}
.summary-card__help a {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--ink);
  font-weight: 600;
}
.summary-card__help a:hover {
  color: var(--rose-strong);
}
.summary-card__help svg {
  width: 0.85rem;
  height: 0.85rem;
}
@media (max-width: 620px) {
  .bk-fields {
    grid-template-columns: 1fr;
  }
  .bk-fields__wide {
    grid-column: auto;
  }
  .bk-review__actions {
    flex-direction: column;
  }
  .bk-review__actions .btn {
    width: 100%;
  }
}
@media (max-width: 360px) {
  .booking-recap .summary-total {
    flex-direction: column;
    align-items: flex-start;
  }
  .booking-recap .summary-total .amt {
    align-self: flex-end;
  }
}
@media (max-width: 390px) {
  .booking-recap .summary-row--name,
  .booking-recap .summary-row--phone {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.15rem;
  }
  .booking-recap .summary-row--name span:last-child,
  .booking-recap .summary-row--phone span:last-child {
    text-align: left;
  }
}
</style>
