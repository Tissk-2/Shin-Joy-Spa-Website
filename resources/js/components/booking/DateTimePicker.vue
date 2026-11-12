<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import { todayIso } from "@/lib/booking";

// The booking step's date and time: a month calendar that is always open, the
// time list beside it, and the typed date field kept as a fallback under the
// calendar. Shared by the customer booking page and the staff "new booking" page.
const props = withDefaults(
  defineProps<{
    date: string;
    time: string;
    times: readonly string[];
    dateId: string;
    timeId: string;
    dateLabel: string;
    timeLabel: string;
    dateInvalid?: boolean;
    timeInvalid?: boolean;
    dateDescribedBy?: string;
    timeDescribedBy?: string;
    // Why the time list is empty, when the page knows better than "nothing free".
    emptyText?: string;
    tone?: "site" | "admin";
  }>(),
  { tone: "site" },
);
const emit = defineEmits<{
  "update:date": [value: string];
  "update:time": [value: string];
}>();
const { language, tr } = useLanguage();

// Calendar days are handled as UTC midnights so no time zone can shift a day.
const DAY = 86_400_000;
const toMs = (iso: string) => Date.parse(`${iso}T00:00:00Z`);
const toIso = (ms: number) => new Date(ms).toISOString().slice(0, 10);
const isDate = (value: string) =>
  /^\d{4}-\d{2}-\d{2}$/.test(value) && toIso(toMs(value)) === value;
const addDays = (iso: string, days: number) => toIso(toMs(iso) + days * DAY);
const monthOf = (iso: string) => `${iso.slice(0, 8)}01`;
const addMonths = (iso: string, months: number) => {
  const [year, month, day] = iso.split("-").map(Number);
  const last = new Date(Date.UTC(year, month - 1 + months + 1, 0)).getUTCDate();
  return toIso(Date.UTC(year, month - 1 + months, Math.min(day, last)));
};
const weekday = (iso: string) => (new Date(toMs(iso)).getUTCDay() + 6) % 7; // Monday = 0

const today = todayIso();
// A date already in the past (an old draft) still shows with its times; the form's
// own validation reports it. Only new picks are kept to today and later.
const chosen = computed(() => (isDate(props.date) ? props.date : ""));
const view = ref(monthOf(chosen.value || today));
const focusDate = ref(
  chosen.value && chosen.value >= today ? chosen.value : today,
);
watch(chosen, (value) => {
  if (!value) return;
  view.value = monthOf(value);
  focusDate.value = value;
});

const locale = computed(() => (language.value === "id" ? "id-ID" : "en-GB"));
const format = (iso: string, options: Intl.DateTimeFormatOptions) =>
  new Intl.DateTimeFormat(locale.value, { timeZone: "UTC", ...options }).format(
    new Date(toMs(iso)),
  );
const longDate = (iso: string) =>
  format(iso, {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
const monthLabel = computed(() =>
  format(view.value, { month: "long", year: "numeric" }),
);
// 7 to 13 January 2025 run Monday to Sunday.
const weekdays = computed(() =>
  Array.from({ length: 7 }, (_, index) => {
    const iso = `2025-01-${String(6 + index).padStart(2, "0")}`;
    return {
      short: format(iso, { weekday: "short" }),
      long: format(iso, { weekday: "long" }),
    };
  }),
);
// Always six weeks, so the calendar keeps its height from month to month.
const days = computed(() => {
  const start = addDays(view.value, -weekday(view.value));
  return Array.from({ length: 42 }, (_, index) => {
    const iso = addDays(start, index);
    return {
      iso,
      number: Number(iso.slice(8)),
      inMonth: iso.slice(0, 7) === view.value.slice(0, 7),
      past: iso < today,
    };
  });
});
const weeks = computed(() =>
  Array.from({ length: 6 }, (_, week) =>
    days.value.slice(week * 7, week * 7 + 7),
  ),
);
// One day in the grid takes Tab focus; the arrow keys move it (roving tabindex).
const tabbable = computed(
  () =>
    days.value.find((day) => day.iso === focusDate.value && !day.past)?.iso ??
    days.value.find((day) => day.inMonth && !day.past)?.iso,
);
const canGoBack = computed(() => view.value > monthOf(today));

const grid = ref<HTMLTableElement>();
function choose(iso: string): void {
  if (iso < today) return;
  focusDate.value = iso;
  view.value = monthOf(iso);
  emit("update:date", iso);
}
function showMonth(months: number): void {
  view.value = addMonths(view.value, months);
}
async function onKey(event: KeyboardEvent): Promise<void> {
  const from = tabbable.value ?? today;
  const moves: Record<string, () => string> = {
    ArrowLeft: () => addDays(from, -1),
    ArrowRight: () => addDays(from, 1),
    ArrowUp: () => addDays(from, -7),
    ArrowDown: () => addDays(from, 7),
    Home: () => addDays(from, -weekday(from)),
    End: () => addDays(from, 6 - weekday(from)),
    PageUp: () => addMonths(from, -1),
    PageDown: () => addMonths(from, 1),
  };
  const move = moves[event.key];
  if (!move) return;
  event.preventDefault();
  const target = move();
  const next = target < today ? today : target;
  focusDate.value = next;
  view.value = monthOf(next);
  await nextTick();
  grid.value?.querySelector<HTMLElement>(`[data-date="${next}"]`)?.focus();
}
function typed(event: Event): void {
  emit("update:date", (event.target as HTMLInputElement).value);
}
function pickTime(slot: string, event: Event): void {
  emit("update:time", slot);
  // Lift the chosen time clear of the scrolling column's fade (see its scroll-padding).
  (event.target as HTMLElement).scrollIntoView?.({ block: "nearest" });
}

const timeLabelId = computed(() => `${props.timeId}-label`);
const summary = computed(() =>
  chosen.value && props.time ? `${longDate(chosen.value)}, ${props.time}` : "",
);
</script>

<template>
  <div class="dtp" :class="`dtp--${tone}`">
    <div class="dtp__calendar">
      <label :for="dateId" class="dtp__label">{{ dateLabel }}</label>
      <div class="dtp__head">
        <button
          type="button"
          class="dtp__nav"
          data-picker-previous
          :aria-label="tr('dtp.previous')"
          :disabled="!canGoBack"
          @click="showMonth(-1)"
        >
          <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
            <path d="M10 3 5 8l5 5" />
          </svg>
        </button>
        <p :id="`${dateId}-month`" class="dtp__month" aria-live="polite">
          {{ monthLabel }}
        </p>
        <button
          type="button"
          class="dtp__nav"
          data-picker-next
          :aria-label="tr('dtp.next')"
          @click="showMonth(1)"
        >
          <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
            <path d="m6 3 5 5-5 5" />
          </svg>
        </button>
      </div>
      <table
        ref="grid"
        class="dtp__grid"
        role="grid"
        :aria-labelledby="`${dateId}-month`"
        @keydown="onKey"
      >
        <thead>
          <tr>
            <th
              v-for="name in weekdays"
              :key="name.long"
              scope="col"
              :abbr="name.long"
            >
              {{ name.short }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(week, index) in weeks" :key="index">
            <td
              v-for="day in week"
              :key="day.iso"
              role="gridcell"
              :aria-selected="day.iso === chosen ? 'true' : 'false'"
            >
              <button
                type="button"
                class="dtp__day"
                :class="{
                  'dtp__day--other': !day.inMonth,
                  'dtp__day--chosen': day.iso === chosen,
                }"
                :data-date="day.iso"
                :tabindex="day.iso === tabbable ? 0 : -1"
                :disabled="day.past"
                :aria-label="longDate(day.iso)"
                :aria-current="day.iso === today ? 'date' : undefined"
                @click="choose(day.iso)"
              >
                {{ day.number }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <div class="dtp__foot">
        <input
          :id="dateId"
          class="dtp__input"
          type="date"
          :value="date"
          :min="today"
          :aria-invalid="dateInvalid ? 'true' : undefined"
          :aria-describedby="dateDescribedBy || undefined"
          @input="typed"
        />
        <button
          type="button"
          class="dtp__today"
          data-picker-today
          @click="choose(today)"
        >
          {{ tr("dtp.today") }}
        </button>
      </div>
    </div>
    <div
      :id="timeId"
      class="dtp__times"
      role="radiogroup"
      tabindex="-1"
      :aria-labelledby="timeLabelId"
      :aria-invalid="timeInvalid ? 'true' : undefined"
      :aria-describedby="timeDescribedBy || undefined"
    >
      <p :id="timeLabelId" class="dtp__label">{{ timeLabel }}</p>
      <!-- Times show whenever the page has some: opening hours do not depend on the day. -->
      <p v-if="!times.length" class="dtp__empty">
        {{ emptyText ?? tr(chosen ? "dtp.noTimes" : "dtp.pickDay") }}
      </p>
      <div v-else class="dtp__slots">
        <label v-for="slot in times" :key="slot" class="dtp__slot">
          <input
            type="radio"
            :name="timeId"
            :value="slot"
            :checked="slot === time"
            @change="pickTime(slot, $event)"
          />
          <span>{{ slot }}</span>
        </label>
      </div>
    </div>
    <p class="dtp__summary" aria-live="polite">{{ summary }}</p>
  </div>
</template>

<style scoped>
/* Colours come from the page the picker sits on: the public site or the admin. */
.dtp--site {
  --dtp-ink: var(--ink);
  --dtp-muted: var(--text-muted);
  --dtp-line: var(--line);
  --dtp-paper: #fdfbf7;
  --dtp-accent: var(--rose);
  --dtp-on-accent: #ffffff;
  --dtp-soft: var(--rose-soft);
  --dtp-mark: var(--gold-deep);
}
.dtp--admin {
  --dtp-ink: var(--admin-ink);
  --dtp-muted: var(--admin-muted);
  --dtp-line: var(--admin-line);
  --dtp-paper: var(--admin-ivory);
  --dtp-accent: var(--admin-action);
  --dtp-on-accent: var(--admin-on-action);
  --dtp-soft: var(--admin-selected);
  --dtp-mark: var(--admin-gold);
}
.dtp {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--dtp-line);
  border-radius: 0.875rem;
  background: var(--dtp-paper);
  color: var(--dtp-ink);
}
.dtp__label {
  display: block;
  margin-bottom: 0.5rem;
  font-size: 0.8125rem;
  font-weight: 700;
  color: var(--dtp-muted);
}
.dtp__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.dtp__month {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.125rem;
}
.dtp__nav,
.dtp__day,
.dtp__today {
  cursor: pointer;
}
.dtp__nav {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border: 1px solid var(--dtp-line);
  border-radius: 0.625rem;
  background: transparent;
  color: var(--dtp-ink);
}
.dtp__nav svg {
  width: 1rem;
  height: 1rem;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.6;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.dtp__nav:hover:not(:disabled) {
  background: var(--dtp-soft);
}
.dtp__nav:disabled {
  cursor: not-allowed;
  color: var(--dtp-line);
}
.dtp__grid {
  width: 100%;
  margin-top: 0.5rem;
  border-collapse: collapse;
  table-layout: fixed;
}
.dtp__grid th {
  padding: 0.375rem 0;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--dtp-muted);
  text-align: center;
}
.dtp__grid td {
  padding: 0.125rem;
  text-align: center;
}
.dtp__day {
  position: relative;
  width: 100%;
  max-width: 2.75rem;
  aspect-ratio: 1;
  border: 0;
  border-radius: 0.625rem;
  background: transparent;
  color: var(--dtp-ink);
  font: inherit;
  font-size: 0.9375rem;
  font-variant-numeric: tabular-nums;
}
.dtp__day--other {
  color: var(--dtp-muted);
}
.dtp__day:hover:not(:disabled):not(.dtp__day--chosen) {
  background: var(--dtp-soft);
}
.dtp__day:active:not(:disabled) {
  box-shadow: inset 0 0 0 2px var(--dtp-accent);
}
.dtp__day:disabled {
  cursor: not-allowed;
  color: color-mix(in srgb, var(--dtp-muted) 45%, var(--dtp-paper));
}
/* Today keeps a small mark, whether or not it is the chosen day. */
.dtp__day[aria-current="date"]::after {
  content: "";
  position: absolute;
  left: 50%;
  bottom: 0.3rem;
  width: 0.25rem;
  height: 0.25rem;
  border-radius: 50%;
  background: var(--dtp-mark);
  transform: translateX(-50%);
}
.dtp__day--chosen {
  background: var(--dtp-accent);
  color: var(--dtp-on-accent);
  font-weight: 700;
}
.dtp__day--chosen[aria-current="date"]::after {
  background: var(--dtp-on-accent);
}
.dtp__foot {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.75rem;
}
.dtp__input {
  flex: 1;
  min-width: 0;
  min-height: 2.75rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--dtp-line);
  border-radius: 0.625rem;
  background: transparent;
  color: var(--dtp-ink);
  font: inherit;
}
.dtp__input[aria-invalid="true"] {
  border-color: var(--dtp-accent);
}
.dtp__today {
  min-height: 2.75rem;
  padding: 0 1rem;
  border: 1px solid var(--dtp-line);
  border-radius: 0.625rem;
  background: transparent;
  color: var(--dtp-ink);
  font: inherit;
  font-weight: 700;
}
.dtp__today:hover {
  background: var(--dtp-soft);
}
.dtp__times {
  min-width: 0;
}
.dtp__times[aria-invalid="true"] .dtp__label {
  color: var(--dtp-accent);
}
.dtp__empty {
  margin: 0;
  font-size: 0.875rem;
  color: var(--dtp-muted);
}
.dtp__slots {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(5.25rem, 1fr));
  gap: 0.5rem;
}
/* Each time is a native radio, stretched invisibly over its button so a click or tap lands on it. */
.dtp__slot {
  position: relative;
  display: grid;
  place-items: center;
  min-height: 2.75rem;
  border: 1px solid var(--dtp-line);
  border-radius: 0.625rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.dtp__slot input {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  margin: 0;
  opacity: 0;
  cursor: pointer;
}
.dtp__slot:hover {
  background: var(--dtp-soft);
}
.dtp__slot:has(input:checked) {
  border-color: var(--dtp-accent);
  background: var(--dtp-accent);
  color: var(--dtp-on-accent);
}
.dtp__slot:has(input:focus-visible) {
  outline: 2px solid var(--dtp-accent);
  outline-offset: 2px;
}
.dtp__summary {
  grid-column: 1 / -1;
  min-height: 1.25rem;
  margin: 0;
  font-weight: 700;
}
@media (min-width: 720px) {
  .dtp {
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    padding: 1.25rem;
    column-gap: 1.25rem;
  }
  /* Beside the calendar the times scroll in a column of the calendar's height. */
  .dtp__times {
    display: flex;
    flex-direction: column;
    padding-left: 1.25rem;
    border-left: 1px solid var(--dtp-line);
  }
  .dtp__slots {
    grid-template-columns: minmax(0, 1fr);
    align-content: start;
    max-height: 22rem;
    overflow-y: auto;
    padding: 0.25rem 0.25rem 2.5rem;
    scroll-padding-block: 0.25rem 2.5rem;
    /* The last visible time fades out to say the list scrolls; the bottom padding
       lets the final time scroll clear of the fade. */
    mask-image: linear-gradient(
      to bottom,
      #000 calc(100% - 2.5rem),
      transparent
    );
  }
}
</style>
