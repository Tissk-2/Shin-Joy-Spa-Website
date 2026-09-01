<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import type { ServiceCategoryData, ServiceData } from "@/types";

const props = withDefaults(
  defineProps<{
    service: ServiceData;
    category?: ServiceCategoryData;
    delay?: number;
    showTag?: boolean;
  }>(),
  {
    delay: 0,
    showTag: true,
  },
);
const { language, tr } = useLanguage();

const name = computed(() =>
  language.value === "id" ? props.service.nameId : props.service.nameEn,
);
const description = computed(() =>
  language.value === "id" ? props.service.descId : props.service.descEn,
);
const categoryName = computed(() => {
  const match = props.category;
  return language.value === "id"
    ? match?.nameId || props.service.category
    : match?.nameEn || props.service.category;
});
const detailUrl = computed(() => `/services/${props.service.slug}`);
const bookingUrl = computed(() => `/booking?service=${props.service.slug}`);
</script>

<template>
  <article class="svc-card reveal" :data-delay="delay || undefined">
    <Link class="svc-card__media" :href="detailUrl" :aria-label="name">
      <img
        :src="service.image"
        :alt="name"
        loading="lazy"
        width="600"
        height="450"
      />
      <span v-if="showTag" class="svc-tag">{{ categoryName }}</span>
    </Link>
    <div class="svc-card__body">
      <h3>
        <Link class="ulink" :href="detailUrl">{{ name }}</Link>
      </h3>
      <p class="svc-card__desc">{{ description }}</p>
      <div class="svc-meta">
        <span class="dur">{{ service.duration }} {{ tr("card.min") }}</span>
        <span class="price"
          >Rp {{ service.price.toLocaleString("id-ID") }}</span
        >
      </div>
      <div class="svc-card__foot">
        <Link class="link-arrow" :href="detailUrl"
          >{{ tr("card.detail") }} →</Link
        >
        <Link class="btn btn--primary" :href="bookingUrl">{{
          tr("card.book")
        }}</Link>
      </div>
    </div>
  </article>
</template>
