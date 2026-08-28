<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineProps<{
  status?: string;
}>();

const { tr } = useLanguage();
const form = useForm({
  email: "",
});
const pageTitle = computed(
  () => `${tr("auth.forgot.title")} · ${tr("auth.brand")}`,
);

function submit(): void {
  form
    .transform((data) => ({ ...data, email: data.email.trim() }))
    .post("/forgot-password");
}
</script>

<template>
  <Head :title="pageTitle" />

  <AuthLayout
    :title="tr('auth.forgot.title')"
    :description="tr('auth.forgot.sub')"
  >
    <p
      v-if="status"
      class="auth-notice"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      {{ status || tr("auth.forgot.sent") }}
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

      <button
        type="submit"
        class="btn btn--primary btn--block"
        :disabled="form.processing"
      >
        {{
          form.processing ? tr("auth.forgot.pending") : tr("auth.forgot.submit")
        }}
      </button>
    </form>

    <p class="auth-alt">
      {{ tr("auth.existingUser") }}
      <Link href="/login">{{ tr("auth.goLogin") }}</Link>
    </p>
  </AuthLayout>
</template>
