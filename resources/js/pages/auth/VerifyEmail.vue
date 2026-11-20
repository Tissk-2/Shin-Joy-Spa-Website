<script setup lang="ts">
import { Head, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, nextTick, ref } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import AuthLayout from "@/layouts/AuthLayout.vue";
import type { AppPageProps, BookingAuthContext } from "@/types";

const props = defineProps<{
  status?: string;
  bookingContext?: BookingAuthContext | null;
}>();

const { tr } = useLanguage();
const page = usePage<AppPageProps>();
const account = computed(() => page.props.auth?.user ?? null);

// A mistyped address could never be verified: it is corrected here, behind the
// account password, and the server sends a new link to the corrected address.
const changeForm = useForm({
  name: account.value?.name ?? "",
  email: "",
  current_password: "",
});
const changing = ref(false);
const changeToggle = ref<HTMLButtonElement | null>(null);
const changeEmailInput = ref<HTMLInputElement | null>(null);

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

function openChange(): void {
  changing.value = true;
  void nextTick(() => changeEmailInput.value?.focus());
}

function closeChange(): void {
  changing.value = false;
  changeForm.clearErrors();
  changeForm.reset();
  void nextTick(() => changeToggle.value?.focus());
}

function submitChange(): void {
  changeForm
    .transform((data) => ({
      ...data,
      name: account.value?.name ?? data.name,
      email: String(data.email).trim().toLowerCase(),
    }))
    .patch("/settings/profile", {
      preserveScroll: true,
      onSuccess: () => {
        changing.value = false;
      },
      onFinish: () =>
        changeForm.defaults("current_password", "").reset("current_password"),
    });
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

    <p v-if="account?.email" class="verify-address">
      {{ tr("auth.verify.sentTo") }} <strong>{{ account.email }}</strong>
      <button
        v-if="!changing"
        ref="changeToggle"
        type="button"
        class="verify-link"
        data-verify-change-email
        @click="openChange"
      >
        {{ tr("auth.verify.wrongEmail") }}
      </button>
    </p>

    <form
      v-if="changing"
      class="auth-form"
      data-verify-change-form
      @submit.prevent="submitChange"
    >
      <p class="verify-hint">{{ tr("auth.verify.changeHint") }}</p>
      <div class="field" :class="{ invalid: changeForm.errors.email }">
        <label for="verify-new-email">{{ tr("auth.verify.newEmail") }}</label>
        <input
          id="verify-new-email"
          ref="changeEmailInput"
          v-model="changeForm.email"
          type="email"
          required
          autocomplete="email"
          :aria-invalid="changeForm.errors.email ? 'true' : undefined"
          :aria-describedby="
            changeForm.errors.email ? 'verify-new-email-error' : undefined
          "
        />
        <span
          v-if="changeForm.errors.email"
          id="verify-new-email-error"
          class="err"
          >{{ changeForm.errors.email }}</span
        >
      </div>
      <div
        class="field"
        :class="{ invalid: changeForm.errors.current_password }"
      >
        <label for="verify-password">{{ tr("auth.password") }}</label>
        <input
          id="verify-password"
          v-model="changeForm.current_password"
          type="password"
          required
          autocomplete="current-password"
          :aria-invalid="
            changeForm.errors.current_password ? 'true' : undefined
          "
          :aria-describedby="
            changeForm.errors.current_password
              ? 'verify-password-error'
              : undefined
          "
        />
        <span
          v-if="changeForm.errors.current_password"
          id="verify-password-error"
          class="err"
          >{{ changeForm.errors.current_password }}</span
        >
      </div>
      <button
        type="submit"
        class="btn btn--primary btn--block"
        :disabled="changeForm.processing"
      >
        {{ tr("auth.verify.change") }}
      </button>
      <button type="button" class="verify-link" @click="closeChange">
        {{ tr("auth.verify.cancel") }}
      </button>
    </form>

    <form v-else class="auth-form" @submit.prevent="submit">
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

<style scoped>
.verify-address {
  margin: 0.75rem 0 1.25rem;
  color: var(--text-muted);
  overflow-wrap: anywhere;
}
.verify-address strong {
  color: var(--ink);
}
.verify-hint {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.9rem;
}
.verify-link {
  display: inline;
  margin-left: 0.35rem;
  padding: 0;
  border: 0;
  background: none;
  color: var(--rose-strong);
  font: inherit;
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}
.auth-form .verify-link {
  justify-self: center;
  min-height: 44px;
  margin-left: 0;
}
</style>
