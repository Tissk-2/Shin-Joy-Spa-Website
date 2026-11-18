<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import { computed } from "vue";

import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type {
  AdminScheduleBookingItem,
  AdminScheduleData,
  AdminScheduleRoom,
  AdminScheduleUnassigned,
} from "@/types";

const props = defineProps<{ schedule: AdminScheduleData }>();
const { tr, language } = useLanguage();

// The working day the timetable always shows; anything outside it widens the view.
const OPEN_MINUTES = 9 * 60;
const CLOSE_MINUTES = 21 * 60;

const jakartaDay = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Jakarta",
});

const isToday = computed(() => props.schedule.date === props.schedule.today);
const dayLabel = computed(() => {
  const [year, month, day] = props.schedule.date.split("-").map(Number);
  return new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
});

function clock(iso: string): string {
  const instant = new Date(iso);
  const sameDay = jakartaDay.format(instant) === props.schedule.date;

  return new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    timeZone: "Asia/Jakarta",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
    ...(sameDay ? {} : { day: "numeric", month: "short" }),
  }).format(instant);
}

function packageName(
  item: AdminScheduleBookingItem | AdminScheduleUnassigned,
): string {
  return language.value === "id" ? item.packageNameId : item.packageNameEn;
}

function bookingHref(reference: string): string {
  return `/admin/bookings/${encodeURIComponent(reference)}`;
}

function goTo(date: string): void {
  if (/^\d{4}-\d{2}-\d{2}$/.test(date) && date !== props.schedule.date)
    router.get("/admin/schedule", { date });
}

// Minutes since midnight of the shown day in Jakarta, kept inside that day.
function minuteOfDay(iso: string): number {
  const midnight = Date.parse(`${props.schedule.date}T00:00:00+07:00`);
  return Math.min(1440, Math.max(0, (Date.parse(iso) - midnight) / 60000));
}

const span = computed(() => {
  let start = OPEN_MINUTES;
  let end = CLOSE_MINUTES;
  // Only bookings widen the hours; a closure that began the night before is
  // clipped at the edge instead of squeezing the whole day into a sliver.
  for (const room of props.schedule.rooms)
    for (const item of room.items) {
      if (item.kind !== "booking") continue;
      start = Math.min(start, Math.floor(minuteOfDay(item.startsAt) / 60) * 60);
      end = Math.max(end, Math.ceil(minuteOfDay(item.endsAt) / 60) * 60);
    }
  return { start, end };
});
const hours = computed(() => {
  const marks: number[] = [];
  for (let minute = span.value.start; minute <= span.value.end; minute += 60)
    marks.push(minute);
  return marks;
});

function percent(minute: number): number {
  const { start, end } = span.value;
  return ((minute - start) / (end - start)) * 100;
}

function place(startsAt: string, endsAt: string): Record<string, string> {
  const start = Math.max(span.value.start, minuteOfDay(startsAt));
  const end = Math.min(span.value.end, Math.max(start, minuteOfDay(endsAt)));
  return {
    left: `${percent(start)}%`,
    width: `${percent(end) - percent(start)}%`,
  };
}

// The share of a booking's slot spent on the treatment; the rest is cleaning.
function treatmentShare(item: AdminScheduleBookingItem): string {
  const start = minuteOfDay(item.startsAt);
  const whole = minuteOfDay(item.endsAt) - start;
  if (whole <= 0) return "100%";
  return `${((minuteOfDay(item.treatmentEndsAt) - start) / whole) * 100}%`;
}

const nowAt = computed(() => {
  if (!isToday.value) return null;
  const minute = minuteOfDay(new Date().toISOString());
  return minute >= span.value.start && minute <= span.value.end
    ? `${percent(minute)}%`
    : null;
});

function hourLabel(minute: number): string {
  return `${String(Math.floor(minute / 60)).padStart(2, "0")}:00`;
}

// Closures on the shown day, for the agenda view where rooms are not drawn.
const closures = computed(() =>
  props.schedule.rooms.flatMap((room: AdminScheduleRoom) =>
    room.items
      .filter((item) => item.kind === "block")
      .map((item) => ({ room, block: item })),
  ),
);

function slotTitle(item: AdminScheduleBookingItem): string {
  return [
    `${clock(item.startsAt)}–${clock(item.treatmentEndsAt)}`,
    item.customerName,
    packageName(item),
    tr("ad.list.guests", { n: item.guests }),
    tr(`ad.status.${item.status}`),
  ].join(" · ");
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout :title="tr('ad.schedule.title')" :subtitle="dayLabel">
      <template #actions>
        <nav class="admin-daynav" :aria-label="tr('ad.schedule.date')">
          <Link
            data-schedule-previous
            :href="`/admin/schedule?date=${schedule.previousDate}`"
            class="admin-button admin-daynav__step"
            :aria-label="tr('ad.schedule.previous')"
            :title="tr('ad.schedule.previous')"
            ><ChevronLeft aria-hidden="true"
          /></Link>
          <Link
            data-schedule-today
            :href="`/admin/schedule?date=${schedule.today}`"
            class="admin-button"
            :aria-current="isToday ? 'date' : undefined"
            >{{ tr("ad.schedule.today") }}</Link
          >
          <Link
            data-schedule-next
            :href="`/admin/schedule?date=${schedule.nextDate}`"
            class="admin-button admin-daynav__step"
            :aria-label="tr('ad.schedule.next')"
            :title="tr('ad.schedule.next')"
            ><ChevronRight aria-hidden="true"
          /></Link>
          <label for="schedule-date" class="sr-only">{{
            tr("ad.schedule.date")
          }}</label>
          <input
            id="schedule-date"
            type="date"
            name="date"
            :value="schedule.date"
            class="admin-control admin-daynav__date"
            @change="goTo(($event.target as HTMLInputElement).value)"
          />
        </nav>
      </template>
      <div class="admin-content">
        <p class="admin-loading">{{ tr("ad.schedule.timezone") }}</p>

        <template v-if="!schedule.schedulingEnabled">
          <p class="admin-callout" role="status" data-schedule-off>
            {{ tr("ad.schedule.off") }}
          </p>
          <section
            class="admin-panel"
            aria-labelledby="schedule-agenda-title"
            data-schedule-agenda
          >
            <header class="admin-panel__head">
              <h2 id="schedule-agenda-title">
                {{ tr("ad.schedule.agenda") }}
                <span class="admin-tab__count">{{
                  schedule.agenda.length
                }}</span>
              </h2>
            </header>
            <p v-if="!schedule.agenda.length" class="admin-panel__empty">
              {{ tr("ad.schedule.agendaEmpty") }}
            </p>
            <ol v-else class="admin-agenda">
              <li
                v-for="booking in schedule.agenda"
                :key="booking.reference"
                :data-booking-row="booking.reference"
              >
                <time class="admin-agenda__time">{{ booking.time }}</time>
                <div class="admin-agenda__body">
                  <Link
                    class="admin-row-link"
                    :href="bookingHref(booking.reference)"
                    :title="booking.reference"
                    ><span class="admin-row-link__name">{{
                      booking.customerName
                    }}</span></Link
                  >
                  <span class="admin-record-secondary"
                    >{{ packageName(booking) }} ·
                    {{ booking.durationMinutes }}
                    {{ tr("bookings.minutes") }} ·
                    {{ tr("ad.list.guests", { n: booking.guests }) }}</span
                  >
                </div>
                <span
                  class="admin-status"
                  :class="`admin-status--${booking.status}`"
                  >{{ tr(`ad.status.${booking.status}`) }}</span
                >
              </li>
            </ol>
          </section>
          <section
            v-if="closures.length"
            class="admin-panel"
            aria-labelledby="schedule-closures-title"
            data-schedule-closures
          >
            <header class="admin-panel__head">
              <h2 id="schedule-closures-title">
                {{ tr("ad.schedule.closures") }}
              </h2>
            </header>
            <ul class="admin-agenda">
              <li
                v-for="{ room, block } in closures"
                :key="`${room.id}-${block.id}`"
                data-schedule-item="block"
              >
                <span class="admin-agenda__time admin-agenda__time--wide"
                  ><time :datetime="block.startsAt">{{
                    clock(block.startsAt)
                  }}</time
                  >–<time :datetime="block.endsAt">{{
                    clock(block.endsAt)
                  }}</time></span
                >
                <div class="admin-agenda__body">
                  <span class="admin-row-link__name"
                    >{{ room.code }} · {{ room.name }}</span
                  >
                  <span class="admin-record-secondary whitespace-pre-wrap">{{
                    block.reason
                  }}</span>
                </div>
                <span class="admin-status admin-status--cancelled">{{
                  tr("ad.schedule.block")
                }}</span>
              </li>
            </ul>
          </section>
        </template>

        <template v-else>
          <p v-if="!schedule.rooms.length" class="admin-empty">
            {{ tr("ad.schedule.noRooms") }}
          </p>
          <section
            v-else
            class="admin-panel"
            aria-labelledby="schedule-rooms-title"
          >
            <header class="admin-panel__head">
              <h2 id="schedule-rooms-title">{{ tr("ad.schedule.rooms") }}</h2>
            </header>
            <div class="admin-timetable-scroll">
              <div class="admin-timetable">
                <div class="admin-timetable__axis" aria-hidden="true">
                  <span
                    v-for="minute in hours"
                    :key="minute"
                    class="admin-timetable__hour"
                    :style="{ left: `${percent(minute)}%` }"
                    >{{ hourLabel(minute) }}</span
                  >
                </div>
                <ol class="admin-timetable__rooms">
                  <li
                    v-for="room in schedule.rooms"
                    :key="room.id"
                    class="admin-timetable__room"
                    :class="{
                      'admin-timetable__room--inactive': !room.isActive,
                    }"
                    :data-schedule-room="room.code"
                  >
                    <h3 class="admin-timetable__label">
                      <span>{{ room.name }}</span>
                      <span class="admin-record-secondary"
                        >{{ room.code }} ·
                        {{ tr("ad.schedule.capacity", { count: room.capacity })
                        }}<template v-if="!room.isActive">
                          · {{ tr("ad.schedule.inactive") }}</template
                        ></span
                      >
                    </h3>
                    <div class="admin-timetable__track">
                      <span
                        v-for="minute in hours"
                        :key="minute"
                        class="admin-timetable__line"
                        :style="{ left: `${percent(minute)}%` }"
                        aria-hidden="true"
                      />
                      <span
                        v-if="nowAt"
                        class="admin-timetable__now"
                        :style="{ left: nowAt }"
                        aria-hidden="true"
                      />
                      <p v-if="!room.items.length" class="sr-only">
                        {{ tr("ad.schedule.free") }}
                      </p>
                      <ul v-else class="admin-timetable__items">
                        <li
                          v-for="item in room.items"
                          :key="
                            item.kind === 'booking'
                              ? `booking-${item.reference}`
                              : `block-${item.id}`
                          "
                          class="admin-slot"
                          :class="
                            item.kind === 'booking'
                              ? `admin-slot--${item.status}`
                              : 'admin-slot--block'
                          "
                          :style="place(item.startsAt, item.endsAt)"
                          :data-schedule-item="item.kind"
                        >
                          <template v-if="item.kind === 'booking'">
                            <Link
                              class="admin-slot__body"
                              :href="bookingHref(item.reference)"
                              :title="slotTitle(item)"
                              :style="{ width: treatmentShare(item) }"
                              ><span class="admin-slot__time"
                                ><time :datetime="item.startsAt">{{
                                  clock(item.startsAt)
                                }}</time
                                >–<time :datetime="item.treatmentEndsAt">{{
                                  clock(item.treatmentEndsAt)
                                }}</time></span
                              >
                              <span class="admin-slot__name">{{
                                item.customerName
                              }}</span>
                              <span class="sr-only"
                                >· {{ packageName(item) }} ·
                                {{ tr("ad.list.guests", { n: item.guests }) }} ·
                                {{ tr(`ad.status.${item.status}`) }}</span
                              ></Link
                            >
                            <span
                              v-if="item.endsAt !== item.treatmentEndsAt"
                              class="admin-slot__cleaning"
                              :title="
                                tr('ad.schedule.cleaningUntil', {
                                  time: clock(item.endsAt),
                                })
                              "
                              ><span class="sr-only">{{
                                tr("ad.schedule.cleaningUntil", {
                                  time: clock(item.endsAt),
                                })
                              }}</span></span
                            >
                          </template>
                          <span
                            v-else
                            class="admin-slot__body"
                            :title="item.reason"
                            ><span class="admin-slot__time"
                              ><time :datetime="item.startsAt">{{
                                clock(item.startsAt)
                              }}</time
                              >–<time :datetime="item.endsAt">{{
                                clock(item.endsAt)
                              }}</time></span
                            >
                            <span class="admin-slot__name">{{
                              tr("ad.schedule.block")
                            }}</span>
                            <span class="sr-only"
                              >· {{ item.reason }}</span
                            ></span
                          >
                        </li>
                      </ul>
                    </div>
                  </li>
                </ol>
              </div>
            </div>
          </section>
          <section
            class="admin-panel"
            aria-labelledby="schedule-unassigned-title"
            data-schedule-unassigned
          >
            <header class="admin-panel__head">
              <h2 id="schedule-unassigned-title">
                {{ tr("ad.schedule.unassigned") }}
                <span class="admin-tab__count">{{
                  schedule.unassigned.length
                }}</span>
              </h2>
              <p>{{ tr("ad.schedule.unassignedHelp") }}</p>
            </header>
            <p v-if="!schedule.unassigned.length" class="admin-panel__empty">
              {{ tr("ad.schedule.none") }}
            </p>
            <ol v-else class="admin-agenda">
              <li
                v-for="booking in schedule.unassigned"
                :key="booking.reference"
                :data-booking-row="booking.reference"
              >
                <time class="admin-agenda__time">{{
                  clock(`${schedule.date}T${booking.time}:00+07:00`)
                }}</time>
                <div class="admin-agenda__body">
                  <Link
                    class="admin-row-link"
                    :href="bookingHref(booking.reference)"
                    :title="booking.reference"
                    ><span class="admin-row-link__name">{{
                      booking.customerName
                    }}</span></Link
                  >
                  <span class="admin-record-secondary"
                    >{{ packageName(booking) }} ·
                    {{ tr("ad.list.guests", { n: booking.guests }) }}</span
                  >
                </div>
                <span
                  class="admin-status"
                  :class="`admin-status--${booking.status}`"
                  >{{ tr(`ad.status.${booking.status}`) }}</span
                >
              </li>
            </ol>
          </section>
        </template>
      </div>
    </AdminLayout>
  </div>
</template>
