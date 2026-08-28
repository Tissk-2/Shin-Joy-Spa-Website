<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { onMounted, onUnmounted } from "vue";
import { useLanguage } from "@/composables/useLanguage";

defineProps<{
  title?: string;
  description?: string;
}>();

const { language, setLanguage, tr } = useLanguage();

onMounted(() => {
  document.body.classList.add("auth-body");
});

onUnmounted(() => {
  document.body.classList.remove("auth-body");
});
</script>

<template>
  <main class="auth">
    <section class="auth__panel" :aria-labelledby="'auth-title'">
      <div class="auth-bar">
        <Link class="auth-back" href="/">
          <span aria-hidden="true">‹</span>
          <span>{{ tr("auth.back") }}</span>
        </Link>

        <div class="lang-toggle" :aria-label="tr('auth.language')" role="group">
          <button
            type="button"
            :class="{ active: language === 'id' }"
            @click="setLanguage('id')"
          >
            ID
          </button>
          <button
            type="button"
            :class="{ active: language === 'en' }"
            @click="setLanguage('en')"
          >
            EN
          </button>
        </div>
      </div>

      <div class="auth-card">
        <Link class="brand" href="/" :aria-label="tr('auth.brand')">
          <svg class="mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
            <path
              class="mark-fill"
              d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z"
              transform="rotate(-40 16 17)"
            />
            <path
              class="mark-fill"
              d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z"
              transform="rotate(-20 16 17)"
            />
            <path
              class="mark-fill"
              d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z"
            />
            <path
              class="mark-fill"
              d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z"
              transform="rotate(20 16 17)"
            />
            <path
              class="mark-fill"
              d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z"
              transform="rotate(40 16 17)"
            />
          </svg>
          <span class="wordmark">
            <b>Shin Joy</b>
            <span>Spa &middot; Malang</span>
          </span>
        </Link>

        <div style="margin-top: 2rem">
          <h1 id="auth-title">{{ title }}</h1>
          <p class="auth-sub">{{ description }}</p>
        </div>

        <slot />
      </div>
    </section>

    <aside class="auth__aside" aria-hidden="true">
      <img src="/img/stilllife.jpg" :alt="tr('auth.demo.asideAlt')" />
      <div class="auth__aside-inner">
        <blockquote>{{ tr("auth.demo.asideQuote") }}</blockquote>
        <span class="auth-aside-brand">
          <span class="rule"></span>
          Shin Joy Spa
        </span>
      </div>
    </aside>
  </main>
</template>
