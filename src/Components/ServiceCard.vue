<script setup>
import { RouterLink } from 'vue-router';
import { computed } from 'vue';
import categories from '../Data/categories.js';
import { language, tr } from '../useLanguage.js';

const props = defineProps({
  service: { type: Object, required: true },
  delay: { type: Number, default: 0 },
  showTag: { type: Boolean, default: true },
});

const name = computed(() => language.value === 'id'
  ? props.service.nameId
  : props.service.nameEn);
const description = computed(() => language.value === 'id'
  ? props.service.descId
  : props.service.descEn);
const category = computed(() => {
  const match = categories.find(({ slug }) => slug === props.service.category);
  return language.value === 'id'
    ? match?.nameId || props.service.category
    : match?.nameEn || props.service.category;
});
const detailUrl = computed(() => `/services/${props.service.slug}`);
const bookingUrl = computed(() => `/booking?service=${props.service.slug}`);
</script>

<template>
  <article class="svc-card reveal" :data-delay="delay || undefined">
    <RouterLink class="svc-card__media" :to="detailUrl" :aria-label="name">
      <img :src="service.image" :alt="name" loading="lazy" width="600" height="450">
      <span v-if="showTag" class="svc-tag">{{ category }}</span>
    </RouterLink>
    <div class="svc-card__body">
      <h3><RouterLink class="ulink" :to="detailUrl">{{ name }}</RouterLink></h3>
      <p class="svc-card__desc">{{ description }}</p>
      <div class="svc-meta">
        <span class="dur">{{ service.duration }} {{ tr('card.min') }}</span>
        <span class="price">Rp {{ service.price.toLocaleString('id-ID') }}</span>
      </div>
      <div class="svc-card__foot">
        <RouterLink class="link-arrow" :to="detailUrl">{{ tr('card.detail') }} →</RouterLink>
        <RouterLink class="btn btn--primary" :to="bookingUrl">{{ tr('card.book') }}</RouterLink>
      </div>
    </div>
  </article>
</template>
