<script setup lang="ts">
import AppLogo from "@/components/AppLogo.vue";
import { SidebarTrigger } from "@/components/ui/sidebar";
import { useLanguage } from "@/composables/useLanguage";
import type { AppPageProps, BreadcrumbItem } from "@/types";
import { Link, router, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";

withDefaults(
  defineProps<{ breadcrumbs?: BreadcrumbItem[]; sidebar?: boolean }>(),
  {
    breadcrumbs: () => [],
    sidebar: false,
  },
);
const page = usePage<AppPageProps>();
const user = computed(() => page.props.auth.user);
const { language, setLanguage, tr } = useLanguage();
const loggingOut = ref(false);
const isActive = (href: string) => page.url.split("?")[0] === href;
function logout() {
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
  <header class="account-topbar py-3">
    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
      <div class="flex min-w-0 items-center gap-3">
        <SidebarTrigger v-if="sidebar" class="h-11 w-11 shrink-0" />
        <Link
          v-else
          href="/"
          class="flex min-h-11 items-center gap-2"
          aria-label="Shin Joy Spa"
          ><AppLogo
        /></Link>
        <nav
          v-if="breadcrumbs.length"
          :aria-label="tr('nav.breadcrumb')"
          class="min-w-0"
        >
          <ol class="flex flex-wrap items-center gap-2 text-sm">
            <li
              v-for="(item, index) in breadcrumbs"
              :key="item.href"
              class="min-w-0 break-words"
            >
              <span
                v-if="index"
                aria-hidden="true"
                class="mr-2 text-muted-foreground"
                >/</span
              >
              <span
                v-if="index === breadcrumbs.length - 1"
                aria-current="page"
                >{{ item.title }}</span
              >
              <Link
                v-else
                :href="item.href"
                class="underline underline-offset-4"
                >{{ item.title }}</Link
              >
            </li>
          </ol>
        </nav>
      </div>
      <div class="flex max-w-full flex-wrap items-center gap-3">
        <Link
          v-if="user"
          href="/settings/profile"
          class="min-w-0 max-w-48 truncate py-3 font-semibold"
          >{{ user.name }}</Link
        >
        <div
          role="group"
          :aria-label="tr('auth.language')"
          class="flex rounded-md border border-border p-1"
        >
          <button
            v-for="locale in ['id', 'en'] as const"
            :key="locale"
            type="button"
            class="min-h-11 min-w-11 rounded px-2 text-xs font-semibold uppercase"
            :class="
              language === locale
                ? 'bg-primary text-primary-foreground'
                : 'text-muted-foreground hover:bg-accent'
            "
            :aria-pressed="language === locale"
            @click="setLanguage(locale)"
          >
            {{ locale }}
          </button>
        </div>
        <button
          v-if="user"
          type="button"
          class="min-h-11 rounded-md border border-border px-3 text-sm font-semibold hover:bg-accent disabled:opacity-60"
          :disabled="loggingOut"
          :aria-busy="loggingOut"
          @click="logout"
        >
          {{ tr("nav.logout") }}
        </button>
        <Link v-else href="/login" class="py-3 underline underline-offset-4">{{
          tr("nav.login")
        }}</Link>
      </div>
    </div>
    <nav
      v-if="!sidebar"
      class="account-header-nav mt-3"
      :aria-label="tr('nav.primary')"
    >
      <Link
        href="/bookings"
        :aria-current="isActive('/bookings') ? 'page' : undefined"
        >{{ tr("nav.bookings") }}</Link
      >
      <Link
        href="/services"
        :aria-current="isActive('/services') ? 'page' : undefined"
        >{{ tr("nav.services") }}</Link
      >
      <Link
        href="/settings/profile"
        :aria-current="isActive('/settings/profile') ? 'page' : undefined"
        >{{ tr("nav.profile") }}</Link
      >
      <Link v-if="page.props.auth.canAccessAdmin" href="/admin/bookings">{{
        tr("ad.panel")
      }}</Link>
    </nav>
  </header>
</template>
