<script setup lang="ts">
import { Link, router, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type {
  AdminBookingCounts,
  AdminBookingFilters,
  AdminBookingPagination,
  AdminBookingSummaryData,
  AppPageProps,
} from "@/types";

const props = defineProps<{
  counts: AdminBookingCounts;
  filters: AdminBookingFilters;
  bookings: AdminBookingPagination;
}>();

const { language, tr } = useLanguage();
const page = usePage<AppPageProps>();
const errors = computed(() => page.props.errors ?? {});
const filterForm = ref({
  ...props.filters,
  status: props.filters.status ?? "",
});
const loading = ref(false);
const scopes = ["upcoming", "past", "all"] as const;
const statuses = ["pending", "confirmed", "completed", "cancelled"] as const;
const countKeys = ["today", "pending", "confirmed", "completed"] as const;
const hasOptionalFilters = computed(() =>
  Boolean(
    props.filters.q.trim() ||
    props.filters.status ||
    props.filters.dateFrom ||
    props.filters.dateTo,
  ),
);
const canClear = computed(
  () =>
    hasOptionalFilters.value ||
    props.filters.scope !== "upcoming" ||
    Boolean(
      filterForm.value.q.trim() ||
      filterForm.value.status ||
      filterForm.value.dateFrom ||
      filterForm.value.dateTo ||
      filterForm.value.scope !== "upcoming",
    ),
);

watch(
  () => props.filters,
  (filters) => {
    filterForm.value = { ...filters, status: filters.status ?? "" };
  },
);

function visit(query: Record<string, string>): void {
  if (loading.value) return;
  loading.value = true;
  router.get("/admin/bookings", query, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onStart: () => {
      loading.value = true;
    },
    onFinish: () => {
      loading.value = false;
    },
  });
}

function submitFilters(): void {
  const query = Object.fromEntries(
    Object.entries({
      ...filterForm.value,
      q: filterForm.value.q.trim(),
    }).filter(
      (entry): entry is [string, string] =>
        entry[1] !== "" && entry[1] !== null,
    ),
  );
  visit(query);
}

function selectScope(scope: AdminBookingFilters["scope"]): void {
  if (loading.value) return;
  filterForm.value = { ...filterForm.value, scope };
  submitFilters();
}

function clearFilters(): void {
  visit({ scope: "upcoming" });
}

function packageName(booking: AdminBookingSummaryData): string {
  return language.value === "id"
    ? booking.package.nameId
    : booking.package.nameEn;
}

function formatDate(date: string): string {
  const [year, month, day] = date.split("-").map(Number);
  return new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
}

function appointmentTime(booking: AdminBookingSummaryData): string {
  const { time, timezone } = booking.appointment;
  return `${time} ${timezone === "Asia/Jakarta" ? "WIB" : timezone}`;
}

function formatPrice(amount: number): string {
  return `Rp ${new Intl.NumberFormat("id-ID").format(amount)}`;
}

function paginationLabel(label: string, index: number): string {
  if (index === 0) return tr("ad.pagination.previous");
  if (index === props.bookings.links.length - 1)
    return tr("ad.pagination.next");
  return label;
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.booking.title')"
      :subtitle="tr('ad.booking.subtitle')"
    >
      <div class="admin-content">
        <dl class="admin-count-grid">
          <div
            v-for="key in countKeys"
            :key="key"
            class="admin-count"
            :class="`admin-count--${key}`"
          >
            <dt>{{ tr(`ad.count.${key}`) }}</dt>
            <dd>{{ props.counts[key] }}</dd>
          </div>
        </dl>

        <form
          class="admin-filter-panel"
          data-admin-filters
          method="get"
          action="/admin/bookings"
          :aria-busy="loading"
          @submit.prevent="submitFilters"
        >
          <div class="admin-scopes">
            <button
              v-for="scope in scopes"
              :key="scope"
              class="admin-button admin-scope"
              type="button"
              :data-scope="scope"
              :aria-pressed="filterForm.scope === scope"
              :disabled="loading"
              @click="selectScope(scope)"
            >
              {{ tr(`ad.scope.${scope}`) }}
            </button>
          </div>
          <div class="admin-filter-fields">
            <div class="admin-filter-search">
              <label for="admin-search">{{ tr("ad.filter.search") }}</label>
              <input
                id="admin-search"
                v-model="filterForm.q"
                class="admin-control"
                type="search"
                name="q"
                :aria-invalid="Boolean(errors.q)"
                :aria-describedby="errors.q ? 'admin-search-error' : undefined"
                maxlength="100"
                :disabled="loading"
              />
              <p
                v-if="errors.q"
                id="admin-search-error"
                class="admin-filter-error"
                role="alert"
              >
                {{ errors.q }}
              </p>
            </div>
            <div>
              <label for="admin-status">{{ tr("ad.filter.status") }}</label>
              <select
                id="admin-status"
                v-model="filterForm.status"
                class="admin-control"
                name="status"
                :aria-invalid="Boolean(errors.status)"
                :aria-describedby="
                  errors.status ? 'admin-status-error' : undefined
                "
                :disabled="loading"
              >
                <option value="">{{ tr("ad.scope.all") }}</option>
                <option
                  v-for="status in statuses"
                  :key="status"
                  :value="status"
                >
                  {{ tr(`ad.status.${status}`) }}
                </option>
              </select>
              <p
                v-if="errors.status"
                id="admin-status-error"
                class="admin-filter-error"
                role="alert"
              >
                {{ errors.status }}
              </p>
            </div>
            <div>
              <label for="admin-date-from">{{
                tr("ad.filter.dateFrom")
              }}</label>
              <input
                id="admin-date-from"
                v-model="filterForm.dateFrom"
                class="admin-control"
                type="date"
                name="dateFrom"
                :aria-invalid="Boolean(errors.dateFrom)"
                :aria-describedby="
                  errors.dateFrom ? 'admin-date-from-error' : undefined
                "
                :disabled="loading"
              />
              <p
                v-if="errors.dateFrom"
                id="admin-date-from-error"
                class="admin-filter-error"
                role="alert"
              >
                {{ errors.dateFrom }}
              </p>
            </div>
            <div>
              <label for="admin-date-to">{{ tr("ad.filter.dateTo") }}</label>
              <input
                id="admin-date-to"
                v-model="filterForm.dateTo"
                class="admin-control"
                type="date"
                name="dateTo"
                :aria-invalid="Boolean(errors.dateTo)"
                :aria-describedby="
                  errors.dateTo ? 'admin-date-to-error' : undefined
                "
                :min="filterForm.dateFrom || undefined"
                :disabled="loading"
              />
              <p
                v-if="errors.dateTo"
                id="admin-date-to-error"
                class="admin-filter-error"
                role="alert"
              >
                {{ errors.dateTo }}
              </p>
            </div>
          </div>
          <div class="admin-filter-actions">
            <button
              class="admin-button admin-button--primary"
              type="submit"
              :disabled="loading"
            >
              {{ tr("ad.filter.apply") }}
            </button>
            <button
              v-if="canClear"
              class="admin-button"
              type="button"
              data-clear-filters
              :disabled="loading"
              @click="clearFilters"
            >
              {{ tr("ad.filter.clear") }}
            </button>
          </div>
        </form>

        <div
          v-if="!props.bookings.data.length"
          class="admin-empty"
          data-booking-empty
          role="status"
        >
          {{ tr(hasOptionalFilters ? "ad.empty.filtered" : "ad.empty.all") }}
        </div>
        <template v-else>
          <div class="admin-booking-table-wrap">
            <table class="admin-booking-table">
              <caption class="sr-only">
                {{
                  tr("ad.booking.title")
                }}
              </caption>
              <thead>
                <tr>
                  <th scope="col">{{ tr("ad.booking.reference") }}</th>
                  <th scope="col">{{ tr("ad.table.customer") }}</th>
                  <th scope="col">{{ tr("ad.table.treatment") }}</th>
                  <th scope="col">{{ tr("ad.table.schedule") }}</th>
                  <th scope="col">{{ tr("ad.table.guests") }}</th>
                  <th scope="col">{{ tr("bookings.totalPrice") }}</th>
                  <th scope="col">{{ tr("ad.table.status") }}</th>
                  <th scope="col">{{ tr("ad.table.action") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="booking in props.bookings.data"
                  :key="booking.reference"
                >
                  <th scope="row">{{ booking.reference }}</th>
                  <td>
                    <strong>{{ booking.customer.name }}</strong>
                    <span class="admin-record-secondary">{{
                      booking.customer.email
                    }}</span>
                    <span class="admin-record-secondary">{{
                      booking.customer.phone
                    }}</span>
                  </td>
                  <td>
                    <strong>{{ packageName(booking) }}</strong>
                    <span v-if="booking.package.isVip" class="admin-vip">{{
                      tr("bookings.vip")
                    }}</span>
                    <span class="admin-record-secondary">{{
                      tr(`bookings.treatmentMode.${booking.treatmentMode}`)
                    }}</span>
                    <span class="admin-record-secondary"
                      >{{ booking.totalDurationMinutes }}
                      {{ tr("bookings.minutes") }}</span
                    >
                  </td>
                  <td>
                    <span>{{ formatDate(booking.appointment.date) }}</span>
                    <span class="admin-record-secondary">{{
                      appointmentTime(booking)
                    }}</span>
                  </td>
                  <td>{{ booking.guests }}</td>
                  <td class="admin-price">
                    {{ formatPrice(booking.totalPriceIdr) }}
                  </td>
                  <td>
                    <span
                      class="admin-status"
                      :class="`admin-status--${booking.status}`"
                      >{{ tr(`ad.status.${booking.status}`) }}</span
                    >
                  </td>
                  <td>
                    <Link
                      class="admin-record-link"
                      :href="`/admin/bookings/${encodeURIComponent(booking.reference)}`"
                      :aria-label="`${tr('ad.booking.details')} ${booking.reference}`"
                      >{{ tr("ad.table.open") }}</Link
                    >
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <ul
            class="admin-booking-cards"
            role="list"
            :aria-label="tr('ad.booking.title')"
          >
            <li v-for="booking in props.bookings.data" :key="booking.reference">
              <dl>
                <div>
                  <dt>{{ tr("ad.booking.reference") }}</dt>
                  <dd class="admin-card-reference">{{ booking.reference }}</dd>
                </div>
                <div>
                  <dt>{{ tr("ad.table.customer") }}</dt>
                  <dd>
                    <strong>{{ booking.customer.name }}</strong
                    ><span class="admin-record-secondary">{{
                      booking.customer.email
                    }}</span
                    ><span class="admin-record-secondary">{{
                      booking.customer.phone
                    }}</span>
                  </dd>
                </div>
                <div>
                  <dt>{{ tr("ad.table.treatment") }}</dt>
                  <dd>
                    <strong>{{ packageName(booking) }}</strong
                    ><span v-if="booking.package.isVip" class="admin-vip">{{
                      tr("bookings.vip")
                    }}</span
                    ><span class="admin-record-secondary">{{
                      tr(`bookings.treatmentMode.${booking.treatmentMode}`)
                    }}</span
                    ><span class="admin-record-secondary"
                      >{{ booking.totalDurationMinutes }}
                      {{ tr("bookings.minutes") }}</span
                    >
                  </dd>
                </div>
                <div>
                  <dt>{{ tr("ad.table.schedule") }}</dt>
                  <dd>
                    {{ formatDate(booking.appointment.date)
                    }}<span class="admin-record-secondary">{{
                      appointmentTime(booking)
                    }}</span>
                  </dd>
                </div>
                <div>
                  <dt>{{ tr("ad.table.guests") }}</dt>
                  <dd>{{ booking.guests }}</dd>
                </div>
                <div>
                  <dt>{{ tr("bookings.totalPrice") }}</dt>
                  <dd class="admin-price">
                    {{ formatPrice(booking.totalPriceIdr) }}
                  </dd>
                </div>
                <div>
                  <dt>{{ tr("ad.table.status") }}</dt>
                  <dd>
                    <span
                      class="admin-status"
                      :class="`admin-status--${booking.status}`"
                      >{{ tr(`ad.status.${booking.status}`) }}</span
                    >
                  </dd>
                </div>
              </dl>
              <Link
                class="admin-record-link"
                :href="`/admin/bookings/${encodeURIComponent(booking.reference)}`"
                :aria-label="`${tr('ad.booking.details')} ${booking.reference}`"
                >{{ tr("ad.table.open") }}</Link
              >
            </li>
          </ul>
        </template>

        <nav
          v-if="props.bookings.links.length"
          class="admin-pagination"
          :aria-label="tr('ad.booking.title')"
        >
          <template v-for="(link, index) in props.bookings.links" :key="index">
            <Link
              v-if="link.url"
              class="admin-page-link"
              :href="link.url"
              preserve-state
              preserve-scroll
              :aria-current="link.active ? 'page' : undefined"
              >{{ paginationLabel(link.label, index) }}</Link
            >
            <span v-else class="admin-page-link" aria-disabled="true">{{
              paginationLabel(link.label, index)
            }}</span>
          </template>
        </nav>
      </div>
    </AdminLayout>
  </div>
</template>
