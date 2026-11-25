<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { Check, ChevronLeft, Copy } from "lucide-vue-next";
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";

import BookingStatusDialog from "@/components/admin/BookingStatusDialog.vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import {
  formatAppointment,
  formatPrice,
  packageName as bookingPackageName,
  timeAgo,
} from "@/lib/adminBooking";
import type {
  AdminBookingDetailData,
  AdminBookingNotification,
  AppPageProps,
  BookingStatus,
} from "@/types";

const props = defineProps<{
  booking: AdminBookingDetailData;
  backUrl: string;
}>();
const { language, tr } = useLanguage();
const page = usePage<AppPageProps>();
const selectedNextStatus = ref<BookingStatus | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const statusSummary = ref<HTMLElement | null>(null);
const copied = ref(false);

const packageName = computed(() =>
  bookingPackageName(props.booking, language.value),
);
const appointment = computed(() =>
  formatAppointment(props.booking.appointment, language.value),
);

const notifications = computed(() => props.booking.notifications ?? []);
// A guest without an email can't get status emails, so staff must tell them another way.
const needsContact = computed(() =>
  notifications.value.some(
    (notice) =>
      notice.channel === "customer" && notice.detail === "skipped: no_email",
  ),
);
const telegramMessageKey = computed(() => {
  const message = props.booking.telegramMessage;
  // A Telegram notice finishes as "Processed" even when nothing was posted, so say when no message is recorded.
  if (!message) return "ad.notify.telegram.none";
  return message.state === "sent"
    ? `ad.notify.telegram.sent.${message.current ? "current" : "stale"}`
    : `ad.notify.telegram.${message.state}`;
});

function noticeStatus(notice: AdminBookingNotification): string {
  if (notice.state === "skipped") {
    const reason = (notice.detail ?? "").replace(/^skipped: /, "");
    const key = `ad.notify.skip.${reason}`;
    const label = tr(key);
    // An unknown reason shows its code instead of an untranslated key.
    return tr("ad.notify.state.skipped", {
      reason: label === key ? reason : label,
    });
  }
  if (notice.state === "completed")
    return tr(`ad.notify.state.completed.${notice.channel}`);
  // A requeued delivery is "retry" with no attempts: nothing has failed since, so it is waiting.
  const state =
    notice.state === "retry" && notice.attempts === 0
      ? "pending"
      : notice.state;
  return tr(`ad.notify.state.${state}`, { attempts: notice.attempts });
}

// A failure's sanitized code, so an owner can quote it. A skip already explains itself.
function noticeDetail(notice: AdminBookingNotification): string | null {
  return notice.state === "failed" || notice.state === "retry"
    ? notice.detail
    : null;
}

function formatDate(value: string, includeTime = false): string {
  return new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    day: "numeric",
    month: "long",
    year: "numeric",
    ...(includeTime
      ? ({
          hour: "2-digit",
          minute: "2-digit",
          timeZone: props.booking.appointment.timezone,
        } as const)
      : { timeZone: "UTC" }),
  }).format(new Date(includeTime ? value : `${value}T00:00:00Z`));
}

// The main action goes last, as on Hari ini and Reservasi (Tolak, then Setujui), so the
// same thumb position always means the same answer.
const actions = computed(() =>
  [...props.booking.allowedTransitions].sort(
    (a, b) =>
      Number(a === "confirmed" || a === "completed") -
      Number(b === "confirmed" || b === "completed"),
  ),
);

function actionClass(next: BookingStatus): string {
  if (next === "cancelled") return "admin-button admin-button--danger";
  if (next === "confirmed" || next === "completed")
    return "admin-button admin-button--primary";
  return "admin-button";
}

// Done and no-show are allowed only from the visit's start and end; until then the button
// waits with the reason. The clock ticks so a page left open unlocks on its own.
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;
onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 30_000)));
onUnmounted(() => clearInterval(clock));
function notYet(next: BookingStatus): boolean {
  const from =
    next === "completed"
      ? props.booking.completeFrom
      : next === "no_show"
        ? props.booking.noShowFrom
        : null;
  return Boolean(from) && now.value < Date.parse(from as string);
}

function openConfirmation(nextStatus: BookingStatus, event: MouseEvent): void {
  if (!props.booking.allowedTransitions.includes(nextStatus)) return;
  if (notYet(nextStatus)) return;
  trigger.value = event.currentTarget as HTMLButtonElement;
  selectedNextStatus.value = nextStatus;
}

// Back to the button that opened the dialog, or to the status when that button is gone.
function closeConfirmation(): void {
  selectedNextStatus.value = null;
  void nextTick(() => {
    const target = trigger.value;
    if (target?.isConnected && !target.disabled) target.focus();
    else statusSummary.value?.focus();
  });
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
</script>

<template>
  <div class="admin-shell">
    <AdminLayout :title="booking.customer.name" :subtitle="appointment">
      <div class="admin-content">
        <Link class="admin-back-link" data-back-bookings :href="backUrl"
          ><ChevronLeft aria-hidden="true" />{{ tr("ad.detail.back") }}</Link
        >
        <section
          ref="statusSummary"
          class="admin-status-actions"
          tabindex="-1"
          :aria-label="tr('ad.table.status')"
        >
          <p>
            <span class="admin-record-secondary">{{
              tr("ad.table.status")
            }}</span
            ><strong
              class="admin-status"
              :class="`admin-status--${booking.status}`"
              >{{ tr(`ad.status.${booking.status}`) }}</strong
            ><span
              v-if="booking.requestedAt"
              class="admin-record-secondary admin-requested"
              >{{
                tr("ad.list.requested", {
                  when: timeAgo(booking.requestedAt, language),
                })
              }}</span
            >
          </p>
          <div
            v-if="booking.allowedTransitions.length"
            role="group"
            :aria-label="tr('ad.table.action')"
          >
            <button
              v-for="status in actions"
              :key="status"
              :class="actionClass(status)"
              type="button"
              :data-next-status="status"
              :disabled="selectedNextStatus !== null || notYet(status)"
              :aria-describedby="
                notYet(status) ? `admin-action-wait-${status}` : undefined
              "
              @click="openConfirmation(status, $event)"
            >
              {{ tr(`ad.action.${booking.status}.${status}`) }}
            </button>
          </div>
          <template v-for="status in booking.allowedTransitions" :key="status">
            <p
              v-if="notYet(status)"
              :id="`admin-action-wait-${status}`"
              class="admin-record-secondary"
              :data-action-wait="status"
            >
              {{ tr(`ad.action.notYet.${status}`) }}
            </p>
          </template>
        </section>
        <p
          v-if="page.props.flash.status"
          class="admin-flash"
          role="status"
          aria-live="polite"
        >
          {{ page.props.flash.status }}
        </p>
        <section
          v-if="booking.notes?.trim()"
          class="admin-guest-note"
          aria-labelledby="admin-notes-title"
        >
          <h2 id="admin-notes-title">{{ tr("ad.detail.notes") }}</h2>
          <p data-booking-notes>{{ booking.notes }}</p>
        </section>
        <div class="admin-detail-grid">
          <section
            class="admin-detail-card"
            aria-labelledby="admin-appointment-title"
          >
            <h2 id="admin-appointment-title">
              {{ tr("ad.detail.appointment") }}
            </h2>
            <dl>
              <div>
                <dt>{{ tr("bookings.date") }}</dt>
                <dd>{{ formatDate(booking.appointment.date) }}</dd>
              </div>
              <div>
                <dt>{{ tr("bookings.time") }}</dt>
                <dd>
                  {{ booking.appointment.time }}
                  {{
                    booking.appointment.timezone === "Asia/Jakarta"
                      ? "WIB"
                      : booking.appointment.timezone
                  }}
                </dd>
              </div>
              <div>
                <dt>{{ tr("ad.table.guests") }}</dt>
                <dd data-booking-guests>{{ booking.guests }}</dd>
              </div>
              <div>
                <dt>{{ tr("bookings.totalDuration") }}</dt>
                <dd>
                  {{ booking.totalDurationMinutes }}
                  {{ tr("bookings.minutes") }}
                </dd>
              </div>
            </dl>
            <h2>{{ tr("ad.detail.treatment") }}</h2>
            <p>
              <strong>{{ packageName }}</strong
              ><span v-if="booking.package.isVip" class="admin-vip">{{
                tr("bookings.vip")
              }}</span>
            </p>
            <dl>
              <div>
                <dt>{{ tr("bookings.baseTreatment") }}</dt>
                <dd>
                  {{ tr(`bookings.treatmentMode.${booking.treatmentMode}`) }} ·
                  {{ booking.base.durationMinutes }}
                  {{ tr("bookings.minutes") }}
                  <span class="admin-record-secondary">{{
                    formatPrice(booking.base.priceIdr)
                  }}</span>
                </dd>
              </div>
              <div>
                <dt>{{ tr("bookings.extension") }}</dt>
                <dd v-if="booking.extension">
                  {{ booking.extension.durationMinutes }}
                  {{ tr("bookings.minutes") }}
                  <span class="admin-record-secondary">{{
                    formatPrice(booking.extension.priceIdr)
                  }}</span>
                </dd>
                <dd v-else>{{ tr("ad.detail.noExtension") }}</dd>
              </div>
              <div>
                <dt>{{ tr("bookings.totalPrice") }}</dt>
                <dd class="admin-price">
                  {{ formatPrice(booking.totalPriceIdr) }}
                </dd>
              </div>
            </dl>
          </section>
          <section
            class="admin-detail-card"
            aria-labelledby="admin-customer-title"
          >
            <h2 id="admin-customer-title">{{ tr("ad.detail.customer") }}</h2>
            <dl>
              <div>
                <dt>{{ tr("bookings.customerName") }}</dt>
                <dd>{{ booking.customer.name }}</dd>
              </div>
              <div>
                <dt>{{ tr("bookings.customerEmail") }}</dt>
                <dd>
                  <a
                    v-if="booking.customer.email"
                    class="admin-record-link"
                    :href="`mailto:${encodeURIComponent(booking.customer.email)}`"
                    >{{ booking.customer.email }}</a
                  >
                  <template v-else>{{ tr("ad.detail.noEmail") }}</template>
                </dd>
              </div>
              <div>
                <dt>{{ tr("bookings.customerPhone") }}</dt>
                <dd>
                  <a
                    v-if="booking.customer.phone"
                    class="admin-record-link"
                    :href="`tel:${encodeURIComponent(booking.customer.phone)}`"
                    >{{ booking.customer.phone }}</a
                  >
                  <template v-else>{{ tr("ad.detail.noPhone") }}</template>
                </dd>
              </div>
              <div v-if="booking.origin?.source" data-booking-origin>
                <dt>{{ tr("ad.detail.source") }}</dt>
                <dd>
                  {{ tr(`ad.source.${booking.origin.source}`) }}
                  <template v-if="booking.origin.staffName">
                    ·
                    {{
                      tr("ad.detail.enteredBy", {
                        name: booking.origin.staffName,
                      })
                    }}
                  </template>
                </dd>
              </div>
              <div>
                <dt>{{ tr("ad.booking.reference") }}</dt>
                <dd class="admin-reference">
                  <span class="admin-code" data-booking-reference>{{
                    booking.reference
                  }}</span>
                  <button
                    type="button"
                    class="admin-icon-button admin-icon-button--small"
                    :aria-label="tr('ad.detail.copy')"
                    :title="tr('ad.detail.copy')"
                    @click="copyReference"
                  >
                    <Check v-if="copied" aria-hidden="true" />
                    <Copy v-else aria-hidden="true" />
                  </button>
                  <span v-if="copied" class="sr-only" role="status">{{
                    tr("ad.detail.copied")
                  }}</span>
                </dd>
              </div>
            </dl>
          </section>
        </div>
        <section
          v-if="booking.assignedRoom"
          class="admin-detail-card admin-panel"
          data-assigned-room
        >
          <h2>{{ tr("ad.room.title") }}</h2>
          <p>
            {{ booking.assignedRoom.name }} · {{ booking.assignedRoom.code }} ·
            <template v-if="booking.assignedRoom.type"
              >{{ booking.assignedRoom.type }} · </template
            >{{ tr("ad.room.capacity") }}:
            {{ booking.assignedRoom.capacity }}
          </p>
          <p>
            {{ formatDate(booking.assignedRoom.startsAt, true) }} →
            {{ formatDate(booking.assignedRoom.endsAt, true) }}
          </p>
          <p v-if="booking.assignedRoom.releasedAt">
            {{ tr("ad.room.released") }}
            {{ formatDate(booking.assignedRoom.releasedAt, true) }}
          </p>
          <p
            v-else-if="
              ['cancelled', 'completed', 'no_show'].includes(booking.status)
            "
          >
            {{ tr("ad.room.retained") }}
          </p>
        </section>
        <div class="admin-detail-columns">
          <section class="admin-history" aria-labelledby="admin-history-title">
            <h2 id="admin-history-title">{{ tr("ad.history.title") }}</h2>
            <p v-if="!booking.history.length" class="admin-record-secondary">
              {{ tr("ad.history.empty") }}
            </p>
            <ol v-else class="admin-timeline">
              <li
                v-for="(change, index) in booking.history"
                :key="index"
                class="admin-history-item"
              >
                <time :datetime="change.createdAt">{{
                  formatDate(change.createdAt, true)
                }}</time>
                <div>
                  <p>
                    <strong
                      >{{ tr(`ad.status.${change.fromStatus}`) }} →
                      {{ tr(`ad.status.${change.toStatus}`) }}</strong
                    >
                  </p>
                  <p>
                    {{ change.actor.name ?? tr("ad.history.system")
                    }}<span class="admin-record-secondary">{{
                      change.actor.email
                    }}</span>
                  </p>
                  <p
                    v-if="change.operationalConfirmedAt"
                    data-operational-attestation
                  >
                    {{ tr("ad.history.operational") }}
                    {{ formatDate(change.operationalConfirmedAt, true) }}
                  </p>
                  <p>
                    <span class="admin-record-secondary">{{
                      tr("ad.history.reason")
                    }}</span
                    >{{
                      change.reason?.trim()
                        ? change.reason
                        : tr("ad.history.noReason")
                    }}
                  </p>
                </div>
              </li>
            </ol>
          </section>
          <section
            class="admin-history"
            aria-labelledby="admin-notify-title"
            data-notifications
          >
            <h2 id="admin-notify-title">{{ tr("ad.notify.title") }}</h2>
            <p
              v-if="booking.telegramMessage || notifications.length"
              data-telegram-message
            >
              <strong>{{ tr("ad.notify.channel.telegram") }}:</strong>
              {{ tr(telegramMessageKey) }}
            </p>
            <p v-if="needsContact" class="admin-callout" data-contact-task>
              {{ tr("ad.notify.contact") }}
            </p>
            <p v-if="!notifications.length" class="admin-record-secondary">
              {{ tr("ad.notify.empty") }}
            </p>
            <ol v-else class="admin-timeline">
              <li
                v-for="(notice, index) in notifications"
                :key="index"
                class="admin-history-item"
                data-notice
                :data-notice-state="notice.state"
              >
                <time :datetime="notice.updatedAt">{{
                  formatDate(notice.updatedAt, true)
                }}</time>
                <div>
                  <p>
                    <strong
                      >{{ tr(`ad.notify.event.${notice.event}`) }} ·
                      {{ tr(`ad.notify.channel.${notice.channel}`) }}</strong
                    >
                  </p>
                  <p>{{ noticeStatus(notice) }}</p>
                  <p v-if="noticeDetail(notice)" class="admin-record-secondary">
                    {{ noticeDetail(notice) }}
                  </p>
                </div>
              </li>
            </ol>
          </section>
        </div>
      </div>
    </AdminLayout>
    <!-- Stays mounted: a change can remove the last action while the dialog is open. -->
    <BookingStatusDialog
      :booking="selectedNextStatus ? booking : null"
      :next-status="selectedNextStatus"
      :room-scheduling-enabled="booking.roomSchedulingEnabled"
      @close="closeConfirmation"
    />
  </div>
</template>
