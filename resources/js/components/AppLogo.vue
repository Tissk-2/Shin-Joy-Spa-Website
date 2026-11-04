<script setup lang="ts">
defineProps<{ class?: string }>();
</script>

<template>
  <span
    class="account-brand-mark flex size-8 shrink-0 items-center justify-center rounded-full border border-sidebar-border text-sidebar-primary"
    aria-hidden="true"
    >SJ</span
  >
  <span
    :class="[
      'account-brand ml-1 truncate text-sidebar-foreground group-data-[collapsible=icon]:hidden',
      $props.class,
    ]"
    >Shin Joy Spa</span
  >
</template>
