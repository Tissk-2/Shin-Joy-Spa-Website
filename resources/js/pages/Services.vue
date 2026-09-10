<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";

import PackageMenuCard from "@/components/PackageMenuCard.vue";
import { useLanguage } from "@/composables/useLanguage";
import MainLayout from "@/layouts/MainLayout.vue";
import type { PackageData } from "@/types";

interface PublicCategoryData {
  slug: string;
  nameId: string;
  nameEn: string;
  image: string;
  packages: PackageData[];
}

defineProps<{ categories: PublicCategoryData[] }>();

const { language } = useLanguage();
const title = computed(() =>
  language.value === "id" ? "Layanan Kami" : "Our Services",
);
</script>

<template>
  <MainLayout>
    <Head :title="`${title} · Shin Joy Spa Malang`" />

    <section
      class="section official-menu"
      style="padding-top: calc(78px + clamp(2rem, 5vw, 3.5rem))"
    >
      <div class="container">
        <header class="official-menu__header">
          <h1>{{ title }}</h1>
        </header>

        <section
          v-for="category in categories"
          :key="category.slug"
          :aria-labelledby="`category-${category.slug}`"
          style="margin-top: 2.5rem"
        >
          <h2 :id="`category-${category.slug}`">
            {{ language === "id" ? category.nameId : category.nameEn }}
          </h2>
          <div class="official-menu__grid" style="margin-top: 1.25rem">
            <PackageMenuCard
              v-for="servicePackage in category.packages"
              :key="servicePackage.slug"
              :service-package="servicePackage"
            />
          </div>
        </section>
      </div>
    </section>
  </MainLayout>
</template>
