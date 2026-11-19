<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { ArrowLeft } from "lucide-vue-next";
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
const copied = ref(false);

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
    weekday: "long",
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

const statusNote = computed(() =>
  ["pending", "confirmed", "cancelled"].includes(props.booking.status)
    ? tr(`bookings.statusNote.${props.booking.status}`)
    : null,
);
const canStillChange = computed(() =>
  ["pending", "awaiting_payment", "confirmed"].includes(props.booking.status),
);

function modeLabel(): string {
  return tr(`bookings.treatmentMode.${props.booking.treatmentMode}`);
}

// The choice code is an internal catalogue key; guests see duration and price.
function choiceMeta(choice: BookingChoiceData): string {
  return `${formatDuration(choice.durationMinutes)} · ${formatPrice(choice.priceIdr)}`;
}

async function copyReference(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.booking.reference);
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 2000);
  } catch {
    copied.value = false;
  }
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
        <Link class="booking-back" href="/bookings">
          <ArrowLeft aria-hidden="true" />{{ tr("bookings.title") }}
        </Link>

        <div class="booking-detail-title">
          <div>
            <h1>{{ localizedPackageName }}</h1>
            <p class="booking-detail-title__when">
              {{ formatDate(props.booking.appointment.date) }} ·
              {{ props.booking.appointment.time }}
              {{ timezoneLabel(props.booking.appointment.timezone) }}
            </p>
            <p class="booking-reference">
              <span>{{ tr("bookings.reference") }}</span>
              <span class="booking-reference__code" data-booking-reference>{{
                props.booking.reference
              }}</span>
              <button
                type="button"
                class="booking-reference__copy"
                data-booking-copy-reference
                @click="copyReference"
              >
                {{ copied ? tr("bookings.copied") : tr("bookings.copy") }}
              </button>
              <span v-if="copied" class="sr-only" role="status">{{
                tr("bookings.copied")
              }}</span>
            </p>
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
        <p
          v-if="statusNote"
          class="booking-status-note"
          data-booking-status-note
        >
          {{ statusNote }}
        </p>

        <div class="booking-appointment-band">
          <dl>
            <div>
              <dt>{{ tr("bookings.guests") }}</dt>
              <dd>{{ formatGuests(props.booking.guests) }}</dd>
            </div>
            <div>
              <dt>{{ tr("bookings.treatmentType") }}</dt>
              <dd>{{ modeLabel() }}</dd>
            </div>
            <div v-if="props.booking.room" data-booking-room>
              <dt>{{ tr("bookings.room") }}</dt>
              <dd>{{ props.booking.room }}</dd>
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
            <p
              v-if="props.booking.pricingVerified"
              class="booking-total-note"
              data-booking-price-fixed
            >
              {{ tr("bookings.priceFixed") }}
            </p>
            <p v-else class="booking-total-note" data-booking-price-estimate>
              {{ tr("bookings.priceEstimate") }}
            </p>
            <p
              v-if="
                canStillChange && props.booking.paymentPreference === 'at_spa'
              "
              class="booking-total-note"
              data-booking-pay-at-spa
            >
              {{ tr("bookings.payAtSpa") }}
            </p>
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

          <article
            v-if="canStillChange"
            class="booking-panel booking-changes-panel"
            data-booking-changes
          >
            <h2>{{ tr("bookings.changesTitle") }}</h2>
            <p>{{ tr("bookings.changesBody") }}</p>
            <a
              v-if="contactInformation.whatsappUrl"
              :href="contactInformation.whatsappUrl"
              class="btn btn--ghost booking-changes-panel__contact"
              target="_blank"
              rel="noopener noreferrer"
              data-booking-contact
              >{{ tr("bookings.contactSpa") }}
              <span class="sr-only">{{ tr("bookings.newTab") }}</span></a
            >
            <p v-else>
              <a
                class="ulink"
                :href="'tel:' + contactInformation.phone.replace(/[^+\d]/g, '')"
                >{{ contactInformation.phone }}</a
              >
            </p>
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
