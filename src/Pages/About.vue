<script setup>
import { RouterLink } from 'vue-router';
import { computed } from 'vue';
import MainLayout from '../Layouts/MainLayout.vue';
import faqs from '../Data/faq.js';
import testimonials from '../Data/testimonials.js';
import { language, tr } from '../useLanguage.js';

const title = computed(() => tr('nav.about'));
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
          <span>{{ tr('nav.about') }}</span>
        </nav>
        <h1>{{ tr('about.title') }}</h1>
      </div>
    </section>

    <section id="about" class="section">
      <div class="container">
        <div class="split">
          <div class="split__media reveal">
            <img src="/img/about.jpg" :alt="tr('about.title')" width="1200" height="1440">
            <div class="badge-float">
              <span class="num">{{ tr('about.badgen') }}</span>
              <small>{{ tr('about.badgel') }}</small>
            </div>
          </div>
          <div class="split__body">
            <span class="eyebrow reveal">{{ tr('about.eyebrow') }}</span>
            <h2 class="reveal" data-delay="1" style="margin-top:1rem">{{ tr('about.title') }}</h2>
            <p class="reveal" data-delay="1" style="margin-top:1.2rem;color:var(--muted)">{{ tr('about.body') }}</p>
            <ul class="feature-list">
              <li class="reveal" data-delay="2">
                <span class="ic">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </span>
                <div><b>{{ tr('about.f1t') }}</b><p>{{ tr('about.f1d') }}</p></div>
              </li>
              <li class="reveal" data-delay="3">
                <span class="ic">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <path d="M12 2C9 6 6 8.5 6 13a6 6 0 0012 0c0-4.5-3-7-6-11Z" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </span>
                <div><b>{{ tr('about.f2t') }}</b><p>{{ tr('about.f2d') }}</p></div>
              </li>
              <li class="reveal" data-delay="4">
                <span class="ic">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <path d="M12 3l7 4v5c0 4-3 7-7 9-4-2-7-5-7-9V7l7-4Z" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </span>
                <div><b>{{ tr('about.f3t') }}</b><p>{{ tr('about.f3d') }}</p></div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <section class="section tint">
      <div class="container">
        <div class="section-head center">
          <span class="eyebrow center reveal">{{ tr('tst.eyebrow') }}</span>
          <h2 class="reveal" data-delay="1">{{ tr('tst.title') }}</h2>
        </div>
        <div class="tst-grid">
          <figure
            v-for="(testimonial, index) in testimonials"
            :key="testimonial.author"
            class="tst reveal"
            :data-delay="(index % 3) + 1"
          >
            <div class="stars" aria-hidden="true">
              <svg v-for="star in testimonial.rating" :key="star" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2l2.9 6.3 6.8.7-5 4.6 1.4 6.7L12 17.8 5.9 20.3l1.4-6.7-5-4.6 6.8-.7Z" />
              </svg>
            </div>
            <blockquote>{{ language === 'id' ? testimonial.quoteId : testimonial.quoteEn }}</blockquote>
            <figcaption>
              <span class="avatar">{{ testimonial.initials }}</span>
              <span class="who">
                <b>{{ testimonial.author }}</b>
                <span>{{ language === 'id' ? testimonial.serviceId : testimonial.serviceEn }}</span>
              </span>
            </figcaption>
          </figure>
        </div>
      </div>
    </section>

    <section id="faq" class="section">
      <div class="container">
        <div class="section-head center">
          <span class="eyebrow center reveal">{{ tr('faq.eyebrow') }}</span>
          <h2 class="reveal" data-delay="1">{{ tr('faq.title') }}</h2>
        </div>
        <div class="faq reveal">
          <details v-for="faq in faqs" :key="faq.qId" class="faq-item">
            <summary>
              <span>{{ language === 'id' ? faq.qId : faq.qEn }}</span>
              <span class="faq-ic" aria-hidden="true"></span>
            </summary>
            <div class="faq-a">{{ language === 'id' ? faq.aId : faq.aEn }}</div>
          </details>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
