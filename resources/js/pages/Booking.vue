<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import {
  timeSlots,
  todayIso,
  validateBooking,
  type BookingErrors,
} from "@/lib/booking";
import MainLayout from "@/layouts/MainLayout.vue";
import type { AppPageProps, ServiceData } from "@/types";

interface BookingForm {
  name: string;
  phone: string;
  email: string;
  service: string;
  date: string;
  time: string;
  guests: number;
  notes: string;
}

const props = defineProps<{
  services: ServiceData[];
  selectedService: string;
}>();
const page = usePage<AppPageProps>();
const { language, tr } = useLanguage();
const initialService = props.services.some(
  ({ slug }) => slug === props.selectedService,
)
  ? props.selectedService
  : "";
const title = computed(() => tr("b.title"));
const currentUser = page.props.auth.user;
const emptyForm = (): BookingForm => ({
  name: currentUser?.name ?? "",
  phone: currentUser?.phone ?? "",
  email: currentUser?.email ?? "",
  service: props.services.some(({ slug }) => slug === initialService)
    ? initialService
    : "",
  date: "",
  time: "",
  guests: 1,
  notes: "",
});
const form = ref<BookingForm>(emptyForm());
const errors = ref<BookingErrors>({});
const submitted = ref(false);
const selectedService = computed(() =>
  props.services.find(({ slug }) => slug === form.value.service),
);
const selectedServiceName = computed(() => {
  if (!selectedService.value) return tr("b.sumempty");
  return language.value === "id"
    ? selectedService.value.nameId
    : selectedService.value.nameEn;
});
const total = computed(() => selectedService.value?.price || 0);

function submit(): void {
  errors.value = validateBooking(form.value, props.services);
  if (Object.keys(errors.value).length) return;

  submitted.value = true;
  window.scrollTo({
    top: 0,
    behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches
      ? "auto"
      : "smooth",
  });
}

function reset(): void {
  submitted.value = false;
  errors.value = {};
  form.value = emptyForm();
}
</script>

<template>
  <Head
    :title="
      title.includes('Shin Joy Spa') ? title : `${title} · Shin Joy Spa Malang`
    "
  />

  <MainLayout>
    <section class="page-hero">
      <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <Link class="ulink" href="/">{{ tr("nav.home") }}</Link>
          <span aria-hidden="true">›</span>
          <span>{{ tr("nav.book") }}</span>
        </nav>
        <h1>{{ tr("b.title") }}</h1>
        <p class="lead">{{ tr("b.sub") }}</p>
      </div>
    </section>

    <section class="section" style="padding-top: clamp(2.5rem, 5vw, 3.5rem)">
      <div class="container">
        <div
          v-if="submitted"
          class="form-success show"
          style="max-width: 560px; margin-inline: auto"
          role="status"
        >
          <span class="check">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2.2"
              aria-hidden="true"
            >
              <path
                d="M5 13l4 4L19 7"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </span>
          <h2 class="h3">{{ tr("b.successt") }}, {{ form.name }}</h2>
          <p class="muted" style="max-width: 42ch; margin: 0.6rem auto 0">
            {{ tr("b.successs") }}
          </p>
          <div class="booking-recap">
            <div class="summary-row">
              <span>{{ tr("b.sumservice") }}</span>
              <span>{{ selectedServiceName }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumdate") }}</span>
              <span>{{ form.date }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumtime") }}</span>
              <span>{{ form.time }} WIB</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumguests") }}</span>
              <span>{{ tr(`b.guest${form.guests}`) }}</span>
            </div>
            <div class="summary-total">
              <span>{{ tr("b.total") }}</span>
              <span class="amt">Rp {{ total.toLocaleString("id-ID") }}</span>
            </div>
          </div>
          <p style="margin-top: 1.6rem">
            <button type="button" class="btn btn--primary" @click="reset">
              {{ tr("b.again") }}
            </button>
          </p>
        </div>

        <div v-else class="booking-layout">
          <form novalidate @submit.prevent="submit">
            <div class="form-grid">
              <div class="field full" :class="{ invalid: errors.name }">
                <label for="f-name">
                  {{ tr("b.fname") }}
                  <span class="req" aria-hidden="true">*</span>
                </label>
                <input
                  id="f-name"
                  v-model="form.name"
                  type="text"
                  autocomplete="name"
                  required
                  :aria-invalid="Boolean(errors.name)"
                  aria-describedby="f-name-error"
                />
                <span id="f-name-error" class="err" aria-live="polite">{{
                  errors.name ? tr(errors.name) : ""
                }}</span>
              </div>

              <div class="field" :class="{ invalid: errors.phone }">
                <label for="f-phone">
                  {{ tr("b.fphone") }}
                  <span class="req" aria-hidden="true">*</span>
                </label>
                <input
                  id="f-phone"
                  v-model="form.phone"
                  type="tel"
                  inputmode="tel"
                  autocomplete="tel"
                  placeholder="+62 8xx xxxx xxxx"
                  required
                  :aria-invalid="Boolean(errors.phone)"
                  aria-describedby="f-phone-error"
                />
                <span id="f-phone-error" class="err" aria-live="polite">{{
                  errors.phone ? tr(errors.phone) : ""
                }}</span>
              </div>

              <div class="field">
                <label for="f-email">{{ tr("b.femail") }}</label>
                <input
                  id="f-email"
                  v-model="form.email"
                  type="email"
                  autocomplete="email"
                />
                <span class="err"></span>
              </div>

              <div class="field full" :class="{ invalid: errors.service }">
                <label for="f-service">
                  {{ tr("b.fservice") }}
                  <span class="req" aria-hidden="true">*</span>
                </label>
                <select
                  id="f-service"
                  v-model="form.service"
                  required
                  :aria-invalid="Boolean(errors.service)"
                  aria-describedby="f-service-error"
                >
                  <option value="">{{ tr("b.fserviceph") }}</option>
                  <option
                    v-for="service in props.services"
                    :key="service.slug"
                    :value="service.slug"
                  >
                    {{ language === "id" ? service.nameId : service.nameEn }} ·
                    Rp {{ service.price.toLocaleString("id-ID") }}
                  </option>
                </select>
                <span id="f-service-error" class="err" aria-live="polite">{{
                  errors.service ? tr(errors.service) : ""
                }}</span>
              </div>

              <div class="field" :class="{ invalid: errors.date }">
                <label for="f-date">
                  {{ tr("b.fdate") }}
                  <span class="req" aria-hidden="true">*</span>
                </label>
                <input
                  id="f-date"
                  v-model="form.date"
                  type="date"
                  :min="todayIso()"
                  required
                  :aria-invalid="Boolean(errors.date)"
                  aria-describedby="f-date-error"
                />
                <span id="f-date-error" class="err" aria-live="polite">{{
                  errors.date ? tr(errors.date) : ""
                }}</span>
              </div>

              <div class="field" :class="{ invalid: errors.time }">
                <label for="f-time">
                  {{ tr("b.ftime") }}
                  <span class="req" aria-hidden="true">*</span>
                </label>
                <select
                  id="f-time"
                  v-model="form.time"
                  required
                  :aria-invalid="Boolean(errors.time)"
                  aria-describedby="f-time-error"
                >
                  <option value="">{{ tr("b.ftime") }}</option>
                  <option v-for="slot in timeSlots" :key="slot" :value="slot">
                    {{ slot }}
                  </option>
                </select>
                <span id="f-time-error" class="err" aria-live="polite">{{
                  errors.time ? tr(errors.time) : ""
                }}</span>
              </div>

              <div class="field full">
                <label for="f-guests">{{ tr("b.fguests") }}</label>
                <select id="f-guests" v-model="form.guests">
                  <option v-for="guest in 4" :key="guest" :value="guest">
                    {{ tr(`b.guest${guest}`) }}
                  </option>
                </select>
              </div>

              <div class="field full">
                <label for="f-notes">{{ tr("b.fnotes") }}</label>
                <textarea
                  id="f-notes"
                  v-model="form.notes"
                  :placeholder="tr('b.fnotesph')"
                ></textarea>
              </div>

              <div class="full">
                <button
                  type="submit"
                  class="btn btn--primary btn--lg btn--block"
                >
                  {{ tr("b.submit") }}
                </button>
              </div>
            </div>
          </form>

          <aside class="summary-card" :aria-label="tr('b.summary')">
            <h3>{{ tr("b.summary") }}</h3>
            <div class="summary-row">
              <span>{{ tr("b.sumservice") }}</span>
              <span>{{ selectedServiceName }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumduration") }}</span>
              <span>{{
                selectedService
                  ? `${selectedService.duration} ${tr("d.min")}`
                  : "—"
              }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumdate") }}</span>
              <span>{{ form.date || tr("b.sumempty") }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumtime") }}</span>
              <span>{{ form.time || tr("b.sumempty") }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumguests") }}</span>
              <span>{{ tr(`b.guest${form.guests}`) }}</span>
            </div>
            <div class="summary-total">
              <span>{{ tr("b.total") }}</span>
              <span class="amt">{{
                selectedService ? `Rp ${total.toLocaleString("id-ID")}` : "—"
              }}</span>
            </div>
            <p class="muted" style="font-size: 0.84rem; margin-top: 1rem">
              {{ tr("b.note") }}
            </p>
          </aside>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
