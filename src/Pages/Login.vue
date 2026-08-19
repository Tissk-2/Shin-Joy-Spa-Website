<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { sanitizeRedirect, validateLogin } from '../auth.js';
import { loginUser } from '../useAuth.js';
import { language, setLanguage, tr } from '../useLanguage.js';

const route = useRoute();
const router = useRouter();
const email = ref('');
const password = ref('');
const fieldErrors = ref({});
const submitError = ref('');
const isSubmitting = ref(false);
const passwordVisible = ref(false);
const safeRoutes = ['/', '/services', '/booking', '/about', '/profile'];
const pageTitle = computed(() => `${tr('auth.login.title')} · ${tr('auth.brand')}`);

function errorId(field) {
  return `login-${field}-error`;
}

function clearFieldError(field) {
  if (!fieldErrors.value[field]) return;
  const nextErrors = { ...fieldErrors.value };
  delete nextErrors[field];
  fieldErrors.value = nextErrors;
}

async function submit() {
  if (isSubmitting.value) return;

  submitError.value = '';
  const credentials = { email: email.value, password: password.value };
  const errors = validateLogin(credentials);
  fieldErrors.value = errors;
  if (Object.keys(errors).length > 0) return;

  isSubmitting.value = true;
  try {
    const result = await loginUser(credentials);
    if (result.ok) {
      const destination = sanitizeRedirect(route.query.redirect, safeRoutes);
      router.replace(destination);
      return;
    }

    if (result.code === 'validation') {
      fieldErrors.value = result.fields ?? {};
    } else if (result.code === 'invalid-credentials') {
      submitError.value = 'auth.error.credentials';
    } else if (result.code === 'storage-unavailable') {
      submitError.value = 'auth.error.storage';
    } else {
      submitError.value = 'auth.error.generic';
    }
  } finally {
    isSubmitting.value = false;
  }
}

onMounted(() => document.body.classList.add('auth-body'));
onUnmounted(() => document.body.classList.remove('auth-body'));
</script>

<template>
  <Teleport to="head">
    <title>{{ pageTitle }}</title>
  </Teleport>

  <main class="auth">
    <section class="auth__panel" aria-labelledby="login-title">
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
        <RouterLink class="auth-back" to="/">{{ tr('auth.back') }}</RouterLink>
        <h1 id="login-title">{{ tr('auth.login.title') }}</h1>
        <p class="auth-sub">{{ tr('auth.login.sub') }}</p>

        <form class="auth-form" novalidate @submit.prevent="submit">
          <p class="auth-error" role="status" aria-live="polite" aria-atomic="true" :class="{ 'is-visible': submitError }">{{ submitError ? tr(submitError) : '' }}</p>

          <div class="field" :class="{ invalid: fieldErrors.email }">
            <label for="login-email">{{ tr('auth.email') }}</label>
            <input id="login-email" v-model="email" name="email" type="email" autocomplete="email" inputmode="email" :aria-describedby="fieldErrors.email ? errorId('email') : undefined" :aria-invalid="fieldErrors.email ? 'true' : undefined" @input="clearFieldError('email')">
            <span v-if="fieldErrors.email" :id="errorId('email')" class="field-error">{{ tr(fieldErrors.email) }}</span>
          </div>

          <div class="field" :class="{ invalid: fieldErrors.password }">
            <label for="login-password">{{ tr('auth.password') }}</label>
            <div class="input-wrap">
              <input id="login-password" v-model="password" name="password" :type="passwordVisible ? 'text' : 'password'" autocomplete="current-password" :aria-describedby="fieldErrors.password ? errorId('password') : undefined" :aria-invalid="fieldErrors.password ? 'true' : undefined" @input="clearFieldError('password')">
              <button type="button" class="pw-toggle" :aria-label="passwordVisible ? tr('auth.hidePassword') : tr('auth.showPassword')" :aria-pressed="passwordVisible" @click="passwordVisible = !passwordVisible">
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
            <span v-if="fieldErrors.password" :id="errorId('password')" class="field-error">{{ tr(fieldErrors.password) }}</span>
          </div>


          <button class="btn btn--primary btn--block" type="submit" :disabled="isSubmitting">{{ isSubmitting ? tr('auth.login.pending') : tr('auth.login.submit') }}</button>
        </form>

        <p class="auth-alt">{{ tr('auth.newUser') }} <RouterLink to="/register">{{ tr('auth.goRegister') }}</RouterLink></p>
      </div>
    </section>

    <aside class="auth__aside">
      <img src="/img/stilllife.jpg" :alt="tr('auth.demo.asideAlt')">
      <div class="auth__aside-inner"><blockquote>{{ tr('auth.demo.asideQuote') }}</blockquote></div>
    </aside>
  </main>
</template>
