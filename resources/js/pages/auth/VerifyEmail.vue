<script setup lang="ts">
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import AuthLayout from "@/layouts/AuthLayout.vue";
import type { BookingAuthContext } from "@/types";

const props = defineProps<{
  status?: string;
  bookingContext?: BookingAuthContext | null;
}>();

const { tr } = useLanguage();
const form = useForm({});
const resendError = ref("");
const pageTitle = computed(
  () => `${tr("auth.verify.title")} · ${tr("auth.brand")}`,
);
const statusMessage = computed(() =>
  props.status === "verification-link-sent"
    ? tr("auth.verify.sent")
    : props.status,
);

function submit(): void {
  resendError.value = "";
  form.post("/email/verification-notification", {
    onError: () => {
      resendError.value = tr("auth.verify.error");
    },
  });
}

function logout(): void {
  router.post("/logout");
}
</script>

<template>
  <Head :title="pageTitle" />

  <AuthLayout
    :title="tr('auth.verify.title')"
    :description="tr('auth.verify.sub')"
    :booking-context="bookingContext"
    booking-step="verify"
  >
    <p
      v-if="statusMessage"
      class="auth-notice"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      {{ statusMessage }}
    </p>
    <p
      v-if="resendError"
      class="err"
      role="alert"
      aria-live="assertive"
      aria-atomic="true"
    >
      {{ resendError }}
    </p>

    <form class="auth-form" @submit.prevent="submit">
      <button
        type="submit"
        class="btn btn--primary btn--block"
        :disabled="form.processing"
      >
        {{
          form.processing
            ? tr("auth.verify.resending")
            : tr("auth.verify.resend")
        }}
      </button>

      <button
        type="button"
        class="auth-forgot"
        :disabled="form.processing"
        @click="logout"
      >
        {{ tr("auth.verify.logout") }}
      </button>
    </form>
  </AuthLayout>
</template>
