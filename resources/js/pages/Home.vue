<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { MapPin } from "lucide-vue-next";
import { computed, nextTick, ref } from "vue";
import CatalogMenuFooter from "@/components/CatalogMenuFooter.vue";
import PackageMenuCard from "@/components/PackageMenuCard.vue";
import { useContactInformation } from "@/composables/useContactInformation";
import { useLanguage } from "@/composables/useLanguage";
import faqs from "@/data/faq";
import MainLayout from "@/layouts/MainLayout.vue";
import type { AppPageProps, PublicCatalogCategoryData } from "@/types";

const props = defineProps<{
  categories: readonly PublicCatalogCategoryData[];
}>();
const { language, tr } = useLanguage();
const contactInformation = useContactInformation();
const pageProps = usePage<AppPageProps>();

function c(key: string): string {
  const override = pageProps.props.content?.[key]?.[language.value];
  return override && override.trim() ? override : tr(key);
}

const visibleCategories = computed(() =>
  props.categories.filter((category) => category.packages.length > 0),
);
const title = computed(() => tr("meta.tagline"));

// The embed pulls about 400 KB of Google Maps scripts, so it waits for a click.
const mapLoaded = ref(false);
const mapFrame = ref<HTMLIFrameElement | null>(null);

function loadMap(): void {
  mapLoaded.value = true;
  // The button disappears; keep keyboard focus where the visitor was.
  nextTick(() => mapFrame.value?.focus());
}
</script>

<style scoped>
.hero-title {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(1.85rem, 3.2vw, 2.65rem);
  line-height: 1.2;
  letter-spacing: 0.02em;
}
.hero-title__accent {
  display: block;
  font-family: inherit;
  font-weight: inherit;
  font-size: inherit;
  letter-spacing: inherit;
}

/* About and visit: text on one side, facts or the map on the other. */
.home-about,
.home-visit {
  display: grid;
  gap: clamp(2rem, 5vw, 4.5rem);
  align-items: start;
}
@media (min-width: 860px) {
  .home-about {
    grid-template-columns: minmax(0, 5fr) minmax(0, 6fr);
  }
  .home-visit {
    grid-template-columns: minmax(0, 6fr) minmax(0, 5fr);
  }
}
.home-about__intro p {
  margin-top: 1.2rem;
  max-width: 60ch;
  color: var(--text-muted);
}

.fact-list {
  display: grid;
  margin: 0;
}
.fact-list > div {
  display: grid;
  gap: 0.25rem;
  padding-block: 1.1rem;
  border-top: 1px solid var(--line);
}
.fact-list > div:last-child {
  border-bottom: 1px solid var(--line);
}
.fact-list dt {
  font-weight: 700;
  color: var(--ink);
}
.fact-list dd {
  margin: 0;
  color: var(--text-muted);
}
.fact-list dd a {
  color: var(--rose-strong);
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 3px;
}
.home-visit .fact-list {
  margin-top: 1.8rem;
}
@media (min-width: 560px) {
  .home-visit .fact-list > div {
    grid-template-columns: 11rem minmax(0, 1fr);
    gap: 1rem;
  }
}

.home-visit__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 1.8rem;
}
@media (max-width: 420px) {
  .home-visit__actions .btn,
  .hero__cta .btn {
    width: 100%;
  }
  .hero__cta {
    gap: 0.75rem;
  }
}

/* Same box as the map it replaces, so nothing moves when the map loads. */
.map-placeholder,
.home-visit__map iframe {
  width: 100%;
  aspect-ratio: 4 / 3;
  border: 1px solid var(--line);
  border-radius: var(--r-md);
}
.map-placeholder {
  display: grid;
  place-content: center;
  justify-items: center;
  gap: 1rem;
  padding: 1.5rem;
  background: var(--surface);
  color: var(--text-muted);
  text-align: center;
}
.map-placeholder__pin {
  width: 2rem;
  height: 2rem;
  color: var(--gold-deep);
  stroke-width: 1.5;
}
.map-placeholder p {
  max-width: 22rem;
}
/* Stacked under the details, an empty 4:3 box would only push the page down. */
@media (max-width: 859px) {
  .map-placeholder {
    aspect-ratio: auto;
  }
}
</style>

<template>
  <Head
    :title="
      title.includes('Shin Joy Spa') ? title : `${title} · Shin Joy Spa Malang`
    "
  />

  <MainLayout>
    <section class="hero">
      <div class="hero__media">
        <!-- Same srcset and sizes as the preload in app.blade.php. The photo
             covers the hero (2000x1180, at most 880px tall), so it is drawn
             at the wider of 100vw and 156vh. -->
        <img
          src="/img/hero.webp"
          srcset="/img/hero-1600.webp 1600w, /img/hero.webp 2000w"
          sizes="(min-aspect-ratio: 39/23) 100vw, 156vh"
          :alt="tr('hero.title')"
          fetchpriority="high"
        />
      </div>
      <div class="hero__scrim"></div>
      <div class="container hero__inner">
        <span class="eyebrow reveal">{{ c("hero.eyebrow") }}</span>
        <h1 class="hero-title reveal" data-delay="1">
          {{ c("hero.heading") }}
          <span class="hero-title__accent">{{ c("hero.headingAccent") }}</span>
        </h1>
        <p class="reveal" data-delay="2">{{ c("hero.sub") }}</p>
        <div class="hero__cta reveal" data-delay="3">
          <Link class="btn btn--primary btn--lg" href="/booking">{{
            tr("hero.cta1")
          }}</Link>
          <Link class="btn btn--on-dark btn--lg" href="/#menu">{{
            tr("hero.cta2")
          }}</Link>
        </div>
      </div>
    </section>

    <section
      id="menu"
      class="official-menu public-catalog public-catalog--home section"
      aria-labelledby="menu-title"
    >
      <div class="container">
        <header class="official-menu__header">
          <h2 id="menu-title">{{ tr("menu.title") }}</h2>
          <p>{{ tr("menu.sub") }}</p>
        </header>
        <div v-if="visibleCategories.length" class="public-catalog__categories">
          <section
            v-for="category in visibleCategories"
            :key="category.slug"
            class="public-catalog__category"
            :data-catalog-category="category.slug"
            :aria-labelledby="`home-category-${category.slug}`"
          >
            <header class="sr-only">
              <h3 :id="`home-category-${category.slug}`">
                {{ language === "id" ? category.nameId : category.nameEn }}
              </h3>
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
          <h3>{{ tr("menu.emptyTitle") }}</h3>
          <p>{{ tr("menu.emptyBody") }}</p>
        </div>
        <CatalogMenuFooter />
      </div>
    </section>

    <section id="about" class="section" aria-labelledby="about-title">
      <div class="container home-about">
        <div class="home-about__intro">
          <h2 id="about-title" class="reveal">{{ c("about.title") }}</h2>
          <p class="reveal" data-delay="1">{{ c("about.body") }}</p>
        </div>
        <dl class="fact-list reveal" data-delay="2">
          <div>
            <dt>{{ tr("about.f1t") }}</dt>
            <dd>{{ tr("about.f1d") }}</dd>
          </div>
          <div>
            <dt>{{ tr("about.f2t") }}</dt>
            <dd>{{ tr("about.f2d") }}</dd>
          </div>
          <div>
            <dt>{{ tr("about.f3t") }}</dt>
            <dd>{{ tr("about.f3d") }}</dd>
          </div>
        </dl>
      </div>
    </section>

    <section id="faq" class="section tint" aria-labelledby="faq-title">
      <div class="container">
        <div class="section-head center">
          <h2 id="faq-title" class="reveal">{{ tr("faq.title") }}</h2>
        </div>
        <div class="faq reveal">
          <details v-for="faq in faqs" :key="faq.qId" class="faq-item">
            <summary>
              <span>{{ language === "id" ? faq.qId : faq.qEn }}</span>
              <span class="faq-ic" aria-hidden="true"></span>
            </summary>
            <div class="faq-a">{{ language === "id" ? faq.aId : faq.aEn }}</div>
          </details>
        </div>
      </div>
    </section>

    <section id="contact" class="section" aria-labelledby="contact-title">
      <div class="container home-visit">
        <div class="home-visit__body">
          <h2 id="contact-title" class="reveal">{{ tr("loc.title") }}</h2>
          <dl class="fact-list reveal" data-delay="1">
            <div>
              <dt>{{ tr("loc.addresst") }}</dt>
              <dd>{{ contactInformation.address }}</dd>
            </div>
            <div>
              <dt>{{ tr("loc.hourst") }}</dt>
              <dd>{{ contactInformation.hours }}</dd>
            </div>
            <div>
              <dt>{{ tr("loc.phonet") }}</dt>
              <dd>
                <a :href="contactInformation.phoneUrl">{{
                  contactInformation.phone
                }}</a>
              </dd>
            </div>
          </dl>
          <div class="home-visit__actions reveal" data-delay="2">
            <a
              v-if="contactInformation.whatsappUrl"
              class="btn btn--primary"
              :href="contactInformation.whatsappUrl"
              target="_blank"
              rel="noopener"
            >
              {{ tr("loc.whatsapp") }}
            </a>
            <a
              class="btn btn--ghost"
              :href="contactInformation.directionsUrl"
              target="_blank"
              rel="noopener"
            >
              {{ tr("loc.directions") }}
            </a>
          </div>
        </div>
        <div class="home-visit__map reveal">
          <iframe
            v-if="mapLoaded"
            ref="mapFrame"
            :title="tr('loc.title')"
            :src="contactInformation.mapEmbedUrl"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
          ></iframe>
          <div v-else class="map-placeholder">
            <MapPin class="map-placeholder__pin" aria-hidden="true" />
            <p>{{ tr("loc.mapNote") }}</p>
            <button
              type="button"
              class="btn btn--ghost"
              data-map-load
              @click="loadMap"
            >
              {{ tr("loc.mapShow") }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <section class="section cta-band" aria-labelledby="cta-title">
      <div class="container inner">
        <h2 id="cta-title" class="reveal">{{ tr("cta.title") }}</h2>
        <p class="reveal" data-delay="1">{{ tr("cta.sub") }}</p>
        <Link
          class="btn btn--gold btn--lg reveal"
          data-delay="2"
          href="/booking"
          >{{ tr("cta.btn") }}</Link
        >
      </div>
    </section>
  </MainLayout>
</template>
