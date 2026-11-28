<script setup lang="ts">
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import {
  BookOpen,
  CalendarClock,
  CalendarDays,
  CircleHelp,
  DoorOpen,
  FileText,
  LayoutDashboard,
  Menu,
  Settings,
  Users,
  X,
} from "lucide-vue-next";
import { computed, nextTick, ref, watch, type Component } from "vue";
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

// Below the desktop breakpoint the sidebar becomes a drawer opened from the
// bottom bar. Keep in step with the 1024px breakpoint in admin.css.
const isMobile = useMediaQuery("(max-width: 1023px)");
const menuOpen = ref(false);
const menuToggle = ref<HTMLButtonElement | null>(null);
const sidebar = ref<HTMLElement | null>(null);
const drawerOpen = computed(() => isMobile.value && menuOpen.value);

interface NavItem {
  href: string;
  label: string;
  icon: Component;
  /** Requests waiting for an answer, shown on Bookings. */
  badge?: number;
}

const waiting = computed(() => page.props.adminNav?.pendingBookings ?? 0);

const navGroups = computed(() => {
  const groups: Array<{ id: string; label: string; items: NavItem[] }> = [
    {
      id: "daily",
      label: tr("ad.nav.groupDaily"),
      items: [
        {
          href: "/admin",
          label: tr("ad.nav.dashboard"),
          icon: LayoutDashboard,
        },
        {
          href: "/admin/bookings",
          label: tr("ad.nav.bookings"),
          icon: CalendarDays,
          badge: waiting.value,
        },
        {
          href: "/admin/schedule",
          label: tr("ad.nav.schedule"),
          icon: CalendarClock,
        },
      ],
    },
    {
      id: "manage",
      label: tr("ad.nav.groupManage"),
      items: [
        { href: "/admin/rooms", label: tr("ad.nav.rooms"), icon: DoorOpen },
        { href: "/admin/catalog", label: tr("ad.nav.catalog"), icon: BookOpen },
        { href: "/admin/content", label: tr("ad.nav.content"), icon: FileText },
        { href: "/admin/guide", label: tr("ad.nav.guide"), icon: CircleHelp },
      ],
    },
  ];
  if (page.props.auth.isOwner) {
    groups.push({
      id: "owner",
      label: tr("ad.nav.groupOwner"),
      items: [
        { href: "/admin/staff", label: tr("ad.staff.title"), icon: Users },
        {
          href: "/admin/settings/booking",
          label: tr("ad.settings.title"),
          icon: Settings,
        },
      ],
    });
  }
  return groups;
});

const quickItems = computed(() => navGroups.value[0].items);

function isCurrent(href: string): boolean {
  // The dashboard lives at the parent path, so it only counts when it is the
  // current path itself, never as a prefix of every other admin page.
  if (href === "/admin") {
    return page.url === "/admin" || page.url.startsWith("/admin?");
  }
  return (
    page.url === href ||
    page.url.startsWith(`${href}?`) ||
    page.url.startsWith(`${href}/`)
  );
}

function openMenu(): void {
  menuOpen.value = true;
  nextTick(() =>
    sidebar.value?.querySelector<HTMLElement>("a, button")?.focus(),
  );
}

function closeMenu(restoreFocus = true): void {
  if (!menuOpen.value) return;
  menuOpen.value = false;
  if (restoreFocus) nextTick(() => menuToggle.value?.focus());
}

function onSidebarKeydown(event: KeyboardEvent): void {
  if (event.key === "Escape" && drawerOpen.value) {
    event.preventDefault();
    closeMenu();
  }
}

watch(
  () => page.url,
  () => closeMenu(false),
);
watch(isMobile, (mobile) => {
  if (!mobile) menuOpen.value = false;
});

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
  <Head :title="props.title">
    <!-- Same description and head-key as MainLayout and AuthLayout. -->
    <meta
      head-key="description"
      name="description"
      :content="tr('meta.tagline')"
    />
  </Head>
  <div
    class="admin-shell admin-frame"
    :class="{ 'is-drawer-open': drawerOpen }"
  >
    <a href="#main" class="admin-skip">{{ tr("ad.skip") }}</a>

    <div
      v-if="isMobile"
      class="admin-mobilebar"
      :inert="drawerOpen || undefined"
    >
      <Link href="/admin" class="admin-brand admin-brand--compact">
        <span class="admin-brand-mark" aria-hidden="true">SJ</span>
        <span class="admin-brand-name">Shin Joy</span>
      </Link>
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
    </div>

    <div
      v-if="drawerOpen"
      class="admin-backdrop"
      aria-hidden="true"
      @click="closeMenu()"
    />

    <aside
      id="admin-sidebar"
      ref="sidebar"
      class="admin-sidebar"
      :inert="(isMobile && !menuOpen) || undefined"
      @keydown="onSidebarKeydown"
    >
      <div class="admin-brand-row">
        <Link href="/admin" class="admin-brand">
          <span class="admin-brand-mark" aria-hidden="true">SJ</span>
          <span>
            <span class="admin-brand-name">Shin Joy</span>
            <span class="admin-brand-caption">{{ tr("ad.panel") }}</span>
          </span>
        </Link>
        <button
          v-if="isMobile"
          type="button"
          class="admin-icon-button"
          :aria-label="tr('ad.nav.close')"
          @click="closeMenu()"
        >
          <X aria-hidden="true" />
        </button>
      </div>

      <nav class="admin-navigation" :aria-label="tr('ad.panel')">
        <div v-for="group in navGroups" :key="group.id" class="admin-nav-group">
          <p :id="`admin-nav-${group.id}`" class="admin-nav-group__label">
            {{ group.label }}
          </p>
          <ul :aria-labelledby="`admin-nav-${group.id}`">
            <li v-for="item in group.items" :key="item.href">
              <Link
                :href="item.href"
                class="admin-nav-link"
                :aria-current="isCurrent(item.href) ? 'page' : undefined"
              >
                <component :is="item.icon" aria-hidden="true" />{{ item.label
                }}<template v-if="item.badge"
                  ><span class="admin-nav-badge" aria-hidden="true">{{
                    item.badge
                  }}</span
                  ><span class="sr-only"
                    >, {{ tr("ad.nav.waiting", { n: item.badge }) }}</span
                  ></template
                >
              </Link>
            </li>
          </ul>
        </div>
      </nav>

      <div class="admin-sidebar-footer">
        <div data-admin-identity class="admin-identity">
          <span class="admin-avatar" aria-hidden="true">{{
            accountInitial
          }}</span>
          <div>
            <p class="admin-identity__name">{{ currentUser?.name }}</p>
            <p class="admin-identity__email">{{ currentUser?.email }}</p>
          </div>
        </div>
        <div
          v-if="!isMobile"
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
        <div class="admin-account-actions">
          <a href="/" class="admin-button">{{ tr("ad.viewsite") }}</a>
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
      </div>
    </aside>

    <div class="admin-frame__body" :inert="drawerOpen || undefined">
      <header class="admin-page-heading">
        <div class="admin-page-heading__text">
          <h1>{{ props.title }}</h1>
          <p v-if="props.subtitle">{{ props.subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="admin-page-heading__actions">
          <slot name="actions" />
        </div>
      </header>
      <main id="main" tabindex="-1" class="admin-main"><slot /></main>
    </div>

    <nav
      v-if="isMobile"
      class="admin-tabbar"
      :aria-label="tr('ad.nav.quick')"
      :inert="drawerOpen || undefined"
    >
      <Link
        v-for="item in quickItems"
        :key="item.href"
        :href="item.href"
        class="admin-tabbar__item"
        :aria-current="isCurrent(item.href) ? 'page' : undefined"
      >
        <span class="admin-tabbar__icon">
          <component :is="item.icon" aria-hidden="true" /><span
            v-if="item.badge"
            class="admin-nav-badge admin-nav-badge--dot"
            aria-hidden="true"
            >{{ item.badge }}</span
          >
        </span>
        <span>{{ item.label }}</span
        ><span v-if="item.badge" class="sr-only"
          >, {{ tr("ad.nav.waiting", { n: item.badge }) }}</span
        >
      </Link>
      <button
        ref="menuToggle"
        type="button"
        class="admin-tabbar__item"
        aria-controls="admin-sidebar"
        :aria-expanded="menuOpen"
        @click="menuOpen ? closeMenu() : openMenu()"
      >
        <Menu aria-hidden="true" />
        <span>{{ tr("ad.nav.menu") }}</span>
      </button>
    </nav>
  </div>
</template>
