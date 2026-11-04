<script setup lang="ts">
import { Link, useForm, usePage } from "@inertiajs/vue3";
import { DialogContent, DialogOverlay, DialogPortal } from "radix-vue";
import { computed, nextTick, ref } from "vue";

import { Dialog, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type {
  AdminBookingDetailData,
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
const heading = ref<HTMLElement | null>(null);
const feedback = ref<HTMLElement | null>(null);
const statusSummary = ref<HTMLElement | null>(null);
const form = useForm({
  currentStatus: props.booking.status,
  nextStatus: "" as BookingStatus | "",
  reason: "",
  operationalConfirmed: false,
});
const requiresOperational = computed(
  () =>
    props.booking.roomSchedulingEnabled &&
    props.booking.status === "pending" &&
    selectedNextStatus.value === "confirmed",
);
const formErrors = computed(() =>
  Object.entries(form.errors)
    .filter(([key]) => !["currentStatus", "nextStatus", "reason"].includes(key))
    .map(([, value]) => value),
);
const packageName = computed(() =>
  language.value === "id"
    ? props.booking.package.nameId
    : props.booking.package.nameEn,
);
const reason = computed({
  get: () => form.reason,
  set: (value: string) => {
    form.reason = value.slice(0, 500);
  },
});

function formatPrice(amount: number): string {
  return `Rp ${new Intl.NumberFormat("id-ID").format(amount)}`;
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

function openConfirmation(nextStatus: BookingStatus, event: MouseEvent): void {
  if (form.processing || !props.booking.allowedTransitions.includes(nextStatus))
    return;
  trigger.value = event.currentTarget as HTMLButtonElement;
  form.currentStatus = props.booking.status;
  form.nextStatus = nextStatus;
  form.reason = "";
  form.operationalConfirmed = false;
  form.clearErrors();
  selectedNextStatus.value = nextStatus;
}

function closeConfirmation(open = false): void {
  if (!open && !form.processing) selectedNextStatus.value = null;
}

function restoreFocus(event: Event): void {
  event.preventDefault();
  focusStatus();
}

function focusStatus(): void {
  void nextTick(() => {
    const target = trigger.value;
    if (target?.isConnected && !target.disabled) target.focus();
    else statusSummary.value?.focus();
  });
}

function confirmTransition(): void {
  if (form.processing || selectedNextStatus.value === null) return;
  form
    .transform(() => ({
      currentStatus: form.currentStatus,
      nextStatus: selectedNextStatus.value,
      reason: form.reason.trim() || null,
      ...(requiresOperational.value
        ? { operationalConfirmed: form.operationalConfirmed }
        : {}),
    }))
    .patch(
      `/admin/bookings/${encodeURIComponent(props.booking.reference)}/status`,
      {
        preserveScroll: true,
        onSuccess: () => {
          selectedNextStatus.value = null;
        },
        onError: () => {
          void nextTick(() => feedback.value?.focus());
        },
        onFinish: () => {
          form.transform((data) => data);
          if (selectedNextStatus.value === null) focusStatus();
        },
      },
    );
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="booking.reference"
      :subtitle="tr('ad.booking.details')"
    >
      <div class="admin-content">
        <Link class="admin-record-link" data-back-bookings :href="backUrl">{{
          tr("ad.detail.back")
        }}</Link>
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
            >
          </p>
          <div
            v-if="booking.allowedTransitions.length"
            role="group"
            :aria-label="tr('ad.confirm.title')"
          >
            <button
              v-for="status in booking.allowedTransitions"
              :key="status"
              class="admin-button"
              type="button"
              :data-next-status="status"
              :disabled="form.processing"
              @click="openConfirmation(status, $event)"
            >
              {{ tr(`ad.status.${status}`) }}
            </button>
          </div>
        </section>
        <p v-if="page.props.flash.status" role="status" aria-live="polite">
          {{ page.props.flash.status }}
        </p>
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
            <p class="admin-record-secondary">
              {{ tr(`bookings.treatmentMode.${booking.treatmentMode}`) }}
            </p>
            <dl>
              <div>
                <dt>{{ tr("bookings.baseTreatment") }}</dt>
                <dd>
                  {{ booking.base.choiceCode
                  }}<span class="admin-record-secondary"
                    >{{ booking.base.durationMinutes }}
                    {{ tr("bookings.minutes") }} ·
                    {{ formatPrice(booking.base.priceIdr) }}</span
                  >
                </dd>
              </div>
              <div>
                <dt>{{ tr("bookings.extension") }}</dt>
                <dd v-if="booking.extension">
                  {{ booking.extension.choiceCode
                  }}<span class="admin-record-secondary"
                    >{{ booking.extension.durationMinutes }}
                    {{ tr("bookings.minutes") }} ·
                    {{ formatPrice(booking.extension.priceIdr) }}</span
                  >
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
                    class="admin-record-link"
                    :href="`mailto:${encodeURIComponent(booking.customer.email)}`"
                    >{{ booking.customer.email }}</a
                  >
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
            </dl>
            <h2>{{ tr("ad.detail.notes") }}</h2>
            <p data-booking-notes>
              {{
                booking.notes?.trim() ? booking.notes : tr("ad.detail.noNotes")
              }}
            </p>
          </section>
        </div>
        <section
          v-if="booking.assignedRoom"
          class="admin-detail-card"
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
          <p v-else-if="['cancelled', 'completed'].includes(booking.status)">
            {{ tr("ad.room.retained") }}
          </p>
        </section>
        <section class="admin-history" aria-labelledby="admin-history-title">
          <h2 id="admin-history-title">{{ tr("ad.history.title") }}</h2>
          <p v-if="!booking.history.length">{{ tr("ad.history.empty") }}</p>
          <ol v-else>
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
                  {{ change.actor.name
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
      </div>
    </AdminLayout>
    <Dialog
      v-if="booking.allowedTransitions.length"
      :open="selectedNextStatus !== null"
      @update:open="closeConfirmation"
    >
      <!-- Compose the existing wrappers with Radix content so the visible Cancel control
           can be localized and disabled during processing; the generic content owns a fixed Close button. -->
      <DialogPortal>
        <DialogOverlay class="admin-dialog-overlay" />
        <DialogContent
          class="admin-shell admin-dialog-content"
          role="alertdialog"
          @open-auto-focus="
            (event) => {
              event.preventDefault();
              heading?.focus();
            }
          "
          @close-auto-focus="restoreFocus"
          @interact-outside.prevent
          @escape-key-down="
            (event) => {
              if (form.processing) event.preventDefault();
            }
          "
        >
          <DialogTitle as-child
            ><h2 ref="heading" tabindex="-1">
              {{ tr("ad.confirm.title") }}
            </h2></DialogTitle
          >
          <DialogDescription>{{
            tr("ad.confirm.body", {
              from: tr(`ad.status.${form.currentStatus}`),
              to: selectedNextStatus
                ? tr(`ad.status.${selectedNextStatus}`)
                : "",
            })
          }}</DialogDescription>
          <p v-if="form.processing" role="status" aria-live="polite">
            {{ tr("ad.confirm.processing") }}
          </p>
          <form
            data-status-confirmation
            :aria-busy="form.processing"
            :aria-describedby="
              form.hasErrors ? 'admin-status-form-error' : undefined
            "
            @submit.prevent="confirmTransition"
          >
            <fieldset :disabled="form.processing">
              <legend>{{ tr("ad.table.status") }}</legend>
              <div>
                <output
                  name="currentStatus"
                  :aria-invalid="Boolean(form.errors.currentStatus)"
                  :aria-describedby="
                    form.errors.currentStatus
                      ? 'admin-current-status-error'
                      : undefined
                  "
                  >{{ tr(`ad.status.${form.currentStatus}`) }}</output
                >
                <span aria-hidden="true"> → </span>
                <output
                  name="nextStatus"
                  :aria-invalid="Boolean(form.errors.nextStatus)"
                  :aria-describedby="
                    form.errors.nextStatus
                      ? 'admin-next-status-error'
                      : undefined
                  "
                  >{{
                    selectedNextStatus
                      ? tr(`ad.status.${selectedNextStatus}`)
                      : ""
                  }}</output
                >
              </div>
              <p
                v-if="form.errors.currentStatus"
                id="admin-current-status-error"
                class="admin-filter-error"
                role="alert"
              >
                {{ form.errors.currentStatus }}
              </p>
              <p
                v-if="form.errors.nextStatus"
                id="admin-next-status-error"
                class="admin-filter-error"
                role="alert"
              >
                {{ form.errors.nextStatus }}
              </p>
              <label
                v-if="requiresOperational"
                for="admin-operational-confirmed"
              >
                <input
                  id="admin-operational-confirmed"
                  v-model="form.operationalConfirmed"
                  type="checkbox"
                  name="operationalConfirmed"
                  :aria-invalid="Boolean(form.errors.operationalConfirmed)"
                  :aria-describedby="
                    form.errors.operationalConfirmed
                      ? 'admin-status-form-error'
                      : undefined
                  "
                />
                {{ tr("ad.confirm.operational") }}
              </label>
              <label for="admin-status-reason">{{
                tr("ad.confirm.reason")
              }}</label>
              <textarea
                id="admin-status-reason"
                v-model="reason"
                class="admin-control"
                name="reason"
                rows="4"
                maxlength="500"
                :aria-invalid="Boolean(form.errors.reason)"
                :aria-describedby="
                  form.errors.reason ? 'admin-status-reason-error' : undefined
                "
              />
              <p
                v-if="form.errors.reason"
                id="admin-status-reason-error"
                class="admin-filter-error"
                role="alert"
              >
                {{ form.errors.reason }}
              </p>
            </fieldset>
            <div
              v-if="form.hasErrors"
              id="admin-status-form-error"
              ref="feedback"
              class="admin-filter-error"
              role="alert"
              tabindex="-1"
            >
              <p>{{ tr("ad.confirm.error") }}</p>
              <p v-for="(error, index) in formErrors" :key="index">
                {{ error }}
              </p>
            </div>
            <div class="admin-status-actions">
              <button
                class="admin-button"
                data-cancel-status
                type="button"
                :disabled="form.processing"
                @click="closeConfirmation()"
              >
                {{ tr("ad.confirm.cancel") }}
              </button>
              <button
                class="admin-button admin-button--primary"
                type="submit"
                :disabled="form.processing"
              >
                {{ tr("ad.confirm.submit") }}
              </button>
            </div>
          </form>
        </DialogContent>
      </DialogPortal>
    </Dialog>
  </div>
</template>
