<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import MainLayout from "@/layouts/MainLayout.vue";
import type { BookingSummaryData, BookingStatus } from "@/types";

const props = defineProps<{
  upcoming: BookingSummaryData[];
  past: BookingSummaryData[];
}>();

const { language, tr } = useLanguage();

const dateLocale = computed(() =>
  language.value === "id" ? "id-ID" : "en-GB",
);

const groups = computed(() => [
  {
    id: "upcoming-bookings-title",
    heading: tr("bookings.upcomingHeading", { count: props.upcoming.length }),
    bookings: props.upcoming,
    empty: tr("bookings.emptyUpcoming"),
  },
  {
    id: "past-bookings-title",
    heading: tr("bookings.pastHeading", { count: props.past.length }),
    bookings: props.past,
    empty: tr("bookings.emptyPast"),
  },
]);

function packageName(booking: BookingSummaryData): string {
  return language.value === "id"
    ? booking.package.nameId
    : booking.package.nameEn;
}

// The day leads each row, so it carries the weekday as well.
function formatDate(date: string): string {
  const [year, month, day] = date.split("-").map(Number);
  return new Intl.DateTimeFormat(dateLocale.value, {
    weekday: "short",
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

function modeLabel(booking: BookingSummaryData): string {
  return tr(`bookings.treatmentMode.${booking.treatmentMode}`);
}
</script>

<template>
  <Head :title="`${tr('bookings.title')} · Shin Joy Spa Malang`" />

  <MainLayout>
    <section class="page-hero booking-page-hero">
      <div class="container">
        <nav class="breadcrumb" :aria-label="tr('nav.breadcrumb')">
          <Link class="ulink" href="/">{{ tr("nav.home") }}</Link>
          <span aria-hidden="true">›</span>
          <span>{{ tr("bookings.title") }}</span>
        </nav>
        <h1>{{ tr("bookings.title") }}</h1>
        <p class="lead">{{ tr("bookings.sub") }}</p>
      </div>
    </section>

    <section class="section booking-ledger-section">
      <div class="container booking-ledger">
        <section
          v-for="group in groups"
          :key="group.id"
          class="booking-ledger__group"
          :aria-labelledby="group.id"
        >
          <div class="booking-ledger__heading">
            <h2 :id="group.id">{{ group.heading }}</h2>
          </div>

          <ul v-if="group.bookings.length" class="booking-list" role="list">
            <li
              v-for="booking in group.bookings"
              :key="booking.reference"
              class="booking-row"
            >
              <Link
                class="booking-row__link"
                :href="`/bookings/${booking.reference}`"
              >
                <span class="booking-row__date">
                  <span>{{ formatDate(booking.appointment.date) }}</span>
                  <span
                    >{{ booking.appointment.time }}
                    {{ timezoneLabel(booking.appointment.timezone) }}</span
                  >
                </span>
                <span class="booking-row__main">
                  <span class="booking-row__package">
                    {{ packageName(booking) }}
                    <span v-if="booking.package.isVip" class="booking-vip">{{
                      tr("bookings.vip")
                    }}</span>
                  </span>
                  <span class="booking-row__meta">
                    {{ modeLabel(booking) }}
                    <span aria-hidden="true">·</span>
                    {{ formatGuests(booking.guests) }}
                    <span aria-hidden="true">·</span>
                    {{ formatDuration(booking.totalDurationMinutes) }}
                  </span>
                  <span class="booking-row__reference">{{
                    booking.reference
                  }}</span>
                </span>
                <span class="booking-row__total">
                  <span
                    class="booking-status"
                    :class="`booking-status--${booking.status}`"
                    >{{ statusLabel(booking.status) }}</span
                  >
                  <strong>{{ formatPrice(booking.totalPriceIdr) }}</strong>
                </span>
              </Link>
            </li>
          </ul>

          <div v-else class="booking-empty">
            <p>{{ group.empty }}</p>
            <Link class="ulink" href="/booking">{{
              tr("bookings.reserveTreatment")
            }}</Link>
          </div>
        </section>
      </div>
    </section>
  </MainLayout>
</template>
