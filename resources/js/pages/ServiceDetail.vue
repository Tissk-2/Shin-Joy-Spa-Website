<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import ServiceCard from "@/components/ServiceCard.vue";
import { useLanguage } from "@/composables/useLanguage";
import MainLayout from "@/layouts/MainLayout.vue";
import type { ServiceCategoryData, ServiceData } from "@/types";

const props = defineProps<{
  service: ServiceData;
  category: ServiceCategoryData;
  related: ServiceData[];
}>();
const { language, tr } = useLanguage();
const selectedImage = ref(props.service.image);
watch(
  () => props.service,
  (nextService) => {
    selectedImage.value = nextService.image;
  },
);
const gallery = computed(() => props.service.gallery);

const name = computed(() =>
  language.value === "id" ? props.service.nameId : props.service.nameEn,
);
const longDescription = computed(() =>
  language.value === "id" ? props.service.longId : props.service.longEn,
);
const goodFor = computed(() =>
  language.value === "id" ? props.service.goodId : props.service.goodEn,
);
const categoryName = computed(() =>
  language.value === "id" ? props.category.nameId : props.category.nameEn,
);
const bookingUrl = computed(
  () => `/booking?service=${encodeURIComponent(props.service.slug)}`,
);
const pageTitle = computed(() => `${name.value} · Shin Joy Spa Malang`);
</script>

<template>
  <MainLayout>
    <Head :title="pageTitle" />

    <section
      class="section"
      style="padding-top: calc(78px + clamp(2rem, 5vw, 3.5rem))"
    >
      <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <Link class="ulink" href="/#services">{{ tr("nav.services") }}</Link>
          <span>›</span>
          <span>{{ name }}</span>
        </nav>

        <div class="detail-grid">
          <div class="detail-media reveal">
            <img :src="selectedImage" :alt="name" />
            <div v-if="gallery.length > 1" class="detail-thumbs">
              <button
                v-for="(image, index) in gallery"
                :key="image"
                type="button"
                :aria-current="selectedImage === image"
                :aria-label="`${name} ${index + 1}`"
                @click="selectedImage = image"
              >
                <img :src="image" alt="" loading="lazy" />
              </button>
            </div>
          </div>

          <div class="detail-info reveal" data-delay="1">
            <span
              class="svc-tag"
              style="
                position: static;
                display: inline-block;
                margin-bottom: 1rem;
              "
            >
              {{ categoryName }}
            </span>
            <h1 class="h2">{{ name }}</h1>
            <div class="price-row">
              <span class="price"
                >Rp {{ service.price.toLocaleString("id-ID") }}</span
              >
              <span class="dur">
                <svg
                  width="17"
                  height="17"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  aria-hidden="true"
                >
                  <circle cx="12" cy="12" r="9" />
                  <path
                    d="M12 7v5l3 2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
                {{ service.duration }} {{ tr("d.min") }}
              </span>
            </div>
            <p class="prose">{{ longDescription }}</p>
            <ul class="spec-list">
              <li>
                <span>{{ tr("d.category") }}</span
                ><span>{{ categoryName }}</span>
              </li>
              <li>
                <span>{{ tr("d.duration") }}</span>
                <span>{{ service.duration }} {{ tr("d.min") }}</span>
              </li>
              <li v-if="goodFor">
                <span>{{ tr("d.goodfor") }}</span
                ><span>{{ goodFor }}</span>
              </li>
            </ul>
            <Link
              class="btn btn--primary btn--lg btn--block"
              :href="bookingUrl"
            >
              {{ tr("d.book") }}
            </Link>
          </div>
        </div>

        <section
          v-if="props.related.length"
          class="section"
          style="padding-bottom: 0"
        >
          <div class="section-head">
            <h2 class="h3" style="font-size: clamp(1.5rem, 3vw, 2rem)">
              {{ tr("d.related") }}
            </h2>
          </div>
          <div class="svc-grid">
            <ServiceCard
              v-for="(item, index) in props.related"
              :key="item.slug"
              :service="item"
              :category="props.category"
              :delay="(index % 3) + 1"
            />
          </div>
        </section>
      </div>
    </section>
  </MainLayout>
</template>
