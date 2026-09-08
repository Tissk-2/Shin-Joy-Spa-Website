<script setup lang="ts">
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";

import { useLanguage } from "@/composables/useLanguage";
import type { AppPageProps } from "@/types";

const props = defineProps<{
  title: string;
  subtitle?: string;
}>();

const page = usePage<AppPageProps>();
const { language, setLanguage, tr } = useLanguage();
const loggingOut = ref(false);
const currentUser = computed(() => page.props.auth.user);

function logout(): void {
  if (loggingOut.value) return;

  loggingOut.value = true;
  router.post(
    "/logout",
    {},
    {
      onFinish: () => {
        loggingOut.value = false;
      },
    },
  );
}
</script>

<template>
  <Head :title="props.title" />

  <div
    class="min-h-screen bg-[#f8f1ed] text-[#34252d] lg:grid lg:grid-cols-[18rem_minmax(0,1fr)]"
  >
    <a
      href="#main"
      class="sr-only z-[100] flex min-h-11 items-center rounded-md bg-white px-4 py-2 font-semibold text-[#5d2845] shadow-lg focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#d9a75d]"
    >
      {{ tr("ad.skip") }}
    </a>

    <aside
      class="border-b border-white/15 bg-[#52233f] text-[#fff9f4] lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col lg:border-b-0 lg:border-r"
    >
      <div
        class="flex items-center justify-between gap-4 px-5 py-5 lg:block lg:px-7 lg:py-8"
      >
        <Link
          href="/admin/bookings"
          class="flex min-h-11 items-center gap-3 rounded-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f2c477]"
        >
          <span
            class="grid h-11 w-11 place-items-center rounded-full border border-[#f2c477]/70 bg-white/10 font-serif text-xl text-[#f2c477]"
            aria-hidden="true"
          >
            SJ
          </span>
          <span>
            <span class="block font-serif text-xl leading-tight">Shin Joy</span>
            <span
              class="block text-xs uppercase tracking-[0.18em] text-[#efd9e5]"
            >
              {{ tr("ad.panel") }}
            </span>
          </span>
        </Link>

        <div
          class="flex rounded-full border border-white/20 bg-black/10 p-1 lg:mt-7 lg:w-full"
          role="group"
          :aria-label="tr('auth.language')"
        >
          <button
            v-for="locale in ['id', 'en'] as const"
            :key="locale"
            type="button"
            class="min-h-11 min-w-11 flex-1 rounded-full px-3 text-xs font-bold uppercase tracking-[0.12em] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#f2c477]"
            :class="
              language === locale
                ? 'bg-[#fff9f4] text-[#52233f]'
                : 'text-[#efd9e5] hover:bg-white/10'
            "
            :aria-pressed="language === locale"
            :data-admin-locale="locale"
            @click="setLanguage(locale)"
          >
            {{ locale }}
          </button>
        </div>
      </div>

      <nav class="px-4 pb-5 lg:px-5" :aria-label="tr('ad.panel')">
        <Link
          href="/admin/bookings"
          class="flex min-h-11 items-center rounded-lg border border-[#f2c477]/45 bg-white/10 px-4 py-3 font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f2c477]"
          aria-current="page"
        >
          {{ tr("ad.nav.bookings") }}
        </Link>
        <Link
          href="/"
          class="mt-2 flex min-h-11 items-center rounded-lg px-4 py-3 text-[#efd9e5] hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f2c477]"
        >
          {{ tr("ad.viewsite") }}
        </Link>
      </nav>

      <div
        data-admin-identity
        class="border-t border-white/15 px-5 py-5 lg:mt-auto lg:px-7"
      >
        <p class="truncate font-semibold text-white">
          {{ currentUser?.name }}
        </p>
        <p class="mt-1 truncate text-sm text-[#efd9e5]">
          {{ currentUser?.email }}
        </p>
        <button
          type="button"
          data-admin-logout
          class="mt-4 flex min-h-11 w-full items-center justify-center rounded-lg border border-white/25 px-4 py-2 text-sm font-semibold text-white hover:border-[#f2c477] hover:bg-white/10 disabled:cursor-wait disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f2c477]"
          :disabled="loggingOut"
          :aria-busy="loggingOut"
          @click="logout"
        >
          {{ tr("ad.logout") }}
        </button>
      </div>
    </aside>

    <div class="min-w-0">
      <header
        class="border-b border-[#decfc8] bg-[#fffaf6]/90 px-5 py-6 sm:px-8 lg:px-10 lg:py-8"
      >
        <p
          class="mb-2 text-xs font-bold uppercase tracking-[0.2em] text-[#9c614c]"
        >
          {{ tr("ad.panel") }}
        </p>
        <h1
          class="font-serif text-3xl leading-tight text-[#52233f] sm:text-4xl"
        >
          {{ props.title }}
        </h1>
        <p
          v-if="props.subtitle"
          class="mt-2 max-w-3xl text-sm leading-6 text-[#725d65] sm:text-base"
        >
          {{ props.subtitle }}
        </p>
      </header>

      <main id="main" tabindex="-1" class="px-5 py-6 sm:px-8 lg:px-10 lg:py-9">
        <slot />
      </main>
    </div>
  </div>
</template>
