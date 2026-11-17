<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { useLanguage } from "@/composables/useLanguage";
import AppLayout from "@/layouts/app/AppSidebarLayout.vue";
import type { BreadcrumbItemType } from "@/types";

interface Props {
  breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
  breadcrumbs: () => [],
});

const { tr } = useLanguage();
</script>

<template>
  <!-- Same description and head-key as MainLayout, AuthLayout and AdminLayout. -->
  <Head>
    <meta
      head-key="description"
      name="description"
      :content="tr('meta.tagline')"
    />
  </Head>
  <AppLayout :breadcrumbs="breadcrumbs">
    <slot />
  </AppLayout>
</template>
