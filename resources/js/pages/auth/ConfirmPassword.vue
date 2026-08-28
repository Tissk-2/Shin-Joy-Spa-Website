<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { Eye, EyeOff } from "lucide-vue-next";
import { useLanguage } from "@/composables/useLanguage";
import AuthLayout from "@/layouts/AuthLayout.vue";

const { tr } = useLanguage();
const showPassword = ref(false);
const form = useForm({
  password: "",
});
const pageTitle = computed(
  () => `${tr("auth.confirm.title")} · ${tr("auth.brand")}`,
);
const passwordLabel = computed(() =>
  showPassword.value ? tr("auth.hidePassword") : tr("auth.showPassword"),
);

function submit(): void {
  form.post("/confirm-password", {
    onFinish: () => form.reset("password"),
  });
}
</script>

<template>
  <Head :title="pageTitle" />

  <AuthLayout
    :title="tr('auth.confirm.title')"
    :description="tr('auth.confirm.sub')"
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
            autocomplete="current-password"
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

      <button
        type="submit"
        class="btn btn--primary btn--block"
        :disabled="form.processing"
      >
        {{
          form.processing
            ? tr("auth.confirm.pending")
            : tr("auth.confirm.submit")
        }}
      </button>
    </form>
  </AuthLayout>
</template>
