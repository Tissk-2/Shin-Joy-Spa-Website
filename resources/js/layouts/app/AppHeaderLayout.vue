<script setup lang="ts">
import AppContent from "@/components/AppContent.vue";
import AppHeader from "@/components/AppHeader.vue";
import AppShell from "@/components/AppShell.vue";
import { useLanguage } from "@/composables/useLanguage";
import type { BreadcrumbItemType } from "@/types";

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), {
  breadcrumbs: () => [],
});
const { tr } = useLanguage();
</script>

<template>
  <div class="account-shell">
    <a href="#account-main" class="account-skip">{{ tr("ad.skip") }}</a>
    <AppShell variant="header">
      <AppHeader :breadcrumbs="breadcrumbs" />
      <AppContent class="account-content" id="account-main" tabindex="-1"
        ><slot
      /></AppContent>
    </AppShell>
  </div>
</template>
