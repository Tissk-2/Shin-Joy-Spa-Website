<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useContactInformation } from "@/composables/useContactInformation";
import { useLanguage } from "@/composables/useLanguage";
import MainLayout from "@/layouts/MainLayout.vue";
import { downloadBookingCard } from "@/lib/bookingCard";
import type {
  BookingChoiceData,
  BookingDetailData,
  BookingStatus,
} from "@/types";

const props = defineProps<{
  booking: BookingDetailData;
}>();

const { language, tr } = useLanguage();
const contactInformation = useContactInformation();
const downloading = ref(false);
const downloadError = ref(false);

const dateLocale = computed(() =>
  language.value === "id" ? "id-ID" : "en-GB",
);

const localizedPackageName = computed(() =>
  language.value === "id"
    ? props.booking.package.nameId
    : props.booking.package.nameEn,
);

function formatDate(date: string): string {
  const [year, month, day] = date.split("-").map(Number);
  return new Intl.DateTimeFormat(dateLocale.value, {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(new Date(year, month - 1, day));
}

function timezoneLabel(timezone: string): string {
  return timezone === "Asia/Jakarta" ? "WIB" : timezone;
}

function formatPrice(amount: number): string {
  return `Rp ${amount.toLocaleString("id-ID")}`;
}

function formatDuration(minutes: number): string {
  return `${minutes} ${tr("bookings.minutes")}`;
}

function formatGuests(count: number): string {
  return tr(count === 1 ? "bookings.guestOne" : "bookings.guestMany", {
    count,
  });
}

function statusLabel(status: BookingStatus): string {
  return tr(`bookings.status.${status}`);
}

function modeLabel(): string {
  return tr(`bookings.treatmentMode.${props.booking.treatmentMode}`);
}

function choiceMeta(choice: BookingChoiceData): string {
  return `${choice.choiceCode} / ${formatDuration(choice.durationMinutes)} / ${formatPrice(choice.priceIdr)}`;
}

async function downloadCard(): Promise<void> {
  if (downloading.value) return;

  downloading.value = true;
  downloadError.value = false;

  try {
    await downloadBookingCard(
      props.booking,
      language.value,
      contactInformation.value,
    );
  } catch {
    downloadError.value = true;
  } finally {
    downloading.value = false;
  }
}
</script>

<template>
  <Head :title="`${props.booking.reference} · Shin Joy Spa Malang`" />

  <MainLayout>
    <section class="page-hero booking-page-hero">
      <div class="container">
        <nav class="breadcrumb" :aria-label="tr('nav.breadcrumb')">
          <Link class="ulink" href="/">{{ tr("nav.home") }}</Link>
          <span aria-hidden="true">›</span>
          <Link class="ulink" href="/bookings">{{ tr("bookings.title") }}</Link>
          <span aria-hidden="true">›</span>
          <span>{{ props.booking.reference }}</span>
        </nav>

        <div class="booking-detail-title">
          <div>
            <p class="booking-detail-title__label">
              {{ tr("bookings.reference") }}
            </p>
            <h1>{{ props.booking.reference }}</h1>
          </div>
          <span
            class="booking-status"
            :class="`booking-status--${props.booking.status}`"
            >{{ statusLabel(props.booking.status) }}</span
          >
        </div>
      </div>
    </section>

    <section class="section booking-detail-section">
      <div class="container booking-detail">
        <div class="booking-appointment-band">
          <dl>
            <div>
              <dt>{{ tr("bookings.date") }}</dt>
              <dd>{{ formatDate(props.booking.appointment.date) }}</dd>
            </div>
            <div>
              <dt>{{ tr("bookings.time") }}</dt>
              <dd>
                {{ props.booking.appointment.time }}
                {{ timezoneLabel(props.booking.appointment.timezone) }}
              </dd>
            </div>
            <div>
              <dt>{{ tr("bookings.guests") }}</dt>
              <dd>{{ formatGuests(props.booking.guests) }}</dd>
            </div>
            <div>
              <dt>{{ tr("bookings.treatmentType") }}</dt>
              <dd>{{ modeLabel() }}</dd>
            </div>
          </dl>
        </div>

        <div class="booking-detail-grid">
          <article class="booking-panel booking-treatment-breakdown">
            <div class="booking-panel__header">
              <h2>{{ tr("bookings.treatmentBreakdown") }}</h2>
              <p>
                {{ localizedPackageName }}
                <span v-if="props.booking.package.isVip" class="booking-vip">{{
                  tr("bookings.vip")
                }}</span>
              </p>
            </div>

            <dl class="booking-breakdown-list">
              <div>
                <dt>{{ tr("bookings.baseTreatment") }}</dt>
                <dd>{{ choiceMeta(props.booking.base) }}</dd>
              </div>
              <div>
                <dt>{{ tr("bookings.extension") }}</dt>
                <dd v-if="props.booking.extension">
                  {{ choiceMeta(props.booking.extension) }}
                </dd>
                <dd v-else>{{ tr("bookings.noExtension") }}</dd>
              </div>
            </dl>
          </article>

          <aside class="booking-panel booking-total-panel">
            <h2>{{ tr("bookings.total") }}</h2>
            <dl>
              <div>
                <dt>{{ tr("bookings.totalDuration") }}</dt>
                <dd>
                  {{ formatDuration(props.booking.totalDurationMinutes) }}
                </dd>
              </div>
              <div>
                <dt>{{ tr("bookings.totalPrice") }}</dt>
                <dd>{{ formatPrice(props.booking.totalPriceIdr) }}</dd>
              </div>
            </dl>
          </aside>

          <article class="booking-panel">
            <h2>{{ tr("bookings.customerSnapshot") }}</h2>
            <dl class="booking-breakdown-list">
              <div>
                <dt>{{ tr("bookings.customerName") }}</dt>
                <dd>{{ props.booking.customer.name }}</dd>
              </div>
              <div>
                <dt>{{ tr("bookings.customerEmail") }}</dt>
                <dd>{{ props.booking.customer.email }}</dd>
              </div>
              <div>
                <dt>{{ tr("bookings.customerPhone") }}</dt>
                <dd>
                  {{ props.booking.customer.phone || tr("bookings.noPhone") }}
                </dd>
              </div>
            </dl>
          </article>

          <article class="booking-panel booking-notes-panel">
            <h2>{{ tr("bookings.notes") }}</h2>
            <p>{{ props.booking.notes || tr("bookings.noNotes") }}</p>
          </article>
        </div>

        <div class="booking-actions">
          <button
            type="button"
            class="btn btn--ghost"
            data-testid="download-booking-card"
            :disabled="downloading"
            @click="downloadCard"
          >
            {{ tr("bookings.downloadCard") }}
          </button>
          <Link class="btn btn--ghost" href="/bookings">{{
            tr("bookings.backToBookings")
          }}</Link>
          <Link class="btn btn--primary" href="/booking">{{
            tr("bookings.reserveAnother")
          }}</Link>
        </div>
        <p v-if="downloadError" class="booking-download-error" role="alert">
          {{ tr("bookings.downloadError") }}
        </p>
      </div>
    </section>
  </MainLayout>
</template>
