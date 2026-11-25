<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import DeleteUser from "@/components/DeleteUser.vue";
import { useLanguage } from "@/composables/useLanguage";
import MainLayout from "@/layouts/MainLayout.vue";
import type { AppPageProps } from "@/types";

const page = usePage<AppPageProps>();
const { tr } = useLanguage();
const account = computed(() => page.props.auth.user);

const details = useForm({
  name: account.value?.name ?? "",
  email: account.value?.email ?? "",
  phone: account.value?.phone ?? "",
  current_password: "",
});

// The server asks for the password whenever the email changes.
const emailChanged = computed(() => details.email !== account.value?.email);

function saveDetails(): void {
  details.patch("/settings/profile", {
    preserveScroll: true,
    // A successful save copies the submitted data into the form's defaults,
    // so clear the default too, or reset() would put the password back.
    onFinish: () =>
      details.defaults("current_password", "").reset("current_password"),
  });
}

const password = useForm({
  current_password: "",
  password: "",
  password_confirmation: "",
});
const currentPasswordInput = ref<HTMLInputElement | null>(null);
const newPasswordInput = ref<HTMLInputElement | null>(null);

function savePassword(): void {
  password.put("/settings/password", {
    preserveScroll: true,
    onSuccess: () => password.reset(),
    onError: (errors) => {
      if (errors.password) {
        password.reset("password", "password_confirmation");
        newPasswordInput.value?.focus();
      }
      if (errors.current_password) {
        password.reset("current_password");
        currentPasswordInput.value?.focus();
      }
    },
  });
}
</script>

<template>
  <Head :title="`${tr('profile.title')} · Shin Joy Spa Malang`" />

  <MainLayout>
    <section class="page-hero">
      <div class="container">
        <h1>{{ tr("profile.title") }}</h1>
        <p class="lead">{{ tr("profile.sub") }}</p>
      </div>
    </section>

    <section class="section account-section">
      <div class="container">
        <div class="account">
          <section
            class="account-block"
            aria-labelledby="account-details-title"
          >
            <h2 id="account-details-title">{{ tr("profile.details") }}</h2>
            <form class="account-form" @submit.prevent="saveDetails">
              <div class="field">
                <label for="name">{{ tr("profile.name") }}</label>
                <input
                  id="name"
                  v-model="details.name"
                  type="text"
                  required
                  autocomplete="name"
                  :aria-invalid="details.errors.name ? 'true' : undefined"
                  :aria-describedby="
                    details.errors.name ? 'name-error' : undefined
                  "
                />
                <span v-if="details.errors.name" id="name-error" class="err">{{
                  details.errors.name
                }}</span>
              </div>

              <div class="field">
                <label for="email">{{ tr("profile.email") }}</label>
                <input
                  id="email"
                  v-model="details.email"
                  type="email"
                  required
                  autocomplete="username"
                  :aria-invalid="details.errors.email ? 'true' : undefined"
                  :aria-describedby="
                    details.errors.email ? 'email-error' : undefined
                  "
                />
                <span
                  v-if="details.errors.email"
                  id="email-error"
                  class="err"
                  >{{ details.errors.email }}</span
                >
              </div>

              <div v-if="emailChanged" class="field">
                <label for="current_password">{{
                  tr("profile.currentPassword")
                }}</label>
                <input
                  id="current_password"
                  v-model="details.current_password"
                  type="password"
                  required
                  autocomplete="current-password"
                  aria-describedby="current_password_hint"
                />
                <p id="current_password_hint" class="account-hint">
                  {{ tr("profile.emailPasswordHint") }}
                </p>
                <span v-if="details.errors.current_password" class="err">{{
                  details.errors.current_password
                }}</span>
              </div>

              <div class="field">
                <label for="phone">{{ tr("profile.phone") }}</label>
                <input
                  id="phone"
                  v-model="details.phone"
                  type="tel"
                  inputmode="tel"
                  required
                  autocomplete="tel"
                  :aria-invalid="details.errors.phone ? 'true' : undefined"
                  :aria-describedby="
                    details.errors.phone ? 'phone-error' : undefined
                  "
                />
                <span
                  v-if="details.errors.phone"
                  id="phone-error"
                  class="err"
                  >{{ details.errors.phone }}</span
                >
              </div>

              <div class="account-actions">
                <button
                  type="submit"
                  class="btn btn--primary"
                  :disabled="details.processing"
                >
                  {{ tr("profile.save") }}
                </button>
                <p
                  v-if="details.recentlySuccessful"
                  class="account-saved"
                  role="status"
                >
                  {{ tr("profile.saved") }}
                </p>
              </div>
            </form>
          </section>

          <section
            id="password"
            class="account-block"
            aria-labelledby="account-password-title"
          >
            <h2 id="account-password-title">
              {{ tr("profile.passwordTitle") }}
            </h2>
            <form class="account-form" @submit.prevent="savePassword">
              <div class="field">
                <label for="password_current">{{
                  tr("profile.currentPassword")
                }}</label>
                <input
                  id="password_current"
                  ref="currentPasswordInput"
                  v-model="password.current_password"
                  type="password"
                  autocomplete="current-password"
                  :aria-invalid="
                    password.errors.current_password ? 'true' : undefined
                  "
                />
                <span v-if="password.errors.current_password" class="err">{{
                  password.errors.current_password
                }}</span>
              </div>
              <div class="field">
                <label for="password_new">{{
                  tr("profile.newPassword")
                }}</label>
                <input
                  id="password_new"
                  ref="newPasswordInput"
                  v-model="password.password"
                  type="password"
                  autocomplete="new-password"
                  :aria-invalid="password.errors.password ? 'true' : undefined"
                />
                <span v-if="password.errors.password" class="err">{{
                  password.errors.password
                }}</span>
              </div>
              <div class="field">
                <label for="password_confirmation">{{
                  tr("profile.confirmPassword")
                }}</label>
                <input
                  id="password_confirmation"
                  v-model="password.password_confirmation"
                  type="password"
                  autocomplete="new-password"
                />
                <span
                  v-if="password.errors.password_confirmation"
                  class="err"
                  >{{ password.errors.password_confirmation }}</span
                >
              </div>
              <div class="account-actions">
                <button
                  type="submit"
                  class="btn btn--primary"
                  :disabled="password.processing"
                >
                  {{ tr("profile.savePassword") }}
                </button>
                <p
                  v-if="password.recentlySuccessful"
                  class="account-saved"
                  role="status"
                >
                  {{ tr("profile.passwordSaved") }}
                </p>
              </div>
            </form>
          </section>

          <section class="account-block" aria-labelledby="booking-history-link">
            <h2 id="booking-history-link">{{ tr("profile.bookingsTitle") }}</h2>
            <p class="account-hint">
              {{ tr("profile.bookingsBody") }}
              <Link class="ulink" href="/bookings">{{
                tr("profile.bookingsAction")
              }}</Link>
            </p>
          </section>

          <DeleteUser />
        </div>
      </div>
    </section>
  </MainLayout>
</template>
