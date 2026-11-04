<script setup lang="ts">
import AppContent from "@/components/AppContent.vue";
import AppHeader from "@/components/AppHeader.vue";
import AppShell from "@/components/AppShell.vue";
import AppSidebar from "@/components/AppSidebar.vue";
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
    <AppShell variant="sidebar">
      <AppSidebar />
      <AppContent variant="sidebar" class="account-content">
        <AppHeader sidebar :breadcrumbs="breadcrumbs" />
        <div
          id="account-main"
          tabindex="-1"
          class="flex min-w-0 flex-1 flex-col"
        >
          <slot />
        </div>
      </AppContent>
    </AppShell>
  </div>
</template>
