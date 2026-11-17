<script setup lang="ts">
interface Props {
  title: string;
  description?: string;
}

defineProps<Props>();
</script>

<template>
  <header>
    <!-- h2: the settings layout's h1 "Settings" is the only heading above it. -->
    <h2 class="mb-0.5 text-base font-medium">{{ title }}</h2>
    <p v-if="description" class="text-sm text-muted-foreground">
      {{ description }}
    </p>
  </header>
</template>
