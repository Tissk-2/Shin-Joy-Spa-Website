<script setup>
import { RouterLink } from 'vue-router';
import { computed, ref } from 'vue';
import MainLayout from '../Layouts/MainLayout.vue';
import ServiceCard from '../Components/ServiceCard.vue';
import categories from '../Data/categories.js';
import services from '../Data/services.js';
import { language, tr } from '../useLanguage.js';

const props = defineProps({ slug: { type: String, required: true } });
const service = computed(() => services.find(({ slug }) => slug === props.slug));
const category = computed(() => categories.find(
  ({ slug }) => slug === service.value?.category,
));
const selectedImage = ref(service.value?.image || '');
const gallery = computed(() => service.value
  ? service.value.gallery.map((image) => `/img/${image}`)
  : []);
const related = computed(() => services
  .filter((item) => (
    item.category === service.value?.category
    && item.slug !== service.value?.slug
  ))
  .slice(0, 3));

const name = computed(() => language.value === 'id'
  ? service.value?.nameId
  : service.value?.nameEn);
const longDescription = computed(() => language.value === 'id'
  ? service.value?.longId
  : service.value?.longEn);
const goodFor = computed(() => language.value === 'id'
  ? service.value?.goodId
  : service.value?.goodEn);
const categoryName = computed(() => language.value === 'id'
  ? category.value?.nameId
  : category.value?.nameEn);
const bookingUrl = computed(() => `/booking?service=${service.value?.slug}`);
const pageTitle = computed(() => service.value
  ? `${name.value} · Shin Joy Spa Malang`
  : `${tr('d.notfoundt')} · Shin Joy Spa Malang`);
</script>

<template>
  <MainLayout>
    <Teleport to="head">
      <title>{{ pageTitle }}</title>
    </Teleport>

    <section v-if="!service" class="section" style="padding-top:calc(78px + 3rem)">
      <div class="container">
        <div class="empty-state">
          <h1 class="h2">{{ tr('d.notfoundt') }}</h1>
          <p>{{ tr('d.notfounds') }}</p>
          <p style="margin-top:1.5rem">
            <RouterLink class="btn btn--primary" to="/services">{{ tr('d.back') }}</RouterLink>
          </p>
        </div>
      </div>
    </section>

    <section v-else class="section" style="padding-top:calc(78px + clamp(2rem,5vw,3.5rem))">
      <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <RouterLink class="ulink" to="/services">{{ tr('nav.services') }}</RouterLink>
          <span>›</span>
          <span>{{ name }}</span>
        </nav>

        <div class="detail-grid">
          <div class="detail-media reveal">
            <img :src="selectedImage" :alt="name">
            <div v-if="gallery.length > 1" class="detail-thumbs">
              <button
                v-for="(image, index) in gallery"
                :key="image"
                type="button"
                :aria-current="selectedImage === image"
                :aria-label="`${name} ${index + 1}`"
                @click="selectedImage = image"
              >
                <img :src="image" alt="" loading="lazy">
              </button>
            </div>
          </div>

          <div class="detail-info reveal" data-delay="1">
            <span class="svc-tag" style="position:static;display:inline-block;margin-bottom:1rem">
              {{ categoryName }}
            </span>
            <h1 class="h2">{{ name }}</h1>
            <div class="price-row">
              <span class="price">Rp {{ service.price.toLocaleString('id-ID') }}</span>
              <span class="dur">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <circle cx="12" cy="12" r="9" />
                  <path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                {{ service.duration }} {{ tr('d.min') }}
              </span>
            </div>
            <p class="prose">{{ longDescription }}</p>
            <ul class="spec-list">
              <li><span>{{ tr('d.category') }}</span><span>{{ categoryName }}</span></li>
              <li>
                <span>{{ tr('d.duration') }}</span>
                <span>{{ service.duration }} {{ tr('d.min') }}</span>
              </li>
              <li v-if="goodFor"><span>{{ tr('d.goodfor') }}</span><span>{{ goodFor }}</span></li>
            </ul>
            <RouterLink class="btn btn--primary btn--lg btn--block" :to="bookingUrl">
              {{ tr('d.book') }}
            </RouterLink>
          </div>
        </div>

        <section v-if="related.length" class="section" style="padding-bottom:0">
          <div class="section-head">
            <h2 class="h3" style="font-size:clamp(1.5rem,3vw,2rem)">{{ tr('d.related') }}</h2>
          </div>
          <div class="svc-grid">
            <ServiceCard
              v-for="(item, index) in related"
              :key="item.slug"
              :service="item"
              :delay="(index % 3) + 1"
            />
          </div>
        </section>
      </div>
    </section>
  </MainLayout>
</template>
