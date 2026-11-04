<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type { AppPageProps } from "@/types";

interface DashboardBooking {
  reference: string;
  customerName: string;
  packageNameId: string;
  packageNameEn: string;
  date: string;
  time: string;
  status: string;
  totalPrice: number;
}

interface CategoryStat {
  slug: string;
  nameId: string;
  nameEn: string;
  count: number;
}

interface DashboardStats {
  services: number;
  categories: number;
  bookingsTotal: number;
  bookingsPending: number;
  bookingsToday: number;
  bookingsWeek: number;
  revenue: number;
  revenueBookings: number;
}

const props = defineProps<{
  stats: DashboardStats;
  upcoming: DashboardBooking[];
  recent: DashboardBooking[];
  categoryStats: CategoryStat[];
}>();

const page = usePage<AppPageProps>();
const { language, tr } = useLanguage();

const userName = computed(() => page.props.auth.user?.name ?? "");
const localeTag = computed(() => (language.value === "id" ? "id-ID" : "en-GB"));

const todayLabel = computed(() =>
  new Intl.DateTimeFormat(localeTag.value, {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(new Date()),
);

function t(key: string, replacements: Record<string, string | number>): string {
  let value = tr(key);
  for (const [name, replacement] of Object.entries(replacements)) {
    value = value.replace(`{${name}}`, String(replacement));
  }
  return value;
}

function rupiah(value: number): string {
  return `Rp ${new Intl.NumberFormat("id-ID").format(value)}`;
}

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(localeTag.value, {
    day: "numeric",
    month: "short",
  }).format(new Date(`${iso}T00:00:00`));
}

function packageName(booking: DashboardBooking): string {
  return language.value === "id"
    ? booking.packageNameId
    : booking.packageNameEn;
}

function categoryName(category: CategoryStat): string {
  return language.value === "id" ? category.nameId : category.nameEn;
}

function statusLabel(status: string): string {
  return tr(`ad.status.${status}`);
}

const maxCategoryCount = computed(() =>
  Math.max(1, ...props.categoryStats.map((category) => category.count)),
);
</script>

<template>
  <AdminLayout :title="tr('ad.dash.title')" :subtitle="tr('ad.dash.subtitle')">
    <div class="admin-content">
      <section class="admin-dash-welcome" aria-labelledby="dash-welcome">
        <h2 id="dash-welcome">{{ tr("ad.dash.welcome") }}, {{ userName }}</h2>
        <p>{{ todayLabel }}</p>
      </section>

      <dl class="admin-dash-stats">
        <div class="admin-count admin-dash-stat">
          <dt>{{ tr("ad.dash.totalServices") }}</dt>
          <dd>
            <span class="admin-dash-value">{{ props.stats.services }}</span>
            <span class="admin-dash-note">{{
              t("ad.dash.inCategories", { n: props.stats.categories })
            }}</span>
          </dd>
        </div>
        <div class="admin-count admin-dash-stat">
          <dt>{{ tr("ad.dash.totalBookings") }}</dt>
          <dd>
            <span class="admin-dash-value">{{
              props.stats.bookingsTotal
            }}</span>
            <span class="admin-dash-note">{{
              t("ad.dash.pendingNote", { n: props.stats.bookingsPending })
            }}</span>
          </dd>
        </div>
        <div class="admin-count admin-dash-stat">
          <dt>{{ tr("ad.dash.todayBookings") }}</dt>
          <dd>
            <span class="admin-dash-value">{{
              props.stats.bookingsToday
            }}</span>
            <span class="admin-dash-note">{{
              t("ad.dash.todayNote", { n: props.stats.bookingsWeek })
            }}</span>
          </dd>
        </div>
        <div class="admin-count admin-dash-stat">
          <dt>{{ tr("ad.dash.revenue") }}</dt>
          <dd>
            <span class="admin-dash-value">{{
              rupiah(props.stats.revenue)
            }}</span>
            <span class="admin-dash-note">{{
              t("ad.dash.revenueNote", { n: props.stats.revenueBookings })
            }}</span>
          </dd>
        </div>
      </dl>

      <div class="admin-dash-grid">
        <section
          class="admin-dash-panel"
          aria-labelledby="dash-upcoming"
          data-dash-panel="upcoming"
        >
          <header class="admin-dash-panel__head">
            <div>
              <h2 id="dash-upcoming">{{ tr("ad.dash.upcoming") }}</h2>
              <span>{{ tr("ad.dash.upcomingRange") }}</span>
            </div>
            <Link class="admin-dash-panel__link" href="/admin/bookings">
              {{ tr("ad.dash.viewBookings") }}
            </Link>
          </header>
          <ul v-if="props.upcoming.length" class="admin-dash-list">
            <li v-for="booking in props.upcoming" :key="booking.reference">
              <span class="admin-dash-when">
                <b>{{ formatDate(booking.date) }}</b>
                <span>{{ booking.time }}</span>
              </span>
              <span class="admin-dash-who">
                <b>{{ booking.customerName }}</b>
                <span>{{ packageName(booking) }}</span>
              </span>
              <span
                class="admin-status"
                :class="`admin-status--${booking.status}`"
                >{{ statusLabel(booking.status) }}</span
              >
            </li>
          </ul>
          <p v-else class="admin-empty" data-upcoming-empty>
            {{ tr("ad.dash.upcomingEmpty") }}
          </p>
        </section>

        <section
          class="admin-dash-panel"
          aria-labelledby="dash-categories"
          data-dash-panel="categories"
        >
          <header class="admin-dash-panel__head">
            <div>
              <h2 id="dash-categories">{{ tr("ad.dash.byCategory") }}</h2>
              <span>{{
                t("ad.dash.byCategoryNote", { n: props.stats.services })
              }}</span>
            </div>
          </header>
          <ul v-if="props.categoryStats.length" class="admin-dash-bars">
            <li
              v-for="category in props.categoryStats"
              :key="category.slug"
              data-category-stat
            >
              <span class="admin-dash-bar__label">
                <span>{{ categoryName(category) }}</span>
                <b>{{ category.count }}</b>
              </span>
              <span class="admin-dash-bar__track" aria-hidden="true">
                <span
                  class="admin-dash-bar__fill"
                  :style="{
                    width: `${Math.round(
                      (category.count / maxCategoryCount) * 100,
                    )}%`,
                  }"
                ></span>
              </span>
            </li>
          </ul>
          <p v-else class="admin-empty" data-categories-empty>
            {{ tr("ad.dash.byCategoryEmpty") }}
          </p>
        </section>
      </div>

      <section
        class="admin-dash-panel"
        aria-labelledby="dash-recent"
        data-dash-panel="recent"
      >
        <header class="admin-dash-panel__head">
          <div>
            <h2 id="dash-recent">{{ tr("ad.dash.recent") }}</h2>
          </div>
          <Link class="admin-dash-panel__link" href="/admin/bookings">
            {{ tr("ad.dash.viewBookings") }}
          </Link>
        </header>
        <ul v-if="props.recent.length" class="admin-dash-list">
          <li v-for="booking in props.recent" :key="booking.reference">
            <span class="admin-dash-when">
              <b>{{ booking.reference }}</b>
              <span>{{ formatDate(booking.date) }} · {{ booking.time }}</span>
            </span>
            <span class="admin-dash-who">
              <b>{{ booking.customerName }}</b>
              <span>{{ packageName(booking) }}</span>
            </span>
            <span
              class="admin-status"
              :class="`admin-status--${booking.status}`"
              >{{ statusLabel(booking.status) }}</span
            >
          </li>
        </ul>
        <p v-else class="admin-empty" data-recent-empty>
          {{ tr("ad.dash.recentEmpty") }}
        </p>
      </section>
    </div>
  </AdminLayout>
</template>
