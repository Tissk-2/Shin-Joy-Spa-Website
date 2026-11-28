<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";

import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import { formatDay, formatPrice } from "@/lib/adminBooking";
import type {
  AdminReportDailyPoint,
  AdminReportData,
  AdminReportPeriod,
} from "@/types";

const props = defineProps<{
  report: AdminReportData;
}>();

const { language, tr } = useLanguage();
const periods: readonly AdminReportPeriod[] = ["7d", "30d", "month"];
const pinnedDay = ref<string | null>(null);
const hoveredDay = ref<string | null>(null);
const activeDay = computed(() => hoveredDay.value ?? pinnedDay.value);

function switchPeriod(period: AdminReportPeriod): void {
  if (period === props.report.period) return;
  pinnedDay.value = null;
  hoveredDay.value = null;
  router.get(
    "/admin/reports",
    { period },
    { preserveState: true, preserveScroll: true, replace: true },
  );
}

const rangeLabel = computed(() =>
  tr("ad.reports.range", {
    from: formatDay(props.report.range.from, language.value, "long"),
    to: formatDay(props.report.range.to, language.value, "long"),
  }),
);

const lostTotal = computed(
  () => props.report.summary.cancelledCount + props.report.summary.noShowCount,
);

const maxDailyTotal = computed(() =>
  Math.max(1, ...props.report.dailyTrend.map((point) => point.total)),
);

const maxPackageCount = computed(() =>
  Math.max(1, ...props.report.topPackages.map((row) => row.count)),
);

const maxHourCount = computed(() =>
  Math.max(1, ...props.report.peakHours.map((point) => point.count)),
);

const hasPeakHours = computed(() =>
  props.report.peakHours.some((point) => point.count > 0),
);

const activeDayPoint = computed<AdminReportDailyPoint | null>(() => {
  if (!activeDay.value) return null;
  return (
    props.report.dailyTrend.find((point) => point.date === activeDay.value) ??
    null
  );
});

function dayDetailText(point: AdminReportDailyPoint): string {
  return tr("ad.reports.trendDetail", {
    date: formatDay(point.date, language.value, "short"),
    total: point.total,
    completed: point.completed,
    active: point.active,
    lost: point.lost,
    count: point.total,
  });
}

function showAxisTick(index: number): boolean {
  const total = props.report.dailyTrend.length;
  if (total <= 10) return true;
  if (index === 0 || index === total - 1) return true;
  const step = Math.ceil(total / 5);
  return index % step === 0 && total - 1 - index >= Math.floor(step / 2);
}

function shortAxisDate(iso: string): string {
  const [, month, day] = iso.split("-");
  return `${Number(day)}/${Number(month)}`;
}

function segmentPercent(value: number, max: number): string {
  if (value <= 0 || max <= 0) return "0%";
  return `${Math.max(6, Math.round((value / max) * 100))}%`;
}

const outcomes = computed(() => [
  {
    key: "completed",
    label: tr("ad.reports.outcomeCompleted"),
    count: props.report.summary.completedCount,
    tone: "completed",
  },
  {
    key: "confirmed",
    label: tr("ad.reports.outcomeConfirmed"),
    count: props.report.summary.confirmedCount,
    tone: "confirmed",
  },
  {
    key: "pending",
    label: tr("ad.reports.outcomePending"),
    count: props.report.summary.pendingCount,
    tone: "pending",
  },
  {
    key: "manualCancelled",
    label: tr("ad.reports.outcomeManualCancelled"),
    count: props.report.summary.manualCancelledCount,
    tone: "cancelled",
  },
  {
    key: "expired",
    label: tr("ad.reports.outcomeExpired"),
    count: props.report.summary.expiredCount,
    tone: "cancelled",
  },
  {
    key: "slotFull",
    label: tr("ad.reports.outcomeSlotFull"),
    count: props.report.summary.slotFullCount,
    tone: "cancelled",
  },
  {
    key: "noShow",
    label: tr("ad.reports.outcomeNoShow"),
    count: props.report.summary.noShowCount,
    tone: "no_show",
  },
]);

const channelTotal = computed(() =>
  props.report.channels.reduce((sum, row) => sum + row.count, 0),
);
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.reports.title')"
      :subtitle="tr('ad.reports.subtitle')"
    >
      <template #actions>
        <Link href="/admin/bookings" class="admin-button">
          {{ tr("ad.reports.viewBookings") }}
        </Link>
      </template>

      <div class="admin-content">
        <div class="admin-report-toolbar">
          <div
            class="admin-tabs"
            role="group"
            :aria-label="tr('ad.reports.periodLabel')"
            data-report-periods
          >
            <button
              v-for="period in periods"
              :key="period"
              type="button"
              class="admin-tab"
              :data-period="period"
              :aria-pressed="report.period === period"
              @click="switchPeriod(period)"
            >
              {{ tr(`ad.reports.period.${period}`) }}
            </button>
          </div>
          <p class="admin-record-secondary" data-report-range>
            {{ rangeLabel }}
          </p>
        </div>

        <section
          class="admin-report-kpis"
          :aria-label="tr('ad.reports.title')"
          data-report-kpis
        >
          <article class="admin-kpi-card" data-kpi="total">
            <p class="admin-kpi-card__label">{{ tr("ad.reports.kpiTotal") }}</p>
            <p class="admin-kpi-card__value">
              {{ report.summary.totalBookings }}
            </p>
            <p class="admin-kpi-card__sub">
              {{
                report.summary.confirmedCount > 0 ||
                report.summary.pendingCount > 0
                  ? tr("ad.reports.kpiTotalSub", {
                      confirmed: report.summary.confirmedCount,
                      pending: report.summary.pendingCount,
                    })
                  : tr("ad.reports.kpiTotalAll")
              }}
            </p>
          </article>

          <article class="admin-kpi-card" data-kpi="completed">
            <p class="admin-kpi-card__label">
              {{ tr("ad.reports.kpiCompleted") }}
            </p>
            <p class="admin-kpi-card__value">
              {{ report.summary.completedCount }}
            </p>
            <p class="admin-kpi-card__sub">
              {{
                report.summary.completionRate !== null
                  ? tr("ad.reports.kpiCompletedRate", {
                      rate: report.summary.completionRate,
                    })
                  : tr("ad.reports.kpiCompletedNone")
              }}
            </p>
          </article>

          <article class="admin-kpi-card" data-kpi="lost">
            <p class="admin-kpi-card__label">{{ tr("ad.reports.kpiLost") }}</p>
            <p class="admin-kpi-card__value">{{ lostTotal }}</p>
            <p class="admin-kpi-card__sub">
              {{
                tr("ad.reports.kpiLostBreakdown", {
                  cancelled: report.summary.cancelledCount,
                  noShow: report.summary.noShowCount,
                })
              }}
            </p>
          </article>

          <article class="admin-kpi-card" data-kpi="value">
            <p class="admin-kpi-card__label">{{ tr("ad.reports.kpiValue") }}</p>
            <p class="admin-kpi-card__value admin-price">
              {{ formatPrice(report.summary.estimatedCompletedRevenueIdr) }}
            </p>
            <p class="admin-kpi-card__sub">
              {{
                report.summary.confirmedPipelineIdr > 0
                  ? tr("ad.reports.kpiValueConfirmed", {
                      amount: formatPrice(report.summary.confirmedPipelineIdr),
                    })
                  : tr("ad.reports.kpiValueAtSpa")
              }}
            </p>
          </article>
        </section>

        <p class="admin-report-note" data-report-estimate-note>
          {{ tr("ad.reports.estimateNote") }}
        </p>

        <section
          class="admin-panel"
          aria-labelledby="report-trend-title"
          data-report-trend
        >
          <header class="admin-panel__head admin-report-panel__head">
            <div>
              <h2 id="report-trend-title">{{ tr("ad.reports.trendTitle") }}</h2>
              <p aria-live="polite">
                {{
                  activeDayPoint
                    ? dayDetailText(activeDayPoint)
                    : report.summary.totalBookings > 0
                      ? tr("ad.reports.trendHint")
                      : tr("ad.reports.trendEmpty")
                }}
              </p>
            </div>
            <ul
              v-if="report.summary.totalBookings > 0"
              class="admin-report-legend"
              role="list"
            >
              <li>
                <span
                  class="admin-report-swatch admin-report-swatch--completed"
                  aria-hidden="true"
                />
                {{ tr("ad.reports.trendLegendCompleted") }}
              </li>
              <li>
                <span
                  class="admin-report-swatch admin-report-swatch--active"
                  aria-hidden="true"
                />
                {{ tr("ad.reports.trendLegendActive") }}
              </li>
              <li>
                <span
                  class="admin-report-swatch admin-report-swatch--lost"
                  aria-hidden="true"
                />
                {{ tr("ad.reports.trendLegendLost") }}
              </li>
            </ul>
          </header>

          <p
            v-if="report.summary.totalBookings === 0"
            class="admin-panel__empty"
          >
            {{ tr("ad.reports.trendEmpty") }}
          </p>
          <div v-else class="admin-trend">
            <div class="admin-trend__bars" role="list">
              <button
                v-for="(point, index) in report.dailyTrend"
                :key="point.date"
                type="button"
                class="admin-trend__col"
                :class="{ 'is-selected': activeDay === point.date }"
                :aria-label="dayDetailText(point)"
                :aria-pressed="pinnedDay === point.date"
                @mouseenter="hoveredDay = point.date"
                @mouseleave="if (hoveredDay === point.date) hoveredDay = null;"
                @focus="hoveredDay = point.date"
                @blur="if (hoveredDay === point.date) hoveredDay = null;"
                @click="
                  pinnedDay = pinnedDay === point.date ? null : point.date
                "
              >
                <span class="admin-trend__track" aria-hidden="true">
                  <span
                    class="admin-trend__stack"
                    :style="{
                      height:
                        point.total > 0
                          ? segmentPercent(point.total, maxDailyTotal)
                          : '0%',
                    }"
                  >
                    <span
                      v-if="point.lost > 0"
                      class="admin-trend__seg admin-trend__seg--lost"
                      :style="{ flexGrow: point.lost }"
                    />
                    <span
                      v-if="point.active > 0"
                      class="admin-trend__seg admin-trend__seg--active"
                      :style="{ flexGrow: point.active }"
                    />
                    <span
                      v-if="point.completed > 0"
                      class="admin-trend__seg admin-trend__seg--completed"
                      :style="{ flexGrow: point.completed }"
                    />
                  </span>
                </span>
                <span class="admin-trend__tick">
                  {{ showAxisTick(index) ? shortAxisDate(point.date) : "" }}
                </span>
              </button>
            </div>
          </div>
        </section>

        <div class="admin-report-grid">
          <section
            class="admin-panel"
            aria-labelledby="report-packages-title"
            data-report-packages
          >
            <header class="admin-panel__head">
              <h2 id="report-packages-title">
                {{ tr("ad.reports.packagesTitle") }}
              </h2>
              <p>{{ tr("ad.reports.packagesSub") }}</p>
            </header>
            <p v-if="!report.topPackages.length" class="admin-panel__empty">
              {{ tr("ad.reports.packagesEmpty") }}
            </p>
            <ol v-else class="admin-report-list">
              <li
                v-for="row in report.topPackages"
                :key="`${row.slug}-${row.treatmentMode}-${row.durationMinutes}`"
                class="admin-report-bar-row"
              >
                <div class="admin-report-bar-row__top">
                  <span class="admin-cell-main">
                    {{ language === "id" ? row.nameId : row.nameEn
                    }}<span v-if="row.isVip" class="admin-vip">{{
                      tr("bookings.vip")
                    }}</span>
                    <span class="admin-record-secondary">
                      ·
                      {{ tr(`bookings.treatmentMode.${row.treatmentMode}`) }} ·
                      {{ row.durationMinutes }} {{ tr("bookings.minutes") }}
                    </span>
                  </span>
                  <span class="admin-report-bar-row__metric">
                    <strong>{{
                      tr("ad.reports.bookingCount", { n: row.count })
                    }}</strong>
                    <span class="admin-record-secondary admin-price">{{
                      formatPrice(row.estimatedValueIdr)
                    }}</span>
                  </span>
                </div>
                <div class="admin-report-meter" aria-hidden="true">
                  <span
                    class="admin-report-meter__fill"
                    :style="{
                      width: segmentPercent(row.count, maxPackageCount),
                    }"
                  />
                </div>
              </li>
            </ol>
          </section>

          <section
            class="admin-panel"
            aria-labelledby="report-hours-title"
            data-report-hours
          >
            <header class="admin-panel__head">
              <h2 id="report-hours-title">{{ tr("ad.reports.hoursTitle") }}</h2>
              <p>{{ tr("ad.reports.hoursSub") }}</p>
            </header>
            <p v-if="!hasPeakHours" class="admin-panel__empty">
              {{ tr("ad.reports.hoursEmpty") }}
            </p>
            <div v-else class="admin-hours-chart" role="list">
              <div
                v-for="slot in report.peakHours"
                :key="slot.hour"
                class="admin-hours-chart__col"
                role="listitem"
                :aria-label="`${slot.hour} WIB: ${tr('ad.reports.bookingCount', { n: slot.count })}`"
              >
                <span class="admin-hours-chart__count">{{
                  slot.count > 0 ? slot.count : ""
                }}</span>
                <span class="admin-hours-chart__track" aria-hidden="true">
                  <span
                    class="admin-hours-chart__bar"
                    :class="{
                      'admin-hours-chart__bar--peak':
                        slot.count > 0 && slot.count === maxHourCount,
                    }"
                    :style="{
                      height:
                        slot.count > 0
                          ? segmentPercent(slot.count, maxHourCount)
                          : '0%',
                    }"
                  />
                </span>
                <span class="admin-hours-chart__label">{{
                  slot.hour.slice(0, 2)
                }}</span>
              </div>
            </div>
          </section>
        </div>

        <div class="admin-report-grid">
          <section
            class="admin-panel"
            aria-labelledby="report-outcomes-title"
            data-report-outcomes
          >
            <header class="admin-panel__head">
              <h2 id="report-outcomes-title">
                {{ tr("ad.reports.outcomesTitle") }}
              </h2>
            </header>
            <ul class="admin-report-list" role="list">
              <li
                v-for="item in outcomes"
                :key="item.key"
                class="admin-report-stat-row"
              >
                <span>{{ item.label }}</span>
                <span
                  class="admin-status"
                  :class="
                    item.count > 0
                      ? `admin-status--${item.tone}`
                      : 'admin-report-zero'
                  "
                  >{{ item.count }}</span
                >
              </li>
            </ul>
          </section>

          <section
            class="admin-panel"
            aria-labelledby="report-channels-title"
            data-report-channels
          >
            <header class="admin-panel__head">
              <h2 id="report-channels-title">
                {{ tr("ad.reports.channelsTitle") }}
              </h2>
            </header>
            <ul class="admin-report-list" role="list">
              <li
                v-for="channel in report.channels"
                :key="channel.key"
                class="admin-report-bar-row"
              >
                <div class="admin-report-bar-row__top">
                  <span>{{ tr(`ad.reports.channel.${channel.key}`) }}</span>
                  <span class="admin-report-bar-row__metric">
                    <strong>{{ channel.count }}</strong>
                    <span
                      v-if="channelTotal > 0"
                      class="admin-record-secondary"
                    >
                      ({{ Math.round((channel.count / channelTotal) * 100) }}%)
                    </span>
                  </span>
                </div>
                <div class="admin-report-meter" aria-hidden="true">
                  <span
                    class="admin-report-meter__fill admin-report-meter__fill--soft"
                    :style="{
                      width:
                        channel.count > 0
                          ? segmentPercent(channel.count, channelTotal)
                          : '0%',
                    }"
                  />
                </div>
              </li>
            </ul>
          </section>
        </div>
      </div>
    </AdminLayout>
  </div>
</template>
