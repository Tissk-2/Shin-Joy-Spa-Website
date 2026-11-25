<script setup lang="ts">
import { Link, router, usePage } from "@inertiajs/vue3";
import { useDebounceFn } from "@vueuse/core";
import { Plus } from "lucide-vue-next";
import { computed, nextTick, ref, watch } from "vue";

import BookingStatusDialog from "@/components/admin/BookingStatusDialog.vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import {
  formatDay,
  formatPrice,
  packageName,
  shortReference,
  timeAgo,
  timezoneLabel,
} from "@/lib/adminBooking";
import type {
  AdminBookingCounts,
  AdminBookingFilters,
  AdminBookingPagination,
  AdminBookingScope,
  AdminBookingSummaryData,
  AppPageProps,
  BookingStatus,
} from "@/types";

const props = defineProps<{
  counts: AdminBookingCounts;
  filters: AdminBookingFilters;
  bookings: AdminBookingPagination;
  canCreate?: boolean;
  roomSchedulingEnabled?: boolean;
  onlinePaymentsEnabled?: boolean;
}>();

const { language, tr } = useLanguage();
const page = usePage<AppPageProps>();
const errors = computed(() => page.props.errors ?? {});
const filterForm = ref({
  ...props.filters,
  status: props.filters.status ?? "",
});
const loading = ref(false);
const scopes: readonly AdminBookingScope[] = [
  "upcoming",
  "pending",
  "today",
  "past",
  "all",
];
// Only online payment creates "awaiting payment" bookings, so the filter offers it only then.
const statuses = computed(() =>
  (
    [
      "pending",
      "confirmed",
      "awaiting_payment",
      "completed",
      "cancelled",
      "no_show",
    ] as const
  ).filter(
    (status) => status !== "awaiting_payment" || props.onlinePaymentsEnabled,
  ),
);
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
    Boolean(
      filterForm.value.q.trim() ||
      filterForm.value.status ||
      filterForm.value.dateFrom ||
      filterForm.value.dateTo,
    ),
);

// The row whose request is being answered, and the button that opened the dialog.
const answering = ref<{
  booking: AdminBookingSummaryData;
  next: BookingStatus;
} | null>(null);
const answerTrigger = ref<HTMLElement | null>(null);
const listHeading = ref<HTMLElement | null>(null);

function queryFor(
  values: typeof filterForm.value | AdminBookingFilters,
): Record<string, string> {
  return Object.fromEntries(
    Object.entries({ ...values, q: values.q.trim() }).filter(
      (entry): entry is [string, string] =>
        entry[1] !== "" && entry[1] !== null,
    ),
  );
}

// The last query sent or received, so one change never sends two requests.
let lastQuery = JSON.stringify(queryFor(props.filters));

// A reply to the latest request keeps whatever staff typed since it left;
// anything else (a link, Back, a rejected filter) resets the controls.
watch(
  () => props.filters,
  (filters) => {
    const received = JSON.stringify(queryFor(filters));
    if (received === lastQuery) return;
    lastQuery = received;
    filterForm.value = { ...filters, status: filters.status ?? "" };
  },
);

function visit(query: Record<string, string>): void {
  lastQuery = JSON.stringify(query);
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

// Filters apply as they change; the search waits for a pause in typing.
function applyIfChanged(): void {
  const query = queryFor(filterForm.value);
  if (JSON.stringify(query) !== lastQuery) visit(query);
}
const applySearch = useDebounceFn(applyIfChanged, 350);
watch(() => filterForm.value.q, applySearch);
watch(
  [
    () => filterForm.value.status,
    () => filterForm.value.dateFrom,
    () => filterForm.value.dateTo,
  ],
  applyIfChanged,
);

function selectScope(scope: AdminBookingScope): void {
  filterForm.value = { ...filterForm.value, scope };
  applyIfChanged();
}

function clearFilters(): void {
  filterForm.value = {
    ...filterForm.value,
    q: "",
    status: "",
    dateFrom: null,
    dateTo: null,
  };
  applyIfChanged();
}

function canAnswer(booking: AdminBookingSummaryData): boolean {
  return (
    (booking.status === "pending" || booking.status === "awaiting_payment") &&
    booking.allowedTransitions.includes("confirmed")
  );
}

function answer(
  booking: AdminBookingSummaryData,
  next: BookingStatus,
  event: MouseEvent,
): void {
  answerTrigger.value = event.currentTarget as HTMLElement;
  answering.value = { booking, next };
}

function answered(): void {
  answering.value = null;
  void nextTick(() => {
    const target = answerTrigger.value;
    if (target?.isConnected) target.focus();
    else listHeading.value?.focus();
  });
}

function bookingHref(booking: AdminBookingSummaryData): string {
  return `/admin/bookings/${encodeURIComponent(booking.reference)}`;
}

function appointmentTime(booking: AdminBookingSummaryData): string {
  const { time, timezone } = booking.appointment;
  return `${time} ${timezoneLabel(timezone)}`;
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
      <template v-if="canCreate" #actions>
        <Link
          href="/admin/bookings/create"
          class="admin-button admin-button--primary"
          data-new-booking
        >
          <Plus aria-hidden="true" />{{ tr("ad.manual.new") }}
        </Link>
      </template>
      <div class="admin-content">
        <p
          v-if="page.props.flash.status"
          class="admin-flash"
          role="status"
          aria-live="polite"
        >
          {{ page.props.flash.status }}
        </p>
        <div
          class="admin-tabs"
          role="group"
          :aria-label="tr('ad.booking.title')"
        >
          <button
            v-for="scope in scopes"
            :key="scope"
            class="admin-tab"
            type="button"
            :data-scope="scope"
            :aria-pressed="filterForm.scope === scope"
            @click="selectScope(scope)"
          >
            {{ tr(`ad.scope.${scope}`) }}
            <span
              class="admin-tab__count"
              :class="{
                'admin-tab__count--alert':
                  scope === 'pending' && props.counts.pending > 0,
              }"
              >{{ props.counts[scope] }}</span
            >
          </button>
        </div>

        <form
          class="admin-filter-bar"
          data-admin-filters
          method="get"
          action="/admin/bookings"
          :aria-busy="loading"
          @submit.prevent="applyIfChanged"
        >
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
            >
              <option value="">{{ tr("ad.scope.all") }}</option>
              <option v-for="status in statuses" :key="status" :value="status">
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
            <label for="admin-date-from">{{ tr("ad.filter.dateFrom") }}</label>
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
          <button
            v-if="canClear"
            class="admin-button admin-filter-clear"
            type="button"
            data-clear-filters
            @click="clearFilters"
          >
            {{ tr("ad.filter.clear") }}
          </button>
        </form>

        <p
          v-if="loading"
          class="admin-loading"
          data-admin-loading
          role="status"
          aria-live="polite"
        >
          {{ tr("ad.filter.loading") }}
        </p>

        <h2 ref="listHeading" class="sr-only" tabindex="-1">
          {{ tr(`ad.scope.${props.filters.scope}`) }}
        </h2>
        <div
          v-if="!props.bookings.data.length"
          class="admin-empty"
          data-booking-empty
          role="status"
        >
          {{ tr(hasOptionalFilters ? "ad.empty.filtered" : "ad.empty.all") }}
        </div>
        <template v-else>
          <div class="admin-booking-table-wrap admin-bookings-table">
            <table class="admin-booking-table">
              <caption class="sr-only">
                {{
                  tr("ad.booking.title")
                }}
              </caption>
              <thead>
                <tr>
                  <th scope="col">{{ tr("ad.table.customer") }}</th>
                  <th scope="col">{{ tr("ad.table.treatment") }}</th>
                  <th scope="col">{{ tr("ad.table.schedule") }}</th>
                  <th scope="col" class="admin-num">
                    {{ tr("bookings.totalPrice") }}
                  </th>
                  <th scope="col">{{ tr("ad.table.status") }}</th>
                  <th scope="col">
                    <span class="sr-only">{{ tr("ad.table.action") }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="booking in props.bookings.data"
                  :key="booking.reference"
                  :data-booking-row="booking.reference"
                >
                  <th scope="row">
                    <Link
                      class="admin-row-link"
                      :href="bookingHref(booking)"
                      :title="booking.reference"
                      ><span class="admin-row-link__name">{{
                        booking.customer.name
                      }}</span>
                      <span class="admin-code">{{
                        shortReference(booking.reference)
                      }}</span></Link
                    >
                  </th>
                  <td>
                    <span class="admin-cell-main"
                      >{{ packageName(booking, language)
                      }}<span v-if="booking.package.isVip" class="admin-vip">{{
                        tr("bookings.vip")
                      }}</span></span
                    >
                    <span class="admin-record-secondary"
                      >{{
                        tr(`bookings.treatmentMode.${booking.treatmentMode}`)
                      }}
                      · {{ booking.totalDurationMinutes }}
                      {{ tr("bookings.minutes") }} ·
                      {{ tr("ad.list.guests", { n: booking.guests }) }}</span
                    >
                  </td>
                  <td class="admin-nowrap">
                    <span class="admin-cell-main">{{
                      formatDay(booking.appointment.date, language, "short")
                    }}</span>
                    <span class="admin-record-secondary">{{
                      appointmentTime(booking)
                    }}</span>
                  </td>
                  <td class="admin-num admin-price">
                    {{ formatPrice(booking.totalPriceIdr) }}
                  </td>
                  <td>
                    <span
                      class="admin-status"
                      :class="`admin-status--${booking.status}`"
                      >{{ tr(`ad.status.${booking.status}`) }}</span
                    >
                    <span
                      v-if="booking.status === 'pending' && booking.requestedAt"
                      class="admin-record-secondary admin-nowrap"
                      >{{
                        tr("ad.list.requested", {
                          when: timeAgo(booking.requestedAt, language),
                        })
                      }}</span
                    >
                  </td>
                  <td class="admin-row-actions">
                    <template v-if="canAnswer(booking)">
                      <button
                        type="button"
                        class="admin-button admin-button--danger admin-button--compact"
                        data-answer="cancelled"
                        :aria-label="`${tr(`ad.action.${booking.status}.cancelled`)} ${booking.customer.name}`"
                        @click="answer(booking, 'cancelled', $event)"
                      >
                        {{ tr(`ad.action.${booking.status}.cancelled`) }}
                      </button>
                      <button
                        type="button"
                        class="admin-button admin-button--primary admin-button--compact"
                        data-answer="confirmed"
                        :aria-label="`${tr(`ad.action.${booking.status}.confirmed`)} ${booking.customer.name}`"
                        @click="answer(booking, 'confirmed', $event)"
                      >
                        {{ tr(`ad.action.${booking.status}.confirmed`) }}
                      </button>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <ul
            class="admin-booking-list"
            role="list"
            :aria-label="tr('ad.booking.title')"
          >
            <li
              v-for="booking in props.bookings.data"
              :key="booking.reference"
              :data-booking-row="booking.reference"
            >
              <div class="admin-booking-list__top">
                <Link
                  class="admin-row-link"
                  :href="bookingHref(booking)"
                  :title="booking.reference"
                  ><span class="admin-row-link__name">{{
                    booking.customer.name
                  }}</span>
                  <span class="admin-code">{{
                    shortReference(booking.reference)
                  }}</span></Link
                >
                <span
                  class="admin-status"
                  :class="`admin-status--${booking.status}`"
                  >{{ tr(`ad.status.${booking.status}`) }}</span
                >
              </div>
              <p class="admin-booking-list__meta">
                <span>{{
                  formatDay(booking.appointment.date, language, "short")
                }}</span>
                · <span>{{ appointmentTime(booking) }}</span> ·
                <span>{{ packageName(booking, language) }}</span>
                · <span>{{ tr("ad.list.guests", { n: booking.guests }) }}</span>
                ·
                <span class="admin-price">{{
                  formatPrice(booking.totalPriceIdr)
                }}</span>
              </p>
              <div
                v-if="canAnswer(booking)"
                class="admin-booking-list__actions"
              >
                <button
                  type="button"
                  class="admin-button admin-button--danger"
                  data-answer="cancelled"
                  :aria-label="`${tr(`ad.action.${booking.status}.cancelled`)} ${booking.customer.name}`"
                  @click="answer(booking, 'cancelled', $event)"
                >
                  {{ tr(`ad.action.${booking.status}.cancelled`) }}
                </button>
                <button
                  type="button"
                  class="admin-button admin-button--primary"
                  data-answer="confirmed"
                  :aria-label="`${tr(`ad.action.${booking.status}.confirmed`)} ${booking.customer.name}`"
                  @click="answer(booking, 'confirmed', $event)"
                >
                  {{ tr(`ad.action.${booking.status}.confirmed`) }}
                </button>
              </div>
            </li>
          </ul>
        </template>

        <nav
          v-if="props.bookings.lastPage > 1"
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
    <BookingStatusDialog
      :booking="answering?.booking ?? null"
      :next-status="answering?.next ?? null"
      :room-scheduling-enabled="roomSchedulingEnabled === true"
      return-to="bookings"
      @close="answered"
    />
  </div>
</template>
