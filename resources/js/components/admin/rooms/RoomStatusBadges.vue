<script setup lang="ts">
import { useLanguage } from "@/composables/useLanguage";
import type { AdminRoom } from "@/types";
defineProps<{ room: AdminRoom }>();
const { tr } = useLanguage();
</script>

<template>
  <div class="flex flex-wrap gap-1.5">
    <span
      class="admin-status whitespace-nowrap"
      :class="
        room.isActive ? 'admin-status--confirmed' : 'admin-status--cancelled'
      "
    >
      {{ tr(room.isActive ? "ad.rooms.enabled" : "ad.rooms.disabled") }}
    </span>
    <span
      v-if="room.currentBlockCount > 0"
      class="admin-status admin-status--pending whitespace-nowrap"
    >
      {{ tr("ad.rooms.blocked") }}
    </span>
  </div>
</template>
