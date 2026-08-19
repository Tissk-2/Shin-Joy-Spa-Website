<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { language, setLanguage, tr } from '../useLanguage.js';

const router = useRouter();
const username = ref('admin');
const password = ref('admin123');
const passwordVisible = ref(false);
const error = ref('');
const isSubmitting = ref(false);

const pageTitle = computed(() => `${tr('auth.admin.title')} · ${tr('auth.brand')}`);

function handleLogin() {
  error.value = '';
  if (!username.value.trim()) {
    error.value = tr('auth.err.invalid');
    return;
  }

  isSubmitting.value = true;
  setTimeout(() => {
    // Frontend-only dummy authentication
    if (username.value.trim() && password.value) {
      if (typeof localStorage !== 'undefined') {
        localStorage.setItem('shinjoy-admin-session', 'true');
      }
      router.push('/admin');
    } else {
      error.value = tr('auth.err.invalid');
    }
    isSubmitting.value = false;
  }, 250);
}

onMounted(() => {
  document.body.classList.add('auth-body', 'auth-body--admin');
});

onUnmounted(() => {
  document.body.classList.remove('auth-body', 'auth-body--admin');
});
</script>

<template>
  <Teleport to="head">
    <title>{{ pageTitle }}</title>
  </Teleport>

  <main class="auth--admin">
    <div class="auth-bar">
      <RouterLink class="brand" to="/" :aria-label="tr('auth.brand')">
        <svg class="mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" transform="rotate(-40 16 17)" />
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" transform="rotate(-20 16 17)" />
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" />
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" transform="rotate(20 16 17)" />
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" transform="rotate(40 16 17)" />
        </svg>
        <span class="wordmark"><b>Shin Joy</b><span>Spa &middot; Malang</span></span>
      </RouterLink>
      <div class="lang-toggle" role="group" :aria-label="tr('auth.language')">
        <button type="button" :aria-pressed="language === 'id'" @click="setLanguage('id')">ID</button>
        <button type="button" :aria-pressed="language === 'en'" @click="setLanguage('en')">EN</button>
      </div>
    </div>

    <div class="auth-card">
      <span class="auth-badge">{{ tr('auth.admin.badge') }}</span>
      <h1 id="admin-login-title">{{ tr('auth.admin.title') }}</h1>
      <p class="auth-sub">{{ tr('auth.admin.sub') }}</p>

      <div v-if="error" class="auth-notice" style="background:var(--rose-soft);color:var(--rose-deep)">{{ error }}</div>

      <form class="auth-form" @submit.prevent="handleLogin">
        <div class="field">
          <label for="admin-username">{{ tr('auth.f.username') }}</label>
          <input
            id="admin-username"
            v-model="username"
            type="text"
            autocomplete="username"
            required
            placeholder="admin"
          />
        </div>

        <div class="field">
          <label for="admin-password">{{ tr('auth.f.password') }}</label>
          <div class="input-wrap">
            <input
              id="admin-password"
              v-model="password"
              :type="passwordVisible ? 'text' : 'password'"
              autocomplete="current-password"
              required
              placeholder="••••••••"
            />
            <button
              type="button"
              class="pw-toggle"
              :aria-label="passwordVisible ? tr('auth.hidePassword') : tr('auth.showPassword')"
              :aria-pressed="passwordVisible"
              @click="passwordVisible = !passwordVisible"
            >
              <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
              <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                <line x1="2" y1="2" x2="22" y2="22" />
              </svg>
            </button>
          </div>
        </div>

        <button
          type="submit"
          class="btn btn--primary btn--block btn--lg"
          :disabled="isSubmitting"
        >
          {{ isSubmitting ? tr('auth.login.pending') : tr('auth.admin.submit') }}
        </button>
      </form>

      <p class="auth-alt" style="margin-top: 1.4rem;">
        <RouterLink class="auth-back" to="/">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="M19 12H5M11 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span>{{ tr('auth.backsite') }}</span>
        </RouterLink>
      </p>
    </div>
    <div></div>
  </main>
</template>
