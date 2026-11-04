<script setup lang="ts">
import { useLanguage } from "@/composables/useLanguage";
import type { RoomBlockData } from "@/types";
defineProps<{ block: RoomBlockData }>();
const { tr, language } = useLanguage();
const display = (value: string) =>
  new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    timeZone: "Asia/Jakarta",
    dateStyle: "medium",
    timeStyle: "short",
    hourCycle: "h23",
  }).format(new Date(value)) + " WIB";
</script>

<template>
  <dl class="min-w-0 space-y-3 break-words text-sm">
    <div>
      <dt class="font-semibold">{{ tr("ad.blocks.original") }}</dt>
      <dd>
        <time :datetime="block.startsAt">{{ display(block.startsAt) }}</time> →
        <time :datetime="block.endsAt">{{ display(block.endsAt) }}</time>
      </dd>
      <dd class="whitespace-pre-wrap">{{ block.reason }}</dd>
    </div>
    <template v-if="block.termination">
      <div>
        <dt class="font-semibold">{{ tr("ad.blocks.effective") }}</dt>
        <dd v-if="block.termination.kind === 'cancel_future'">
          {{ tr("ad.blocks.emptyInterval") }}
        </dd>
        <dd v-else>
          <time :datetime="block.startsAt">{{ display(block.startsAt) }}</time>
          →
          <time :datetime="block.effectiveEndsAt">{{
            display(block.effectiveEndsAt!)
          }}</time>
        </dd>
      </div>
      <div>
        <dt class="font-semibold">
          {{ tr(`ad.blocks.${block.termination.kind}`) }}
        </dt>
        <dd>
          <time :datetime="block.termination.occurredAt">{{
            display(block.termination.occurredAt)
          }}</time>
        </dd>
        <dd class="whitespace-pre-wrap">{{ block.termination.reason }}</dd>
      </div>
      <div>
        <dt class="font-semibold">{{ tr("ad.blocks.recordedBy") }}</dt>
        <dd>
          {{ block.termination.actorName }} · {{ block.termination.actorRole }}
        </dd>
      </div>
    </template>
  </dl>
</template>
