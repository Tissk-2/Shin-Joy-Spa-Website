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

const activeTotal = computed(
  () =>
    props.report.summary.confirmedCount +
    props.report.summary.pendingCount +
    props.report.summary.awaitingPaymentCount,
);

interface DeltaBadge {
  readonly label: string;
  readonly tone: "good" | "bad" | "neutral";
  readonly detail: string;
}

function buildDelta(
  current: number,
  previous: number | undefined,
  options: { invert?: boolean; formatPrev?: (v: number) => string } = {},
): DeltaBadge | null {
  if (previous === undefined) return null;
  const formatPrev = options.formatPrev ?? ((v: number) => String(v));
  const diff = current - previous;
  const detail = tr("ad.reports.vsPrev", { prev: formatPrev(previous) });

  if (diff === 0) {
    return {
      label: "0%",
      tone: "neutral",
      detail: tr("ad.reports.deltaFlat"),
    };
  }

  const isPositiveMove = options.invert ? diff < 0 : diff > 0;
  const tone = isPositiveMove ? "good" : "bad";

  if (previous === 0) {
    const label = options.formatPrev
      ? `+${formatPrev(diff)}`
      : tr("ad.reports.deltaNew", { diff });
    return { label, tone, detail };
  }

  const pct = Math.round((diff / previous) * 100);
  const sign = pct > 0 ? "+" : "";
  return { label: `${sign}${pct}%`, tone, detail };
}

const totalDelta = computed(() =>
  buildDelta(
    props.report.summary.totalBookings,
    props.report.previousSummary?.totalBookings,
  ),
);

const completedDelta = computed(() =>
  buildDelta(
    props.report.summary.completedCount,
    props.report.previousSummary?.completedCount,
  ),
);

const lostDelta = computed(() =>
  buildDelta(lostTotal.value, props.report.previousSummary?.lostCount, {
    invert: true,
  }),
);

const revenueDelta = computed(() =>
  buildDelta(
    props.report.summary.estimatedCompletedRevenueIdr,
    props.report.previousSummary?.estimatedCompletedRevenueIdr,
    { formatPrev: formatPrice },
  ),
);

// SVG completion ring (radius 25 -> circumference ~157.08).
const RING_RADIUS = 25;
const RING_CIRCUMFERENCE = Number((2 * Math.PI * RING_RADIUS).toFixed(2));
const ringOffset = computed(() => {
  const rate = props.report.summary.completionRate;
  if (rate === null || rate <= 0) return RING_CIRCUMFERENCE;
  return Number(
    (RING_CIRCUMFERENCE * (1 - Math.min(100, rate) / 100)).toFixed(2),
  );
});

const maxDailyTotal = computed(() =>
  Math.max(1, ...props.report.dailyTrend.map((point) => point.total)),
);

const dailyMidTick = computed(() => {
  const max = maxDailyTotal.value;
  return max <= 1 ? null : Math.ceil(max / 2);
});

const peakDay = computed<AdminReportDailyPoint | null>(() => {
  let best: AdminReportDailyPoint | null = null;
  for (const point of props.report.dailyTrend) {
    if (point.total > 0 && (!best || point.total > best.total)) best = point;
  }
  return best;
});

const sparkline = computed(() => {
  const points = props.report.dailyTrend;
  if (!points.length || props.report.summary.totalBookings === 0) return null;
  const width = 84;
  const height = 26;
  const padY = 3;
  const usableHeight = height - padY * 2;
  const max = maxDailyTotal.value;
  const stepX = points.length > 1 ? width / (points.length - 1) : 0;

  const coords = points.map((point, index) => {
    const x =
      points.length > 1 ? Number((index * stepX).toFixed(1)) : width / 2;
    const y = Number(
      (height - padY - (point.total / max) * usableHeight).toFixed(1),
    );
    return { x, y };
  });

  const line = coords
    .map((pt, i) => `${i === 0 ? "M" : "L"}${pt.x} ${pt.y}`)
    .join(" ");
  const area = `${line} L${coords[coords.length - 1].x} ${height} L${coords[0].x} ${height} Z`;
  return { line, area };
});

const maxPackageCount = computed(() =>
  Math.max(1, ...props.report.topPackages.map((row) => row.count)),
);

const maxHourCount = computed(() =>
  Math.max(1, ...props.report.peakHours.map((point) => point.count)),
);

const hasPeakHours = computed(() =>
  props.report.peakHours.some((point) => point.count > 0),
);

const busiestHour = computed(() => {
  let best: { hour: string; count: number } | null = null;
  for (const slot of props.report.peakHours) {
    if (slot.count > 0 && (!best || slot.count > best.count)) best = slot;
  }
  return best;
});

const activeDayIndex = computed(() => {
  if (!activeDay.value) return -1;
  return props.report.dailyTrend.findIndex(
    (point) => point.date === activeDay.value,
  );
});

const activeDayPoint = computed<AdminReportDailyPoint | null>(() =>
  activeDayIndex.value >= 0
    ? props.report.dailyTrend[activeDayIndex.value]
    : null,
);

const tooltipLeftPercent = computed(() => {
  const total = props.report.dailyTrend.length;
  if (activeDayIndex.value < 0 || total <= 0) return "50%";
  const raw = ((activeDayIndex.value + 0.5) / total) * 100;
  return `${Math.min(86, Math.max(14, raw)).toFixed(1)}%`;
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

function barHeightPercent(value: number, max: number): string {
  if (value <= 0 || max <= 0) return "0%";
  return `${Math.max(5, Math.round((value / max) * 100))}%`;
}

function meterWidthPercent(value: number, max: number): string {
  if (value <= 0 || max <= 0) return "0%";
  return `${Math.max(4, Math.round((value / max) * 100))}%`;
}

const outcomeBarSegments = computed(() => {
  const total = props.report.summary.totalBookings;
  if (total <= 0) return [];
  return [
    {
      key: "completed",
      count: props.report.summary.completedCount,
      tone: "completed",
      width: `${(props.report.summary.completedCount / total) * 100}%`,
    },
    {
      key: "active",
      count: activeTotal.value,
      tone: "active",
      width: `${(activeTotal.value / total) * 100}%`,
    },
    {
      key: "lost",
      count: lostTotal.value,
      tone: "lost",
      width: `${(lostTotal.value / total) * 100}%`,
    },
  ].filter((seg) => seg.count > 0);
});

const outcomes = computed(() => [
  {
    key: "completed",
    label: tr("ad.reports.outcomeCompleted"),
    count: props.report.summary.completedCount,
    dot: "completed",
  },
  {
    key: "confirmed",
    label: tr("ad.reports.outcomeConfirmed"),
    count: props.report.summary.confirmedCount,
    dot: "active",
  },
  {
    key: "pending",
    label: tr("ad.reports.outcomePending"),
    count: props.report.summary.pendingCount,
    dot: "pending",
  },
  {
    key: "manualCancelled",
    label: tr("ad.reports.outcomeManualCancelled"),
    count: props.report.summary.manualCancelledCount,
    dot: "lost",
  },
  {
    key: "expired",
    label: tr("ad.reports.outcomeExpired"),
    count: props.report.summary.expiredCount,
    dot: "lost",
  },
  {
    key: "slotFull",
    label: tr("ad.reports.outcomeSlotFull"),
    count: props.report.summary.slotFullCount,
    dot: "lost",
  },
  {
    key: "noShow",
    label: tr("ad.reports.outcomeNoShow"),
    count: props.report.summary.noShowCount,
    dot: "lost",
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
            class="admin-segmented"
            role="group"
            :aria-label="tr('ad.reports.periodLabel')"
            data-report-periods
          >
            <button
              v-for="period in periods"
              :key="period"
              type="button"
              class="admin-segmented__item"
              :data-period="period"
              :aria-pressed="report.period === period"
              @click="switchPeriod(period)"
            >
              {{ tr(`ad.reports.period.${period}`) }}
            </button>
          </div>
          <p class="admin-record-secondary admin-nowrap" data-report-range>
            {{ rangeLabel }}
          </p>
        </div>

        <!-- 2-column executive split workspace (Boulevard / Attio analytics layout) -->
        <div class="admin-reports-workspace">
          <!-- LEFT PRIMARY STAGE: unified volume+value hero chart + peak hours -->
          <div class="admin-reports-primary">
            <section
              class="admin-panel admin-hero-report"
              :aria-label="tr('ad.reports.title')"
              data-report-kpis
            >
              <div class="admin-hero-report__head">
                <article
                  class="admin-hero-metric admin-hero-metric--lead"
                  data-kpi="total"
                >
                  <div class="admin-metric-cell__top">
                    <span class="admin-metric-cell__label">{{
                      tr("ad.reports.kpiTotal")
                    }}</span>
                    <span
                      v-if="totalDelta"
                      class="admin-delta"
                      :class="`admin-delta--${totalDelta.tone}`"
                      :title="totalDelta.detail"
                      data-kpi-delta="total"
                      >{{ totalDelta.label }}</span
                    >
                  </div>
                  <div class="admin-metric-cell__figure">
                    <p class="admin-hero-metric__value">
                      {{ report.summary.totalBookings }}
                    </p>
                    <svg
                      v-if="sparkline"
                      class="admin-sparkline"
                      viewBox="0 0 84 26"
                      width="84"
                      height="26"
                      aria-hidden="true"
                    >
                      <path class="admin-sparkline__area" :d="sparkline.area" />
                      <path class="admin-sparkline__line" :d="sparkline.line" />
                    </svg>
                  </div>
                  <p class="admin-metric-cell__sub">
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

                <article class="admin-hero-metric" data-kpi="value">
                  <div class="admin-metric-cell__top">
                    <span class="admin-metric-cell__label">{{
                      tr("ad.reports.kpiValue")
                    }}</span>
                    <span
                      v-if="revenueDelta"
                      class="admin-delta"
                      :class="`admin-delta--${revenueDelta.tone}`"
                      :title="revenueDelta.detail"
                      data-kpi-delta="value"
                      >{{ revenueDelta.label }}</span
                    >
                  </div>
                  <p class="admin-hero-metric__value admin-price">
                    {{
                      formatPrice(report.summary.estimatedCompletedRevenueIdr)
                    }}
                  </p>
                  <p class="admin-metric-cell__sub">
                    {{
                      report.summary.confirmedPipelineIdr > 0
                        ? tr("ad.reports.kpiValueConfirmed", {
                            amount: formatPrice(
                              report.summary.confirmedPipelineIdr,
                            ),
                          })
                        : tr("ad.reports.kpiValueAtSpa")
                    }}
                  </p>
                </article>
              </div>

              <div class="admin-hero-report__trend" data-report-trend>
                <header class="admin-hero-report__subhead">
                  <div class="admin-report-panel__heading">
                    <div class="admin-report-panel__title-row">
                      <h2 id="report-trend-title">
                        {{ tr("ad.reports.trendTitle") }}
                      </h2>
                      <span
                        v-if="peakDay"
                        class="admin-report-pill"
                        data-report-peak-day
                      >
                        {{
                          tr("ad.reports.peakDaySummary", {
                            date: formatDay(peakDay.date, language, "short"),
                            count: peakDay.total,
                          })
                        }}
                      </span>
                    </div>
                    <p aria-live="polite" class="admin-trend-readout">
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
                <div v-else class="admin-trend-chart">
                  <div class="admin-trend-chart__yaxis" aria-hidden="true">
                    <span>{{ maxDailyTotal }}</span>
                    <span>{{ dailyMidTick ?? "" }}</span>
                    <span>0</span>
                  </div>
                  <div class="admin-trend-chart__plot">
                    <div class="admin-trend-chart__guides" aria-hidden="true">
                      <span class="admin-trend-chart__guide" />
                      <span class="admin-trend-chart__guide" />
                      <span
                        class="admin-trend-chart__guide admin-trend-chart__guide--base"
                      />
                    </div>

                    <div
                      v-if="activeDayPoint"
                      class="admin-chart-tooltip"
                      :style="{ left: tooltipLeftPercent }"
                      aria-hidden="true"
                    >
                      <span class="admin-chart-tooltip__date">{{
                        formatDay(activeDayPoint.date, language, "short")
                      }}</span>
                      <span class="admin-chart-tooltip__count">
                        {{
                          tr("ad.reports.bookingCount", {
                            n: activeDayPoint.total,
                          })
                        }}
                      </span>
                    </div>

                    <div
                      class="admin-trend__bars"
                      :class="{ 'has-active': activeDay !== null }"
                      role="list"
                    >
                      <button
                        v-for="(point, index) in report.dailyTrend"
                        :key="point.date"
                        type="button"
                        class="admin-trend__col"
                        :class="{
                          'is-selected': activeDay === point.date,
                          'is-pinned': pinnedDay === point.date,
                        }"
                        :aria-label="dayDetailText(point)"
                        :aria-pressed="pinnedDay === point.date"
                        @mouseenter="hoveredDay = point.date"
                        @mouseleave="
                          if (hoveredDay === point.date) hoveredDay = null;
                        "
                        @focus="hoveredDay = point.date"
                        @blur="
                          if (hoveredDay === point.date) hoveredDay = null;
                        "
                        @click="
                          pinnedDay =
                            pinnedDay === point.date ? null : point.date
                        "
                      >
                        <span class="admin-trend__track" aria-hidden="true">
                          <span
                            v-if="activeDay === point.date"
                            class="admin-trend__crosshair"
                          />
                          <span
                            class="admin-trend__stack"
                            :style="{
                              height:
                                point.total > 0
                                  ? barHeightPercent(point.total, maxDailyTotal)
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
                          {{
                            showAxisTick(index) ? shortAxisDate(point.date) : ""
                          }}
                        </span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <p class="admin-metric-band__foot" data-report-estimate-note>
                {{ tr("ad.reports.estimateNote") }}
              </p>
            </section>

            <section
              class="admin-panel"
              aria-labelledby="report-hours-title"
              data-report-hours
            >
              <header class="admin-panel__head admin-report-panel__head">
                <div class="admin-report-panel__heading">
                  <div class="admin-report-panel__title-row">
                    <h2 id="report-hours-title">
                      {{ tr("ad.reports.hoursTitle") }}
                    </h2>
                    <span
                      v-if="busiestHour"
                      class="admin-report-pill"
                      data-report-peak-hour
                    >
                      {{
                        tr("ad.reports.peakSummary", {
                          hour: busiestHour.hour,
                          count: busiestHour.count,
                        })
                      }}
                    </span>
                  </div>
                  <p>{{ tr("ad.reports.hoursSub") }}</p>
                </div>
              </header>
              <p v-if="!hasPeakHours" class="admin-panel__empty">
                {{ tr("ad.reports.hoursEmpty") }}
              </p>
              <div v-else class="admin-hours-wrap">
                <div class="admin-hours-guides" aria-hidden="true">
                  <span class="admin-trend-chart__guide" />
                  <span class="admin-trend-chart__guide" />
                </div>
                <div class="admin-hours-chart" role="list">
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
                              ? barHeightPercent(slot.count, maxHourCount)
                              : '0%',
                        }"
                      />
                    </span>
                    <span class="admin-hours-chart__label">{{
                      slot.hour.slice(0, 2)
                    }}</span>
                  </div>
                </div>
              </div>
            </section>
          </div>

          <!-- RIGHT INTELLIGENCE RAIL: completion ring + outcomes, top treatments, channels -->
          <div class="admin-reports-rail">
            <section
              class="admin-panel"
              aria-labelledby="report-outcomes-title"
              data-report-outcomes
            >
              <header class="admin-panel__head">
                <h2 id="report-outcomes-title">
                  {{ tr("ad.reports.outcomesTitle") }}
                </h2>
                <p v-if="report.summary.totalBookings > 0">
                  {{
                    tr("ad.reports.outcomesShare", {
                      n: report.summary.totalBookings,
                    })
                  }}
                </p>
              </header>

              <div class="admin-health-top">
                <div class="admin-ring" aria-hidden="true">
                  <svg viewBox="0 0 64 64" width="64" height="64">
                    <circle
                      class="admin-ring__track"
                      cx="32"
                      cy="32"
                      :r="RING_RADIUS"
                    />
                    <circle
                      class="admin-ring__arc"
                      cx="32"
                      cy="32"
                      :r="RING_RADIUS"
                      :stroke-dasharray="RING_CIRCUMFERENCE"
                      :stroke-dashoffset="ringOffset"
                    />
                  </svg>
                  <span class="admin-ring__label">{{
                    report.summary.completionRate !== null
                      ? `${report.summary.completionRate}%`
                      : "0%"
                  }}</span>
                </div>

                <div class="admin-health-stats">
                  <article class="admin-health-stat" data-kpi="completed">
                    <div class="admin-metric-cell__top">
                      <span class="admin-metric-cell__label">{{
                        tr("ad.reports.kpiCompleted")
                      }}</span>
                      <span
                        v-if="completedDelta"
                        class="admin-delta"
                        :class="`admin-delta--${completedDelta.tone}`"
                        :title="completedDelta.detail"
                        data-kpi-delta="completed"
                        >{{ completedDelta.label }}</span
                      >
                    </div>
                    <p class="admin-health-stat__value">
                      {{ report.summary.completedCount }}
                    </p>
                    <p class="admin-metric-cell__sub">
                      {{
                        report.summary.completionRate !== null
                          ? tr("ad.reports.kpiCompletedRate", {
                              rate: report.summary.completionRate,
                            })
                          : tr("ad.reports.kpiCompletedNone")
                      }}
                    </p>
                  </article>

                  <article class="admin-health-stat" data-kpi="lost">
                    <div class="admin-metric-cell__top">
                      <span class="admin-metric-cell__label">{{
                        tr("ad.reports.kpiLost")
                      }}</span>
                      <span
                        v-if="lostDelta"
                        class="admin-delta"
                        :class="`admin-delta--${lostDelta.tone}`"
                        :title="lostDelta.detail"
                        data-kpi-delta="lost"
                        >{{ lostDelta.label }}</span
                      >
                    </div>
                    <p class="admin-health-stat__value">{{ lostTotal }}</p>
                    <p class="admin-metric-cell__sub">
                      {{
                        tr("ad.reports.kpiLostBreakdown", {
                          cancelled: report.summary.cancelledCount,
                          noShow: report.summary.noShowCount,
                        })
                      }}
                    </p>
                  </article>
                </div>
              </div>

              <div
                v-if="outcomeBarSegments.length"
                class="admin-outcome-summary"
                aria-hidden="true"
              >
                <div class="admin-outcome-bar">
                  <span
                    v-for="seg in outcomeBarSegments"
                    :key="seg.key"
                    class="admin-outcome-bar__seg"
                    :class="`admin-outcome-bar__seg--${seg.tone}`"
                    :style="{ width: seg.width }"
                  />
                </div>
              </div>

              <ul class="admin-report-list" role="list">
                <li
                  v-for="item in outcomes"
                  :key="item.key"
                  class="admin-report-stat-row"
                  :class="{ 'is-zero': item.count === 0 }"
                >
                  <span class="admin-outcome-label">
                    <span
                      class="admin-outcome-dot"
                      :class="`admin-outcome-dot--${item.dot}`"
                      aria-hidden="true"
                    />
                    {{ item.label }}
                  </span>
                  <span class="admin-outcome-count">{{ item.count }}</span>
                </li>
              </ul>
            </section>

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
                  v-for="(row, index) in report.topPackages"
                  :key="`${row.slug}-${row.treatmentMode}-${row.durationMinutes}`"
                  class="admin-report-bar-row"
                >
                  <div class="admin-report-bar-row__top">
                    <span class="admin-report-rank-cell">
                      <span class="admin-report-rank" aria-hidden="true">{{
                        index + 1
                      }}</span>
                      <span class="admin-cell-main">
                        {{ language === "id" ? row.nameId : row.nameEn
                        }}<span v-if="row.isVip" class="admin-vip">{{
                          tr("bookings.vip")
                        }}</span>
                        <span class="admin-record-secondary">
                          ·
                          {{
                            tr(`bookings.treatmentMode.${row.treatmentMode}`)
                          }}
                          · {{ row.durationMinutes }}
                          {{ tr("bookings.minutes") }}
                        </span>
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
                        width: meterWidthPercent(row.count, maxPackageCount),
                      }"
                    />
                  </div>
                </li>
              </ol>
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
                  :class="{ 'is-zero': channel.count === 0 }"
                >
                  <div class="admin-report-bar-row__top">
                    <span>{{ tr(`ad.reports.channel.${channel.key}`) }}</span>
                    <span class="admin-report-bar-row__metric">
                      <strong>{{ channel.count }}</strong>
                      <span
                        v-if="channelTotal > 0"
                        class="admin-record-secondary"
                      >
                        {{ Math.round((channel.count / channelTotal) * 100) }}%
                      </span>
                    </span>
                  </div>
                  <div class="admin-report-meter" aria-hidden="true">
                    <span
                      class="admin-report-meter__fill admin-report-meter__fill--soft"
                      :style="{
                        width:
                          channel.count > 0
                            ? meterWidthPercent(channel.count, channelTotal)
                            : '0%',
                      }"
                    />
                  </div>
                </li>
              </ul>
            </section>
          </div>
        </div>
      </div>
    </AdminLayout>
  </div>
</template>
