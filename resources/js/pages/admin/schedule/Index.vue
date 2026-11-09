<script setup lang="ts">
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import { useLanguage } from "@/composables/useLanguage";
import type {
  AdminScheduleBookingItem,
  AdminScheduleData,
  AdminScheduleUnassigned,
} from "@/types";

const props = defineProps<{ schedule: AdminScheduleData }>();
const { tr, language } = useLanguage();

const jakartaDay = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Jakarta",
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
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.schedule.title')"
      :subtitle="tr('ad.schedule.subtitle')"
    >
      <div class="admin-content space-y-5">
        <div class="admin-filter-panel space-y-3">
          <form
            action="/admin/schedule"
            method="get"
            class="flex flex-wrap items-end gap-3"
          >
            <div class="min-w-0 flex-1">
              <label
                for="schedule-date"
                class="mb-1 block text-sm font-semibold"
                >{{ tr("ad.schedule.date") }}</label
              >
              <input
                id="schedule-date"
                type="date"
                name="date"
                :value="schedule.date"
                class="admin-control"
              />
            </div>
            <button type="submit" class="admin-button">
              {{ tr("ad.schedule.show") }}
            </button>
          </form>
          <nav
            class="flex flex-wrap gap-3"
            :aria-label="tr('ad.schedule.date')"
          >
            <a
              data-schedule-previous
              :href="`/admin/schedule?date=${schedule.previousDate}`"
              class="admin-button"
              >{{ tr("ad.schedule.previous") }}</a
            >
            <a
              data-schedule-today
              :href="`/admin/schedule?date=${schedule.today}`"
              class="admin-button"
              >{{ tr("ad.schedule.today") }}</a
            >
            <a
              data-schedule-next
              :href="`/admin/schedule?date=${schedule.nextDate}`"
              class="admin-button"
              >{{ tr("ad.schedule.next") }}</a
            >
          </nav>
          <p class="text-sm text-[var(--admin-muted)]">
            {{ tr("ad.schedule.timezone") }}
          </p>
        </div>
        <p
          v-if="!schedule.schedulingEnabled"
          role="status"
          class="admin-filter-panel"
          data-schedule-off
        >
          {{ tr("ad.schedule.off") }}
        </p>
        <p v-if="!schedule.rooms.length" class="admin-empty">
          {{ tr("ad.schedule.noRooms") }}
        </p>
        <ol v-else class="space-y-3">
          <li
            v-for="room in schedule.rooms"
            :key="room.id"
            class="admin-filter-panel min-w-0 space-y-2 break-words"
            :data-schedule-room="room.code"
          >
            <h2 class="font-bold">{{ room.code }} · {{ room.name }}</h2>
            <p class="text-sm">
              {{ tr("ad.schedule.capacity", { count: room.capacity }) }}
              <template v-if="!room.isActive">
                · {{ tr("ad.schedule.inactive") }}
              </template>
            </p>
            <p v-if="!room.items.length">{{ tr("ad.schedule.free") }}</p>
            <ul v-else class="space-y-2">
              <li
                v-for="item in room.items"
                :key="
                  item.kind === 'booking'
                    ? `booking-${item.reference}`
                    : `block-${item.id}`
                "
                :data-schedule-item="item.kind"
              >
                <template v-if="item.kind === 'booking'">
                  <p>
                    <time :datetime="item.startsAt">{{
                      clock(item.startsAt)
                    }}</time
                    >–<time :datetime="item.treatmentEndsAt">{{
                      clock(item.treatmentEndsAt)
                    }}</time>
                    <template v-if="item.endsAt !== item.treatmentEndsAt">
                      ·
                      {{
                        tr("ad.schedule.cleaningUntil", {
                          time: clock(item.endsAt),
                        })
                      }}
                    </template>
                  </p>
                  <p>
                    <a
                      :href="`/admin/bookings/${item.reference}`"
                      class="font-semibold text-[var(--admin-plum)] underline"
                      >{{ item.reference }}</a
                    >
                    · {{ tr(`ad.status.${item.status}`) }} ·
                    {{ item.customerName }} ·
                    {{ tr("ad.schedule.guests", { count: item.guests }) }} ·
                    {{ packageName(item) }}
                  </p>
                </template>
                <template v-else>
                  <p>
                    {{ tr("ad.schedule.block") }} ·
                    <time :datetime="item.startsAt">{{
                      clock(item.startsAt)
                    }}</time
                    >–<time :datetime="item.endsAt">{{
                      clock(item.endsAt)
                    }}</time>
                  </p>
                  <p class="whitespace-pre-wrap">{{ item.reason }}</p>
                </template>
              </li>
            </ul>
          </li>
        </ol>
        <section
          class="admin-filter-panel min-w-0 space-y-2 break-words"
          data-schedule-unassigned
        >
          <h2 class="font-bold">{{ tr("ad.schedule.unassigned") }}</h2>
          <p class="text-sm">{{ tr("ad.schedule.unassignedHelp") }}</p>
          <p v-if="!schedule.unassigned.length">{{ tr("ad.schedule.none") }}</p>
          <ul v-else class="space-y-2">
            <li v-for="booking in schedule.unassigned" :key="booking.reference">
              {{ clock(`${schedule.date}T${booking.time}:00+07:00`) }} ·
              <a
                :href="`/admin/bookings/${booking.reference}`"
                class="font-semibold text-[var(--admin-plum)] underline"
                >{{ booking.reference }}</a
              >
              · {{ tr(`ad.status.${booking.status}`) }} ·
              {{ booking.customerName }} ·
              {{ tr("ad.schedule.guests", { count: booking.guests }) }} ·
              {{ packageName(booking) }}
            </li>
          </ul>
        </section>
      </div>
    </AdminLayout>
  </div>
</template>
