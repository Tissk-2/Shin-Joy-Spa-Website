<script setup lang="ts">
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import categories from "@/data/categories";
import { useLanguage } from "@/composables/useLanguage";
import {
  accountInitial as deriveAccountInitial,
  initialHeaderMenuState,
  transitionHeaderMenuState,
  type HeaderMenuEvent,
} from "@/lib/headerMenuState";
import type { AppPageProps } from "@/types";

const page = usePage<AppPageProps>();
const { language, setLanguage, tr } = useLanguage();
const headerMenuState = ref(initialHeaderMenuState);
const isMenuOpen = computed(() => headerMenuState.value.mobileMenuOpen);
const isAccountMenuOpen = computed(() => headerMenuState.value.accountMenuOpen);
const isScrolled = ref(false);
const navElement = ref<HTMLElement | null>(null);
const navToggle = ref<HTMLButtonElement | null>(null);
const accountMenu = ref<HTMLElement | null>(null);
const accountToggle = ref<HTMLButtonElement | null>(null);
const logoutError = ref(false);
const currentUser = computed(() => page.props.auth.user);
const isAuthenticated = computed(() => currentUser.value !== null);
const currentUrl = computed(() => {
  const [withoutHash, hash = ""] = page.url.split("#", 2);
  const path = withoutHash.split("?", 1)[0] || "/";

  return { path, hash: hash ? `#${hash}` : "" };
});
const isHome = computed(() => currentUrl.value.path === "/");
const accountInitial = computed(() =>
  deriveAccountInitial(currentUser.value?.name),
);
const accountLabel = computed(
  () => `${tr("nav.account")}: ${currentUser.value?.name?.trim() || "?"}`,
);
const activeSection = ref("");

const links = computed(() => {
  const hash = currentUrl.value.hash;
  const isHashMatch = (targetHash: string) =>
    isHome.value &&
    (activeSection.value === targetHash.substring(1) || hash === targetHash);
  return [
    {
      label: tr("nav.home"),
      to: "/",
      active:
        isHome.value &&
        (!hash || activeSection.value === "home" || activeSection.value === ""),
    },
    {
      label: tr("nav.services"),
      to: "/#services",
      active: isHashMatch("#services"),
    },
    { label: tr("nav.about"), to: "/#about", active: isHashMatch("#about") },
    {
      label: tr("nav.gallery"),
      to: "/#gallery",
      active: isHashMatch("#gallery"),
    },
    {
      label: tr("nav.contact"),
      to: "/#contact",
      active: isHashMatch("#contact"),
    },
  ];
});

function handleScroll() {
  isScrolled.value = window.scrollY > 32;

  if (!isHome.value) {
    activeSection.value = "";
    return;
  }

  const sections = [
    { id: "contact", el: document.getElementById("contact") },
    { id: "faq", el: document.getElementById("faq") },
    { id: "gallery", el: document.getElementById("gallery") },
    { id: "about", el: document.getElementById("about") },
    { id: "services", el: document.getElementById("services") },
  ];

  const scrollPosition = window.scrollY + 120;
  let current = "home";

  for (const { id, el } of sections) {
    if (el && scrollPosition >= el.offsetTop) {
      current = id;
      break;
    }
  }
  activeSection.value = current;
}

function transitionMenuState(event: HeaderMenuEvent): void {
  headerMenuState.value = transitionHeaderMenuState(
    headerMenuState.value,
    event,
    () => {
      nextTick(() => accountToggle.value?.focus());
    },
  );
}

function setMenu(open: boolean, restoreFocus = true): void {
  const wasOpen = isMenuOpen.value;
  transitionMenuState({
    type: "set-mobile-menu",
    open,
    restoreAccountFocus: false,
  });
  document.body.style.overflow = isMenuOpen.value ? "hidden" : "";
  if (isMenuOpen.value) {
    nextTick(() =>
      navElement.value
        ?.querySelector<HTMLElement>("a, button:not([disabled])")
        ?.focus(),
    );
  } else if (wasOpen && restoreFocus) {
    nextTick(() => navToggle.value?.focus());
  }
}

function setAccountMenu(open: boolean, restoreFocus = true): void {
  if (open) logoutError.value = false;
  transitionMenuState({
    type: "set-account-menu",
    open,
    restoreAccountFocus: restoreFocus,
  });
}

function handleOutsidePointer(event: PointerEvent): void {
  if (!isAccountMenuOpen.value) return;
  if (
    accountMenu.value?.contains(event.target as Node) ||
    accountToggle.value?.contains(event.target as Node)
  )
    return;
  transitionMenuState({ type: "outside-pointer" });
}

function handleLogout(): void {
  logoutError.value = false;
  router.post(
    "/logout",
    {},
    {
      onSuccess: () => {
        transitionMenuState({ type: "logout" });
        document.body.style.overflow = "";
      },
      onError: () => {
        logoutError.value = true;
      },
    },
  );
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.key === "Escape") {
    const wasMenuOpen = isMenuOpen.value;
    transitionMenuState({ type: "escape" });
    if (wasMenuOpen && !isMenuOpen.value) {
      nextTick(() => navToggle.value?.focus());
    }
    return;
  }

  if (event.key !== "Tab" || !isMenuOpen.value) return;

  const links = [
    ...(navElement.value?.querySelectorAll<HTMLElement>(
      "a[href], button:not([disabled])",
    ) ?? []),
  ];
  const first = links[0];
  const last = links.at(-1);
  const toggle = navToggle.value;
  const active = document.activeElement;

  if (!first || !last || !toggle) return;

  if (event.shiftKey && active === first) {
    event.preventDefault();
    toggle.focus();
  } else if (!event.shiftKey && active === last) {
    event.preventDefault();
    toggle.focus();
  } else if (event.shiftKey && active === toggle) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && active === toggle) {
    event.preventDefault();
    first.focus();
  }
}

watch(
  () => page.url,
  () => {
    transitionMenuState({ type: "route-change" });
    document.body.style.overflow = "";
  },
);

watch(
  language,
  (locale) => {
    document.documentElement.lang = locale;
  },
  { immediate: true },
);

onMounted(() => {
  window.addEventListener("scroll", handleScroll, { passive: true });
  document.addEventListener("keydown", handleKeydown);
  document.addEventListener("pointerdown", handleOutsidePointer);
  handleScroll();
});

onUnmounted(() => {
  window.removeEventListener("scroll", handleScroll);
  document.removeEventListener("keydown", handleKeydown);
  document.removeEventListener("pointerdown", handleOutsidePointer);
  document.body.style.overflow = "";
});
</script>

<template>
  <div>
    <Head>
      <meta
        head-key="description"
        name="description"
        :content="tr('meta.tagline')"
      />
    </Head>
    <a class="skip-link" href="#main">{{ tr("skip") }}</a>

    <header
      class="site-header"
      :class="{
        'site-header--overlay': isHome,
        scrolled: isScrolled || !isHome,
      }"
    >
      <div class="container bar">
        <Link
          class="brand"
          href="/"
          aria-label="Shin Joy Spa Malang"
          @click="setMenu(false, false)"
        >
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

        <nav
          id="primary-nav"
          ref="navElement"
          class="nav"
          :class="{ open: isMenuOpen }"
          :aria-label="tr('nav.primary')"
        >
          <ul class="nav-list">
            <li v-for="link in links" :key="link.to">
              <Link
                class="nav-link"
                :href="link.to"
                :aria-current="link.active ? 'page' : undefined"
                @click="setMenu(false, false)"
              >
                {{ link.label }}
              </Link>
            </li>
          </ul>

          <Link
            class="btn btn--primary"
            href="/booking"
            :aria-current="
              currentUrl.path.startsWith('/booking') ? 'page' : undefined
            "
            @click="setMenu(false, false)"
          >
            {{ tr("nav.book") }}
          </Link>

          <div v-if="!isAuthenticated" class="nav-auth-actions">
            <Link
              class="btn btn--ghost"
              href="/login"
              :aria-current="
                currentUrl.path === '/login' || currentUrl.path === '/register'
                  ? 'page'
                  : undefined
              "
              @click="setMenu(false, false)"
            >
              {{ tr("nav.loginRegister") }}
            </Link>
          </div>

          <div v-else class="account-menu-wrapper">
            <button
              ref="accountToggle"
              type="button"
              class="account-toggle"
              :aria-expanded="isAccountMenuOpen"
              aria-controls="account-menu"
              :aria-label="accountLabel"
              @click="setAccountMenu(!isAccountMenuOpen)"
            >
              <span aria-hidden="true">{{ accountInitial }}</span>
            </button>

            <div
              v-if="isAccountMenuOpen"
              id="account-menu"
              ref="accountMenu"
              class="account-menu"
            >
              <p class="account-menu__name">{{ currentUser?.name }}</p>
              <Link href="/profile" @click="setAccountMenu(false, false)">
                {{ tr("nav.profile") }}
              </Link>
              <button
                type="button"
                class="account-menu__logout"
                @click="handleLogout"
              >
                {{ tr("nav.logout") }}
              </button>
              <p
                v-if="logoutError"
                class="auth-notice account-menu__notice"
                role="status"
                aria-live="polite"
              >
                {{ tr("auth.error.storage") }}
              </p>
            </div>
          </div>
        </nav>

        <div class="header-actions">
          <div
            class="lang-toggle"
            role="group"
            :aria-label="tr('nav.language')"
          >
            <button
              type="button"
              :aria-pressed="language === 'id'"
              @click="setLanguage('id')"
            >
              ID
            </button>
            <button
              type="button"
              :aria-pressed="language === 'en'"
              @click="setLanguage('en')"
            >
              EN
            </button>
          </div>

          <button
            ref="navToggle"
            type="button"
            class="nav-toggle relative z-[400]"
            :aria-expanded="isMenuOpen"
            aria-controls="primary-nav"
            :aria-label="isMenuOpen ? tr('nav.close') : tr('nav.menu')"
            @click="setMenu(!isMenuOpen)"
          >
            <svg
              v-if="!isMenuOpen"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              aria-hidden="true"
            >
              <path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round" />
            </svg>
            <svg
              v-else
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              aria-hidden="true"
            >
              <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
            </svg>
          </button>
        </div>
      </div>

      <div
        class="nav-backdrop"
        :class="{ show: isMenuOpen }"
        aria-hidden="true"
        @click="setMenu(false)"
      ></div>
    </header>

    <main id="main">
      <slot />
    </main>

    <footer class="site-footer">
      <div class="container">
        <div class="footer-grid">
          <div class="footer-about">
            <Link class="brand" href="/" aria-label="Shin Joy Spa Malang">
              <svg
                class="mark"
                viewBox="0 0 32 32"
                fill="none"
                aria-hidden="true"
              >
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
            <p>{{ tr("foot.tagline") }}</p>
          </div>

          <div class="footer-col">
            <h4>{{ tr("foot.explore") }}</h4>
            <ul>
              <li>
                <Link href="/">{{ tr("nav.home") }}</Link>
              </li>
              <li>
                <Link href="/#services" @click="setMenu(false, false)">{{
                  tr("nav.services")
                }}</Link>
              </li>
              <li>
                <Link href="/#about" @click="setMenu(false, false)">{{
                  tr("nav.about")
                }}</Link>
              </li>
              <li>
                <Link href="/#gallery" @click="setMenu(false, false)">{{
                  tr("nav.gallery")
                }}</Link>
              </li>
              <li>
                <Link href="/#faq" @click="setMenu(false, false)">{{
                  tr("faq.eyebrow")
                }}</Link>
              </li>
            </ul>
          </div>

          <div class="footer-col">
            <h4>{{ tr("foot.services") }}</h4>
            <ul>
              <li v-for="category in categories" :key="category.slug">
                <Link
                  :href="`/#services?cat=${category.slug}`"
                  @click="setMenu(false, false)"
                >
                  {{ language === "id" ? category.nameId : category.nameEn }}
                </Link>
              </li>
            </ul>
          </div>

          <div class="footer-col">
            <h4>{{ tr("foot.contactt") }}</h4>
            <ul class="footer-contact">
              <li>
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  aria-hidden="true"
                >
                  <path
                    d="M12 21s7-5.5 7-11a7 7 0 10-14 0c0 5.5 7 11 7 11Z"
                    stroke-linejoin="round"
                  />
                  <circle cx="12" cy="10" r="2.5" />
                </svg>
                <span>{{ tr("loc.address") }}</span>
              </li>
              <li>
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  aria-hidden="true"
                >
                  <path
                    d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2Z"
                    stroke-linejoin="round"
                  />
                </svg>
                <span>{{ tr("loc.phone") }}</span>
              </li>
              <li>
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  aria-hidden="true"
                >
                  <circle cx="12" cy="12" r="9" />
                  <path d="M12 7v5l3 2" stroke-linecap="round" />
                </svg>
                <span>{{ tr("loc.hours") }}</span>
              </li>
            </ul>
          </div>
        </div>

        <div class="footer-bottom">
          <span
            >&copy; {{ new Date().getFullYear() }} {{ tr("foot.rights") }}</span
          >
          <span class="footer-meta">
            {{ tr("foot.made") }} &middot;
            <Link
              href="/admin-login"
              class="hover:underline opacity-80 hover:opacity-100"
              >{{ tr("foot.admin") }}</Link
            >
          </span>
        </div>
      </div>
    </footer>
  </div>
</template>
