<script setup>
import { RouterLink, useRoute } from 'vue-router';
import { computed, ref, watch } from 'vue';
import ServiceCard from '../Components/ServiceCard.vue';
import MainLayout from '../Layouts/MainLayout.vue';
import categories from '../Data/categories.js';
import services from '../Data/services.js';
import { language, tr } from '../useLanguage.js';

const search = ref('');
const activeCategory = ref('');
const sortBy = ref('featured');
const route = useRoute();
const title = computed(() => tr('sp.title'));

watch(
  () => route.query.cat,
  (category) => {
    activeCategory.value = categories.some(({ slug }) => slug === category)
      ? category
      : '';
  },
  { immediate: true },
);

const filteredServices = computed(() => {
  let result = services;

  if (activeCategory.value) {
    result = result.filter(({ category }) => category === activeCategory.value);
  }

  if (search.value.trim()) {
    const query = search.value.trim().toLowerCase();
    result = result.filter((service) => {
      const name = language.value === 'id' ? service.nameId : service.nameEn;
      const description = language.value === 'id' ? service.descId : service.descEn;
      return name.toLowerCase().includes(query)
        || description.toLowerCase().includes(query);
    });
  }

  result = [...result];
  if (sortBy.value === 'price-asc') {
    result.sort((a, b) => a.price - b.price);
  } else if (sortBy.value === 'price-desc') {
    result.sort((a, b) => b.price - a.price);
  } else if (sortBy.value === 'duration') {
    result.sort((a, b) => a.duration - b.duration);
  } else {
    result.sort((a, b) => b.popularity - a.popularity);
  }

  return result;
});
</script>

<template>
  <Teleport to="head">
    <title>{{ title.includes('Shin Joy Spa') ? title : `${title} · Shin Joy Spa Malang` }}</title>
  </Teleport>

  <MainLayout>
    <section class="page-hero">
      <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <RouterLink class="ulink" to="/">{{ tr('nav.home') }}</RouterLink>
          <span aria-hidden="true">›</span>
          <span>{{ tr('nav.services') }}</span>
        </nav>
        <h1>{{ tr('sp.title') }}</h1>
        <p class="lead">{{ tr('sp.sub') }}</p>
      </div>
    </section>

    <section class="section" style="padding-top:clamp(2.5rem,5vw,3.5rem)">
      <div class="container">
        <form class="filters" @submit.prevent>
          <div class="search-field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <circle cx="11" cy="11" r="7" />
              <path d="M21 21l-4-4" stroke-linecap="round" />
            </svg>
            <input
              v-model="search"
              type="search"
              :placeholder="tr('sp.search')"
              :aria-label="tr('sp.searchlabel')"
            >
          </div>

          <div class="pill-row" role="group" :aria-label="tr('nav.services')">
            <button
              type="button"
              class="pill"
              :aria-pressed="activeCategory === ''"
              @click="activeCategory = ''"
            >
              {{ tr('sp.all') }}
            </button>
            <button
              v-for="category in categories"
              :key="category.slug"
              type="button"
              class="pill"
              :aria-pressed="activeCategory === category.slug"
              @click="activeCategory = category.slug"
            >
              {{ language === 'id' ? category.nameId : category.nameEn }}
            </button>
          </div>

          <div class="sort-field">
            <label for="svc-sort">{{ tr('sp.sort') }}</label>
            <select id="svc-sort" v-model="sortBy">
              <option value="featured">{{ tr('sp.sortfeatured') }}</option>
              <option value="price-asc">{{ tr('sp.sortpriceasc') }}</option>
              <option value="price-desc">{{ tr('sp.sortpricedesc') }}</option>
              <option value="duration">{{ tr('sp.sortduration') }}</option>
            </select>
          </div>
        </form>

        <p class="result-count" aria-live="polite">
          {{ filteredServices.length === 1
            ? tr('sp.result1')
            : tr('sp.results', { n: filteredServices.length }) }}
        </p>

        <div v-if="filteredServices.length" class="svc-grid">
          <ServiceCard
            v-for="(service, index) in filteredServices"
            :key="service.slug"
            :service="service"
            :delay="(index % 3) + 1"
          />
        </div>

        <div v-else class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="M21 21l-4-4" stroke-linecap="round" />
          </svg>
          <h3 class="h3">{{ tr('sp.emptyt') }}</h3>
          <p>{{ tr('sp.emptys') }}</p>
          <p style="margin-top:1.5rem">
            <button
              type="button"
              class="btn btn--ghost"
              @click="search = ''; activeCategory = ''; sortBy = 'featured'"
            >
              {{ tr('sp.reset') }}
            </button>
          </p>
        </div>
      </div>
    </section>

    <section class="section cta-band">
      <div class="container inner">
        <span class="eyebrow center reveal">{{ tr('cta.eyebrow') }}</span>
        <h2 class="reveal" data-delay="1" style="margin-top:1rem">{{ tr('cta.title') }}</h2>
        <p class="reveal" data-delay="2">{{ tr('cta.sub') }}</p>
        <RouterLink class="btn btn--gold btn--lg reveal" data-delay="3" to="/booking">{{ tr('cta.btn') }}</RouterLink>
      </div>
    </section>
  </MainLayout>
</template>
