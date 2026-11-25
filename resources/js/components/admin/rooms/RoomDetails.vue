<script setup lang="ts">
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import type { AdminRoom } from "@/types";
const props = defineProps<{
  room: AdminRoom;
  generatedAt: string;
  section: "treatments" | "blocks";
}>();
const { language, tr } = useLanguage();
// One line per package and treatment type, so a room with six durations reads
// as two short lines instead of six stacked boxes.
const treatmentGroups = computed(() => {
  const groups = new Map<
    string,
    {
      key: string;
      name: string;
      doubleTherapist: boolean;
      durations: number[];
      inactive: boolean;
    }
  >();
  for (const variant of props.room.compatibleVariants) {
    const key = `${variant.serviceNameId}\u0000${variant.serviceNameEn}\u0000${variant.treatmentMode}`;
    let group = groups.get(key);
    if (!group) {
      group = {
        key,
        name:
          language.value === "id"
            ? variant.serviceNameId
            : variant.serviceNameEn,
        doubleTherapist: variant.treatmentMode !== "regular",
        durations: [],
        inactive: false,
      };
      groups.set(key, group);
    }
    if (!group.durations.includes(variant.durationMinutes))
      group.durations.push(variant.durationMinutes);
    group.inactive ||= [
      variant.variantStatus,
      variant.serviceStatus,
      variant.categoryStatus,
    ].some((status) => status !== "published");
  }
  for (const group of groups.values()) group.durations.sort((a, b) => a - b);
  return [...groups.values()];
});
const omitted = computed(() =>
  Math.max(
    0,
    props.room.currentBlockCount +
      props.room.upcomingBlockCount -
      props.room.blocks.length,
  ),
);
const dateTime = (value: string) =>
  new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    timeZone: "Asia/Jakarta",
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).format(new Date(value)) + " WIB";
</script>

<template>
  <div v-if="section === 'treatments'" class="admin-room-details">
    <p
      v-if="room.compatibleVariants.length === 0"
      class="admin-record-secondary"
    >
      {{ tr("ad.rooms.noTreatments") }}
    </p>
    <ul v-else role="list" class="admin-room-treatments">
      <li v-for="group in treatmentGroups" :key="group.key">
        <span class="admin-room-treatments__name">{{ group.name }}</span>
        <span class="admin-record-secondary"
          >{{
            tr(
              group.doubleTherapist
                ? "ad.rooms.doubleTherapist"
                : "ad.rooms.regular",
            )
          }}
          · {{ group.durations.join(" · ") }} {{ tr("ad.rooms.minutes") }}</span
        >
        <span v-if="group.inactive" class="admin-room-treatments__warning">{{
          tr("ad.rooms.inactiveCatalog")
        }}</span>
      </li>
    </ul>
  </div>
  <div v-else class="admin-room-details">
    <p
      v-if="room.currentBlockCount + room.upcomingBlockCount === 0"
      class="admin-record-secondary"
    >
      {{ tr("ad.rooms.noBlocks") }}
    </p>
    <p v-else class="admin-room-details__count">
      {{
        tr("ad.rooms.blockCounts", {
          current: room.currentBlockCount,
          upcoming: room.upcomingBlockCount,
        })
      }}
    </p>
    <ul v-if="room.blocks.length > 0" role="list" class="admin-room-blocks">
      <li v-for="block in room.blocks" :key="block.id">
        <p class="admin-room-blocks__when">
          {{
            tr(
              Date.parse(block.startsAt) <= Date.parse(generatedAt)
                ? "ad.rooms.current"
                : "ad.rooms.upcoming",
            )
          }}
        </p>
        <p class="admin-record-secondary">
          <time :datetime="block.startsAt">{{ dateTime(block.startsAt) }}</time>
          –
          <time :datetime="block.endsAt">{{ dateTime(block.endsAt) }}</time>
        </p>
        <p class="admin-room-blocks__reason">{{ block.reason }}</p>
      </li>
    </ul>
    <p v-if="omitted > 0" class="admin-record-secondary">
      {{ tr("ad.rooms.omittedBlocks", { count: omitted }) }}
    </p>
  </div>
</template>
