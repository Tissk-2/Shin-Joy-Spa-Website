<script setup lang="ts">
import { computed } from "vue";

import { useLanguage } from "@/composables/useLanguage";
import type { CatalogStatus } from "@/types";

const props = defineProps<{
  status: CatalogStatus;
}>();

const { tr } = useLanguage();
const label = computed(() => tr(`ad.catalog.status.${props.status}`));
</script>

<template>
  <span
    class="catalog-status"
    :class="`catalog-status--${props.status}`"
    role="status"
    :data-catalog-status="props.status"
  >
    {{ label }}
  </span>
</template>
