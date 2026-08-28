<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { Eye, EyeOff } from "lucide-vue-next";
import { useLanguage } from "@/composables/useLanguage";
import AuthLayout from "@/layouts/AuthLayout.vue";

const { tr } = useLanguage();
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);
const form = useForm({
  name: "",
  email: "",
  phone: "",
  password: "",
  password_confirmation: "",
});
const pageTitle = computed(
  () => `${tr("auth.register.title")} · ${tr("auth.brand")}`,
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
  form.post("/register", {
    onFinish: () => form.reset("password", "password_confirmation"),
  });
}
</script>

<template>
  <Head :title="pageTitle" />

  <AuthLayout
    :title="tr('auth.register.title')"
    :description="tr('auth.register.sub')"
  >
    <form class="auth-form" novalidate @submit.prevent="submit">
      <div class="field" :class="{ invalid: form.errors.name }">
        <label for="name">{{ tr("auth.name") }}</label>
        <input
          id="name"
          v-model="form.name"
          name="name"
          type="text"
          autocomplete="name"
          required
          autofocus
          :aria-invalid="Boolean(form.errors.name)"
          :aria-describedby="'name-error'"
        />
        <span id="name-error" class="err" aria-live="polite">{{
          form.errors.name || ""
        }}</span>
      </div>

      <div class="field" :class="{ invalid: form.errors.email }">
        <label for="email">{{ tr("auth.email") }}</label>
        <input
          id="email"
          v-model="form.email"
          name="email"
          type="email"
          autocomplete="email"
          required
          :aria-invalid="Boolean(form.errors.email)"
          :aria-describedby="'register-email-error'"
        />
        <span id="register-email-error" class="err" aria-live="polite">{{
          form.errors.email || ""
        }}</span>
      </div>

      <div class="field" :class="{ invalid: form.errors.phone }">
        <label for="phone">{{ tr("auth.phone") }}</label>
        <input
          id="phone"
          v-model="form.phone"
          name="phone"
          type="tel"
          autocomplete="tel"
          required
          :aria-invalid="Boolean(form.errors.phone)"
          :aria-describedby="'phone-error'"
        />
        <span id="phone-error" class="err" aria-live="polite">{{
          form.errors.phone || ""
        }}</span>
      </div>

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
          form.processing
            ? tr("auth.register.pending")
            : tr("auth.register.submit")
        }}
      </button>
    </form>

    <p class="auth-alt">
      {{ tr("auth.existingUser") }}
      <Link href="/login">{{ tr("auth.goLogin") }}</Link>
    </p>
  </AuthLayout>
</template>
