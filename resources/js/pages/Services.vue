<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import CatalogMenuFooter from "@/components/CatalogMenuFooter.vue";
import PackageMenuCard from "@/components/PackageMenuCard.vue";
import { useLanguage } from "@/composables/useLanguage";
import MainLayout from "@/layouts/MainLayout.vue";
import type { PublicCatalogCategoryData } from "@/types";

const props = defineProps<{
  categories: readonly PublicCatalogCategoryData[];
}>();

const { language, tr } = useLanguage();
const visibleCategories = computed(() =>
  props.categories.filter((category) => category.packages.length > 0),
);
</script>

<template>
  <MainLayout>
    <Head :title="`${tr('menu.allTitle')} · Shin Joy Spa Malang`" />

    <section
      class="section official-menu public-catalog public-catalog--page"
      aria-labelledby="catalog-page-title"
    >
      <div class="container">
        <h1 id="catalog-page-title" class="sr-only">
          {{ tr("menu.allTitle") }}
        </h1>
        <nav class="breadcrumb sr-only" :aria-label="tr('nav.breadcrumb')">
          <Link class="ulink" href="/">{{ tr("nav.home") }}</Link>
          <span aria-hidden="true">›</span>
          <span aria-current="page">{{ tr("nav.services") }}</span>
        </nav>

        <div v-if="visibleCategories.length" class="public-catalog__categories">
          <section
            v-for="category in visibleCategories"
            :key="category.slug"
            class="public-catalog__category"
            :data-catalog-category="category.slug"
            :aria-labelledby="`category-${category.slug}`"
          >
            <header class="public-catalog__category-heading">
              <span
                class="public-catalog__category-index"
                aria-hidden="true"
              ></span>
              <h2 :id="`category-${category.slug}`">
                {{ language === "id" ? category.nameId : category.nameEn }}
              </h2>
            </header>
            <div class="official-menu__grid public-catalog__grid">
              <PackageMenuCard
                v-for="servicePackage in category.packages"
                :key="servicePackage.slug"
                :service-package="servicePackage"
              />
            </div>
          </section>
        </div>
        <div v-else class="public-catalog__empty" data-catalog-empty>
          <h2>{{ tr("menu.emptyTitle") }}</h2>
          <p>{{ tr("menu.emptyBody") }}</p>
        </div>
        <CatalogMenuFooter />
      </div>
    </section>
  </MainLayout>
</template>
