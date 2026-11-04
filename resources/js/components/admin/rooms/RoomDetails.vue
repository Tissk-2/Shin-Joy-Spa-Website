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
  <div v-if="section === 'treatments'" class="space-y-1 text-xs">
    <p
      v-if="room.compatibleVariants.length === 0"
      class="text-[var(--admin-muted)]"
    >
      {{ tr("ad.rooms.noTreatments") }}
    </p>
    <ul v-else class="space-y-1.5" role="list">
      <li
        v-for="variant in room.compatibleVariants"
        :key="variant.id"
        class="rounded border border-[var(--admin-line)] bg-[var(--admin-canvas)] px-2 py-1"
      >
        <p class="font-semibold">
          {{
            language === "id" ? variant.serviceNameId : variant.serviceNameEn
          }}
        </p>
        <p>
          {{ variant.durationMinutes }} {{ tr("ad.rooms.minutes") }} ·
          {{
            tr(
              variant.treatmentMode === "regular"
                ? "ad.rooms.regular"
                : "ad.rooms.doubleTherapist",
            )
          }}
        </p>
        <p class="text-[var(--admin-muted)]">{{ variant.code }}</p>
        <span
          v-if="
            [
              variant.variantStatus,
              variant.serviceStatus,
              variant.categoryStatus,
            ].some((status) => status !== 'published')
          "
          class="text-[var(--admin-warning)]"
          >{{ tr("ad.rooms.inactiveCatalog") }}</span
        >
      </li>
    </ul>
  </div>
  <div v-else class="space-y-2 text-xs">
    <p class="font-semibold">
      {{
        tr("ad.rooms.blockCounts", {
          current: room.currentBlockCount,
          upcoming: room.upcomingBlockCount,
        })
      }}
    </p>
    <p
      v-if="room.currentBlockCount + room.upcomingBlockCount === 0"
      class="text-[var(--admin-muted)]"
    >
      {{ tr("ad.rooms.noBlocks") }}
    </p>
    <ul v-else class="space-y-2" role="list">
      <li
        v-for="block in room.blocks"
        :key="block.id"
        class="rounded border border-[var(--admin-line)] bg-[var(--admin-canvas)] px-2 py-1.5"
      >
        <p class="font-semibold text-[var(--admin-plum)]">
          {{
            tr(
              Date.parse(block.startsAt) <= Date.parse(generatedAt)
                ? "ad.rooms.current"
                : "ad.rooms.upcoming",
            )
          }}
        </p>
        <p>
          <time :datetime="block.startsAt">{{ dateTime(block.startsAt) }}</time>
        </p>
        <p>
          – <time :datetime="block.endsAt">{{ dateTime(block.endsAt) }}</time>
        </p>
        <p class="whitespace-pre-wrap break-words">{{ block.reason }}</p>
      </li>
    </ul>
    <p v-if="omitted > 0" class="text-[var(--admin-muted)]">
      {{ tr("ad.rooms.omittedBlocks", { count: omitted }) }}
    </p>
  </div>
</template>
