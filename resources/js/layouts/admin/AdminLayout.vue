<script setup lang="ts">
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import {
  BookOpen,
  CalendarDays,
  DoorOpen,
  LayoutDashboard,
  Settings,
  Users,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import type { AppPageProps } from "@/types";

const props = defineProps<{ title: string; subtitle?: string }>();
const page = usePage<AppPageProps>();
const { language, setLanguage, tr } = useLanguage();
const loggingOut = ref(false);
const currentUser = computed(() => page.props.auth.user);
const accountInitial = computed(() =>
  (currentUser.value?.name ?? "?").trim().charAt(0).toUpperCase(),
);

function isActiveAdminLink(href: string): boolean {
  return (
    page.url === href ||
    page.url.startsWith(`${href}?`) ||
    page.url.startsWith(`${href}/`)
  );
}

// The dashboard lives at the parent path, so it only counts when it is the
// current path itself, never as a prefix of every other admin page.
const isActiveDashboard = computed(
  () => page.url === "/admin" || page.url.startsWith("/admin?"),
);

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
  <div class="admin-shell admin-frame">
    <a href="#main" class="admin-skip">{{ tr("ad.skip") }}</a>
    <aside class="admin-sidebar">
      <div class="admin-brand-row">
        <Link href="/admin" class="admin-brand">
          <span class="admin-brand-mark" aria-hidden="true">SJ</span>
          <span>
            <span class="admin-brand-name">Shin Joy</span>
            <span class="admin-brand-caption">{{ tr("ad.panel") }}</span>
          </span>
        </Link>
      </div>
      <nav class="admin-navigation" :aria-label="tr('ad.panel')">
        <Link
          v-if="page.props.auth.isOwner"
          href="/admin/staff"
          class="admin-nav-link"
          :aria-current="isActiveAdminLink('/admin/staff') ? 'page' : undefined"
        >
          <Users aria-hidden="true" />{{ tr("ad.staff.title") }}
        </Link>
        <Link
          v-if="page.props.auth.isOwner"
          href="/admin/settings/booking"
          class="admin-nav-link"
          :aria-current="
            isActiveAdminLink('/admin/settings/booking') ? 'page' : undefined
          "
        >
          <Settings aria-hidden="true" />{{ tr("ad.settings.title") }}
        </Link>
        <Link
          href="/admin"
          class="admin-nav-link"
          :aria-current="isActiveDashboard ? 'page' : undefined"
        >
          <LayoutDashboard aria-hidden="true" />{{ tr("ad.nav.dashboard") }}
        </Link>
        <Link
          href="/admin/catalog"
          class="admin-nav-link"
          :aria-current="
            isActiveAdminLink('/admin/catalog') ? 'page' : undefined
          "
        >
          <BookOpen aria-hidden="true" />{{ tr("ad.nav.catalog") }}
        </Link>
        <Link
          href="/admin/bookings"
          class="admin-nav-link"
          :aria-current="
            isActiveAdminLink('/admin/bookings') ? 'page' : undefined
          "
        >
          <CalendarDays aria-hidden="true" />{{ tr("ad.nav.bookings") }}
        </Link>
        <Link
          href="/admin/rooms"
          class="admin-nav-link"
          :aria-current="isActiveAdminLink('/admin/rooms') ? 'page' : undefined"
        >
          <DoorOpen aria-hidden="true" />{{ tr("ad.nav.rooms") }}
        </Link>
      </nav>
      <div class="admin-sidebar-footer">
        <div data-admin-identity class="admin-identity">
          <div>
            <p class="font-semibold">{{ currentUser?.name }}</p>
            <p>{{ currentUser?.email }}</p>
          </div>
        </div>
        <div
          class="admin-language"
          role="group"
          :aria-label="tr('auth.language')"
        >
          <button
            v-for="locale in ['id', 'en'] as const"
            :key="locale"
            type="button"
            :aria-pressed="language === locale"
            :data-admin-locale="locale"
            @click="setLanguage(locale)"
          >
            {{ locale }}
          </button>
        </div>
        <button
          type="button"
          data-admin-logout
          class="admin-button admin-logout"
          :disabled="loggingOut"
          :aria-busy="loggingOut"
          @click="logout"
        >
          {{ tr("ad.logout") }}
        </button>
      </div>
    </aside>
    <div class="admin-frame__body">
      <header class="admin-topbar admin-page-heading">
        <div class="admin-page-heading__text">
          <p>{{ tr("ad.panel") }}</p>
          <h1>{{ props.title }}</h1>
          <p v-if="props.subtitle">{{ props.subtitle }}</p>
        </div>
        <div class="admin-topbar__actions">
          <a href="/" class="admin-topbar__site">
            {{ tr("ad.viewsite") }}
          </a>
          <span class="admin-topbar__user">
            <span class="admin-topbar__avatar" aria-hidden="true">{{
              accountInitial
            }}</span>
            <span class="admin-topbar__name">{{ currentUser?.name }}</span>
          </span>
        </div>
      </header>
      <main id="main" tabindex="-1" class="admin-main"><slot /></main>
    </div>
  </div>
</template>
