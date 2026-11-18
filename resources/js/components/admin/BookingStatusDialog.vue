<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { DialogContent, DialogOverlay, DialogPortal } from "radix-vue";
import { computed, nextTick, ref, watch } from "vue";

import { Dialog, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { useLanguage } from "@/composables/useLanguage";
import { formatAppointment } from "@/lib/adminBooking";
import type { AdminBookingSummaryData, BookingStatus } from "@/types";

/**
 * Confirms one status change for one booking: the detail page, the bookings
 * list and the Today page all answer requests through this dialog.
 */
const props = defineProps<{
  booking: AdminBookingSummaryData | null;
  nextStatus: BookingStatus | null;
  roomSchedulingEnabled: boolean;
  /** Which page the server sends staff back to; the detail page by default. */
  returnTo?: "bookings" | "today";
}>();
const emit = defineEmits<{ close: [changed: boolean] }>();

const { language, tr } = useLanguage();
const heading = ref<HTMLElement | null>(null);
const feedback = ref<HTMLElement | null>(null);
const form = useForm({
  currentStatus: "" as BookingStatus | "",
  nextStatus: "" as BookingStatus | "",
  reason: "",
  operationalConfirmed: false,
});

const open = computed(
  () => props.booking !== null && props.nextStatus !== null,
);
const actionKey = computed(() =>
  open.value ? `${form.currentStatus}.${props.nextStatus}` : "",
);
const requiresOperational = computed(
  () =>
    props.roomSchedulingEnabled &&
    form.currentStatus === "pending" &&
    props.nextStatus === "confirmed",
);
const formErrors = computed(() =>
  Object.entries(form.errors)
    .filter(([key]) => !["currentStatus", "nextStatus", "reason"].includes(key))
    .map(([, value]) => value),
);
const reason = computed({
  get: () => form.reason,
  set: (value: string) => {
    form.reason = value.slice(0, 500);
  },
});
const packageName = computed(() =>
  props.booking
    ? language.value === "id"
      ? props.booking.package.nameId
      : props.booking.package.nameEn
    : "",
);

// Every opening starts clean: the status the page shows, no reason, no consent.
watch(
  open,
  (isOpen) => {
    if (!isOpen || !props.booking) return;
    form.currentStatus = props.booking.status;
    form.nextStatus = props.nextStatus ?? "";
    form.reason = "";
    form.operationalConfirmed = false;
    form.clearErrors();
  },
  { immediate: true },
);

function close(): void {
  if (!form.processing) emit("close", false);
}

function submit(): void {
  if (form.processing || !props.booking || !props.nextStatus) return;
  const next = props.nextStatus;
  form
    .transform(() => ({
      currentStatus: form.currentStatus,
      nextStatus: next,
      reason: form.reason.trim() || null,
      ...(requiresOperational.value
        ? { operationalConfirmed: form.operationalConfirmed }
        : {}),
      ...(props.returnTo ? { returnTo: props.returnTo } : {}),
    }))
    .patch(
      `/admin/bookings/${encodeURIComponent(props.booking.reference)}/status`,
      {
        preserveScroll: true,
        onSuccess: () => emit("close", true),
        onError: () => {
          void nextTick(() => feedback.value?.focus());
        },
        onFinish: () => {
          form.transform((data) => data);
        },
      },
    );
}
</script>

<template>
  <Dialog :open="open" @update:open="(value) => value || close()">
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
        @close-auto-focus="(event) => event.preventDefault()"
        @interact-outside.prevent
        @escape-key-down="
          (event) => {
            if (form.processing) event.preventDefault();
          }
        "
      >
        <DialogTitle as-child
          ><h2 ref="heading" tabindex="-1">
            {{ tr(`ad.actionTitle.${actionKey}`) }}
          </h2></DialogTitle
        >
        <p v-if="booking" class="admin-dialog-booking" data-dialog-booking>
          <strong>{{ booking.customer.name }}</strong>
          <span class="admin-record-secondary"
            >{{ formatAppointment(booking.appointment, language) }} ·
            {{ packageName }}</span
          >
        </p>
        <DialogDescription>{{
          tr("ad.confirm.body", {
            from: form.currentStatus
              ? tr(`ad.status.${form.currentStatus}`)
              : "",
            to: nextStatus ? tr(`ad.status.${nextStatus}`) : "",
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
          @submit.prevent="submit"
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
                >{{
                  form.currentStatus
                    ? tr(`ad.status.${form.currentStatus}`)
                    : ""
                }}</output
              >
              <span aria-hidden="true"> → </span>
              <output
                name="nextStatus"
                :aria-invalid="Boolean(form.errors.nextStatus)"
                :aria-describedby="
                  form.errors.nextStatus ? 'admin-next-status-error' : undefined
                "
                >{{ nextStatus ? tr(`ad.status.${nextStatus}`) : "" }}</output
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
            <label v-if="requiresOperational" for="admin-operational-confirmed">
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
              @click="close()"
            >
              {{ tr("ad.confirm.cancel") }}
            </button>
            <button
              class="admin-button"
              :class="
                nextStatus === 'cancelled'
                  ? 'admin-button--danger-solid'
                  : 'admin-button--primary'
              "
              type="submit"
              data-submit-status
              :disabled="form.processing"
            >
              {{ tr(`ad.action.${actionKey}`) }}
            </button>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </Dialog>
</template>
