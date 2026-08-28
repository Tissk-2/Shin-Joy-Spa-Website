<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { Eye, EyeOff } from "lucide-vue-next";
import { useLanguage } from "@/composables/useLanguage";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineProps<{
  status?: string;
  canResetPassword: boolean;
}>();

const { tr } = useLanguage();
const showPassword = ref(false);
const form = useForm({
  email: "",
  password: "",
  remember: false,
});
const pageTitle = computed(
  () => `${tr("auth.login.title")} · ${tr("auth.brand")}`,
);
const passwordLabel = computed(() =>
  showPassword.value ? tr("auth.hidePassword") : tr("auth.showPassword"),
);

function submit(): void {
  form
    .transform((data) => ({ ...data, email: data.email.trim() }))
    .post("/login", {
      onFinish: () => form.reset("password"),
    });
}
</script>

<template>
  <Head :title="pageTitle" />

  <AuthLayout
    :title="tr('auth.login.title')"
    :description="tr('auth.login.sub')"
  >
    <p
      v-if="status"
      class="auth-notice"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      {{ status }}
    </p>

    <form class="auth-form" novalidate @submit.prevent="submit">
      <div class="field" :class="{ invalid: form.errors.email }">
        <label for="email">{{ tr("auth.email") }}</label>
        <input
          id="email"
          v-model="form.email"
          name="email"
          type="email"
          autocomplete="email"
          required
          autofocus
          :aria-invalid="Boolean(form.errors.email)"
          :aria-describedby="'email-error'"
        />
        <span id="email-error" class="err" aria-live="polite">{{
          form.errors.email || ""
        }}</span>
      </div>

      <div class="field" :class="{ invalid: form.errors.password }">
        <div class="auth-row">
          <label for="password">{{ tr("auth.password") }}</label>
          <Link
            v-if="canResetPassword"
            class="auth-forgot"
            href="/forgot-password"
          >
            {{ tr("auth.login.forgot") }}
          </Link>
        </div>
        <div class="input-wrap">
          <input
            id="password"
            v-model="form.password"
            name="password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
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

      <div class="auth-row">
        <label class="checkbox" for="remember">
          <input
            id="remember"
            v-model="form.remember"
            name="remember"
            type="checkbox"
          />
          <span>{{ tr("auth.remember") }}</span>
        </label>
      </div>

      <button
        type="submit"
        class="btn btn--primary btn--block"
        :disabled="form.processing"
      >
        {{
          form.processing ? tr("auth.login.pending") : tr("auth.login.submit")
        }}
      </button>
    </form>

    <p class="auth-alt">
      {{ tr("auth.newUser") }}
      <Link href="/register">{{ tr("auth.goRegister") }}</Link>
    </p>
  </AuthLayout>
</template>
