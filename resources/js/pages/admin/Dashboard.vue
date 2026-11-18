<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { Plus } from "lucide-vue-next";
import { computed, nextTick, ref } from "vue";

import BookingStatusDialog from "@/components/admin/BookingStatusDialog.vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import {
  formatDay,
  formatPrice,
  packageName,
  timeAgo,
  timezoneLabel,
} from "@/lib/adminBooking";
import type {
  AdminBookingSummaryData,
  AppPageProps,
  BookingStatus,
} from "@/types";

defineProps<{
  queue: AdminBookingSummaryData[];
  queueTotal: number;
  today: AdminBookingSummaryData[];
  pricingUnverified: number | null;
  roomSchedulingEnabled: boolean;
  canCreate: boolean;
}>();

const { language, tr } = useLanguage();
const page = usePage<AppPageProps>();
const answering = ref<{
  booking: AdminBookingSummaryData;
  next: BookingStatus;
} | null>(null);
const answerTrigger = ref<HTMLElement | null>(null);
const queueHeading = ref<HTMLElement | null>(null);

const todayLabel = computed(() =>
  new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "Asia/Jakarta",
  }).format(new Date()),
);

function bookingHref(booking: AdminBookingSummaryData): string {
  return `/admin/bookings/${encodeURIComponent(booking.reference)}`;
}

function answer(
  booking: AdminBookingSummaryData,
  next: BookingStatus,
  event: MouseEvent,
): void {
  answerTrigger.value = event.currentTarget as HTMLElement;
  answering.value = { booking, next };
}

// An answered request leaves the queue, so focus moves to the queue heading.
function answered(): void {
  answering.value = null;
  void nextTick(() => {
    const target = answerTrigger.value;
    if (target?.isConnected) target.focus();
    else queueHeading.value?.focus();
  });
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout :title="tr('ad.today.title')" :subtitle="todayLabel">
      <template v-if="canCreate" #actions>
        <Link
          href="/admin/bookings/create"
          class="admin-button admin-button--primary"
          data-new-booking
        >
          <Plus aria-hidden="true" />{{ tr("ad.manual.new") }}
        </Link>
      </template>
      <div class="admin-content">
        <p
          v-if="page.props.flash.status"
          class="admin-flash"
          role="status"
          aria-live="polite"
        >
          {{ page.props.flash.status }}
        </p>
        <p
          v-if="pricingUnverified"
          class="admin-callout admin-callout--warning"
          data-pricing-alert
        >
          {{ tr("ad.today.pricing", { n: pricingUnverified }) }}
          <Link href="/admin/catalog" class="admin-callout__link">{{
            tr("ad.today.pricingLink")
          }}</Link>
        </p>

        <div class="admin-today">
          <section
            class="admin-panel"
            aria-labelledby="today-queue-title"
            data-queue
          >
            <header class="admin-panel__head">
              <h2 id="today-queue-title" ref="queueHeading" tabindex="-1">
                {{ tr("ad.today.queue")
                }}<span
                  class="admin-tab__count"
                  :class="{ 'admin-tab__count--alert': queueTotal > 0 }"
                  >{{ queueTotal }}</span
                >
              </h2>
              <p v-if="queue.length">{{ tr("ad.today.queueNote") }}</p>
            </header>
            <p v-if="!queue.length" class="admin-panel__empty">
              {{ tr("ad.today.queueEmpty") }}
            </p>
            <ul v-else class="admin-queue" role="list">
              <li
                v-for="booking in queue"
                :key="booking.reference"
                :data-booking-row="booking.reference"
              >
                <div class="admin-queue__top">
                  <Link
                    class="admin-row-link"
                    :href="bookingHref(booking)"
                    :title="booking.reference"
                    ><span class="admin-row-link__name">{{
                      booking.customer.name
                    }}</span></Link
                  >
                  <span
                    v-if="booking.requestedAt"
                    class="admin-record-secondary admin-nowrap"
                    >{{
                      tr("ad.list.requested", {
                        when: timeAgo(booking.requestedAt, language),
                      })
                    }}</span
                  >
                </div>
                <p class="admin-booking-list__meta">
                  <span>{{
                    formatDay(booking.appointment.date, language, "short")
                  }}</span>
                  ·
                  <span
                    >{{ booking.appointment.time }}
                    {{ timezoneLabel(booking.appointment.timezone) }}</span
                  >
                  · <span>{{ packageName(booking, language) }}</span> ·
                  <span>{{ tr("ad.list.guests", { n: booking.guests }) }}</span>
                  ·
                  <span class="admin-price">{{
                    formatPrice(booking.totalPriceIdr)
                  }}</span>
                </p>
                <div
                  v-if="booking.allowedTransitions.includes('confirmed')"
                  class="admin-booking-list__actions"
                >
                  <button
                    type="button"
                    class="admin-button admin-button--danger"
                    data-answer="cancelled"
                    :aria-label="`${tr(`ad.action.${booking.status}.cancelled`)} ${booking.customer.name}`"
                    @click="answer(booking, 'cancelled', $event)"
                  >
                    {{ tr(`ad.action.${booking.status}.cancelled`) }}
                  </button>
                  <button
                    type="button"
                    class="admin-button admin-button--primary"
                    data-answer="confirmed"
                    :aria-label="`${tr(`ad.action.${booking.status}.confirmed`)} ${booking.customer.name}`"
                    @click="answer(booking, 'confirmed', $event)"
                  >
                    {{ tr(`ad.action.${booking.status}.confirmed`) }}
                  </button>
                </div>
              </li>
            </ul>
            <Link
              v-if="queueTotal > queue.length"
              href="/admin/bookings?scope=pending"
              class="admin-panel__more"
              >{{ tr("ad.today.queueMore", { n: queueTotal }) }}</Link
            >
          </section>

          <section
            class="admin-panel"
            aria-labelledby="today-agenda-title"
            data-agenda
          >
            <header class="admin-panel__head">
              <h2 id="today-agenda-title">
                {{ tr("ad.today.schedule")
                }}<span class="admin-tab__count">{{ today.length }}</span>
              </h2>
            </header>
            <p v-if="!today.length" class="admin-panel__empty">
              {{ tr("ad.today.scheduleEmpty") }}
            </p>
            <ol v-else class="admin-agenda">
              <li
                v-for="booking in today"
                :key="booking.reference"
                :data-booking-row="booking.reference"
              >
                <time class="admin-agenda__time">{{
                  booking.appointment.time
                }}</time>
                <div class="admin-agenda__body">
                  <Link
                    class="admin-row-link"
                    :href="bookingHref(booking)"
                    :title="booking.reference"
                    ><span class="admin-row-link__name">{{
                      booking.customer.name
                    }}</span></Link
                  >
                  <span class="admin-record-secondary"
                    >{{ packageName(booking, language) }} ·
                    {{ booking.totalDurationMinutes }}
                    {{ tr("bookings.minutes") }} ·
                    {{ tr("ad.list.guests", { n: booking.guests }) }}</span
                  >
                </div>
                <span
                  class="admin-status"
                  :class="`admin-status--${booking.status}`"
                  >{{ tr(`ad.status.${booking.status}`) }}</span
                >
              </li>
            </ol>
          </section>
        </div>
      </div>
    </AdminLayout>
    <BookingStatusDialog
      :booking="answering?.booking ?? null"
      :next-status="answering?.next ?? null"
      :room-scheduling-enabled="roomSchedulingEnabled"
      return-to="today"
      @close="answered"
    />
  </div>
</template>
