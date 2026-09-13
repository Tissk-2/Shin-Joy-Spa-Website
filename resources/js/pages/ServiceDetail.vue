<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

import PackageConfigurator from "@/components/PackageConfigurator.vue";
import { useLanguage } from "@/composables/useLanguage";
import { applyImageFallback } from "@/lib/media";
import { emptySelection, type ServiceSelection } from "@/lib/serviceSelection";
import MainLayout from "@/layouts/MainLayout.vue";
import type { PackageData, PackageLinkData } from "@/types";

const props = defineProps<{
  service: PackageData;
  related: readonly PackageLinkData[];
}>();
const { language, tr } = useLanguage();
const selectedImage = ref(props.service.image);
const selection = ref<ServiceSelection>(emptySelection(props.service.slug));

watch(
  () => props.service,
  (nextService) => {
    selectedImage.value = nextService.image;
    selection.value = emptySelection(nextService.slug);
  },
);

const gallery = computed(() => {
  const images = [props.service.image, ...props.service.gallery];
  return [...new Set(images.filter(Boolean))];
});
const name = computed(() =>
  language.value === "id" ? props.service.nameId : props.service.nameEn,
);
const description = computed(() =>
  language.value === "id" ? props.service.descId : props.service.descEn,
);
const benefit = computed(() =>
  language.value === "id" ? props.service.benefitId : props.service.benefitEn,
);
const relatedName = (relatedPackage: PackageLinkData): string =>
  language.value === "id" ? relatedPackage.nameId : relatedPackage.nameEn;
const pageTitle = computed(() => `${name.value} · Shin Joy Spa Malang`);
</script>

<template>
  <MainLayout>
    <Head :title="pageTitle" />

    <section
      class="section package-detail"
      style="padding-top: calc(78px + clamp(2rem, 5vw, 3.5rem))"
    >
      <div class="container">
        <nav class="breadcrumb" :aria-label="tr('nav.breadcrumb')">
          <Link class="ulink" href="/services">{{ tr("nav.services") }}</Link>
          <span aria-hidden="true">›</span>
          <span aria-current="page">{{ name }}</span>
        </nav>

        <div class="detail-grid">
          <div class="detail-media reveal">
            <img :src="selectedImage" :alt="name" @error="applyImageFallback" />
            <div v-if="gallery.length > 1" class="detail-thumbs">
              <button
                v-for="(image, index) in gallery"
                :key="image"
                type="button"
                :aria-current="selectedImage === image"
                :aria-label="`${name} ${index + 1}`"
                @click="selectedImage = image"
              >
                <img
                  :src="image"
                  alt=""
                  loading="lazy"
                  @error="applyImageFallback"
                />
              </button>
            </div>
          </div>

          <div class="detail-info reveal" data-delay="1">
            <span v-if="service.isVip" class="package-menu-card__vip">VIP</span>
            <span v-else class="eyebrow">{{ tr("menu.eyebrow") }}</span>
            <h1 class="h2">{{ name }}</h1>
            <p class="prose">{{ description }}</p>
            <p v-if="benefit" class="package-detail__benefit">
              {{ benefit }}
            </p>

            <PackageConfigurator
              v-model="selection"
              :service-package="service"
              show-booking-link
            />
          </div>
        </div>

        <aside
          v-if="related.length"
          class="package-detail__related"
          aria-labelledby="related-packages-title"
        >
          <h2 id="related-packages-title">{{ tr("menu.related") }}</h2>
          <ul>
            <li v-for="relatedPackage in related" :key="relatedPackage.slug">
              <Link class="ulink" :href="`/services/${relatedPackage.slug}`">
                {{ relatedName(relatedPackage) }}
                <span
                  v-if="relatedPackage.isVip"
                  class="package-detail__related-vip"
                  >VIP</span
                >
                <span aria-hidden="true">→</span>
              </Link>
            </li>
          </ul>
        </aside>
      </div>
    </section>
  </MainLayout>
</template>
