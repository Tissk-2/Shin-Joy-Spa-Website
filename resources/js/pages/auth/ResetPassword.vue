<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { Eye, EyeOff } from "lucide-vue-next";
import { useLanguage } from "@/composables/useLanguage";
import AuthLayout from "@/layouts/AuthLayout.vue";

const props = defineProps<{
  email: string;
  token: string;
}>();

const { tr } = useLanguage();
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);
const form = useForm({
  email: props.email,
  token: props.token,
  password: "",
  password_confirmation: "",
});
const pageTitle = computed(
  () => `${tr("auth.reset.title")} · ${tr("auth.brand")}`,
);
const passwordLabel = computed(() =>
  showPassword.value ? tr("auth.hidePassword") : tr("auth.showPassword"),
);
const passwordConfirmationLabel = computed(() =>
  showPasswordConfirmation.value
    ? tr("auth.hidePassword")
    : tr("auth.showPassword"),
);

function submit(): void {
  form.post("/reset-password", {
    onFinish: () => form.reset("password", "password_confirmation"),
  });
}
</script>

<template>
  <Head :title="pageTitle" />

  <AuthLayout
    :title="tr('auth.reset.title')"
    :description="tr('auth.reset.sub')"
  >
    <form class="auth-form" novalidate @submit.prevent="submit">
      <div class="field" :class="{ invalid: form.errors.password }">
        <label for="password">{{ tr("auth.password") }}</label>
        <div class="input-wrap">
          <input
            id="password"
            v-model="form.password"
            name="password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="new-password"
            required
            autofocus
            :aria-invalid="Boolean(form.errors.password)"
            :aria-describedby="'password-error'"
          />
          <button
            type="button"
            class="pw-toggle"
            :aria-pressed="showPassword"
            :aria-label="passwordLabel"
            @click="showPassword = !showPassword"
          >
            <Eye v-if="!showPassword" aria-hidden="true" />
            <EyeOff v-else aria-hidden="true" />
          </button>
        </div>
        <span id="password-error" class="err" aria-live="polite">{{
          form.errors.password || ""
        }}</span>
      </div>

      <div
        class="field"
        :class="{ invalid: form.errors.password_confirmation }"
      >
        <label for="password_confirmation">{{
          tr("auth.confirmPassword")
        }}</label>
        <div class="input-wrap">
          <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            name="password_confirmation"
            :type="showPasswordConfirmation ? 'text' : 'password'"
            autocomplete="new-password"
            required
            :aria-invalid="Boolean(form.errors.password_confirmation)"
            :aria-describedby="'password-confirmation-error'"
          />
          <button
            type="button"
            class="pw-toggle"
            :aria-pressed="showPasswordConfirmation"
            :aria-label="passwordConfirmationLabel"
            @click="showPasswordConfirmation = !showPasswordConfirmation"
          >
            <Eye v-if="!showPasswordConfirmation" aria-hidden="true" />
            <EyeOff v-else aria-hidden="true" />
          </button>
        </div>
        <span id="password-confirmation-error" class="err" aria-live="polite">{{
          form.errors.password_confirmation || ""
        }}</span>
      </div>

      <button
        type="submit"
        class="btn btn--primary btn--block"
        :disabled="form.processing"
      >
        {{
          form.processing ? tr("auth.reset.pending") : tr("auth.reset.submit")
        }}
      </button>
    </form>

    <p class="auth-alt">
      {{ tr("auth.existingUser") }}
      <Link href="/login">{{ tr("auth.goLogin") }}</Link>
    </p>
  </AuthLayout>
</template>
