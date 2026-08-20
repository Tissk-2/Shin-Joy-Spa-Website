<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import ServiceCard from "@/components/ServiceCard.vue";
import { useLanguage } from "@/composables/useLanguage";
import categories from "@/data/categories";
import faqs from "@/data/faq";
import galleryImgs from "@/data/gallery";
import services from "@/data/services";
import testimonials from "@/data/testimonials";
import MainLayout from "@/layouts/MainLayout.vue";
import type { AppPageProps } from "@/types";

const page = usePage<AppPageProps>();
const { language, tr } = useLanguage();
const activeCategory = ref("");

function categoryFromUrl(url: string): string {
  const [pathAndQuery, hash = ""] = url.split("#", 2);
  const query = pathAndQuery.includes("?")
    ? pathAndQuery.slice(pathAndQuery.indexOf("?"))
    : hash.includes("?")
      ? hash.slice(hash.indexOf("?"))
      : "";
  const category = new URLSearchParams(query).get("cat") ?? "";

  return categories.some(({ slug }) => slug === category) ? category : "";
}

watch(
  () => page.url,
  (url) => {
    activeCategory.value = categoryFromUrl(url);
  },
  { immediate: true },
);

const filteredServices = computed(() => {
  if (!activeCategory.value) {
    return services.filter(({ popularity }) => popularity > 90).slice(0, 6);
  }
  return services.filter(({ category }) => category === activeCategory.value);
});

function selectCategory(slug: string): void {
  activeCategory.value = activeCategory.value === slug ? "" : slug;
  document
    .getElementById("services-list")
    ?.scrollIntoView({ behavior: "smooth" });
}
const gallerySpans = ["tall", "", "", "wide", "", "tall", "", "wide"];
const statServices = services.length;
const statRating = "4.9";
const statGuests = 2000;
const title = computed(() => tr("meta.tagline"));
const formattedGuests = computed(() =>
  new Intl.NumberFormat(language.value === "id" ? "id-ID" : "en-US").format(
    statGuests,
  ),
);

const lightboxIndex = ref(-1);
const lightboxOpen = computed(() => lightboxIndex.value >= 0);
const lightboxElement = ref<HTMLElement | null>(null);
const lightboxCloseButton = ref<HTMLButtonElement | null>(null);
let lastFocused: HTMLElement | null = null;

function openLightbox(index: number): void {
  lastFocused = document.activeElement as HTMLElement | null;
  lightboxIndex.value = index;
  document.body.style.overflow = "hidden";
  nextTick(() => lightboxCloseButton.value?.focus());
}

function closeLightbox(): void {
  lightboxIndex.value = -1;
  document.body.style.overflow = "";
  nextTick(() => lastFocused?.focus());
}

function moveLightbox(offset: number): void {
  lightboxIndex.value =
    (lightboxIndex.value + offset + galleryImgs.length) % galleryImgs.length;
}

function handleLightboxKeydown(event: KeyboardEvent): void {
  if (event.key === "Escape") {
    closeLightbox();
  } else if (event.key === "ArrowLeft") {
    moveLightbox(-1);
  } else if (event.key === "ArrowRight") {
    moveLightbox(1);
  } else if (event.key === "Tab") {
    const controls = [
      ...(lightboxElement.value?.querySelectorAll<HTMLButtonElement>(
        "button",
      ) ?? []),
    ];
    const first = controls[0];
    const last = controls.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }
}

onBeforeUnmount(() => {
  document.body.style.overflow = "";
});
</script>

<template>
  <Head
    :title="
      title.includes('Shin Joy Spa') ? title : `${title} · Shin Joy Spa Malang`
    "
  />

  <MainLayout>
    <section class="hero">
      <div class="hero__media">
        <img src="/img/hero.jpg" :alt="tr('hero.title')" fetchpriority="high" />
      </div>
      <div class="hero__scrim"></div>
      <div class="container hero__inner">
        <span class="eyebrow reveal">{{ tr("hero.eyebrow") }}</span>
        <h1 class="hero-title reveal" data-delay="1">{{ tr("hero.title") }}</h1>
        <p class="reveal" data-delay="2">{{ tr("hero.sub") }}</p>
        <div class="hero__cta reveal" data-delay="3">
          <Link class="btn btn--primary btn--lg" href="/booking">{{
            tr("hero.cta1")
          }}</Link>
          <Link class="btn btn--on-dark btn--lg" href="/#services">{{
            tr("hero.cta2")
          }}</Link>
        </div>
        <div class="hero__meta reveal" data-delay="4">
          <div>
            <b>{{ statServices }}+</b><span>{{ tr("hero.m1l") }}</span>
          </div>
          <div>
            <b>{{ statRating }}</b
            ><span>{{ tr("hero.m2l") }}</span>
          </div>
          <div>
            <b>{{ formattedGuests }}+</b><span>{{ tr("hero.m3l") }}</span>
          </div>
        </div>
      </div>
      <span class="hero__scroll" aria-hidden="true">
        <span>{{ tr("hero.scroll") }}</span>
        <span class="dot"></span>
      </span>
    </section>

    <section id="services" class="section">
      <div class="container">
        <div class="section-head center">
          <span class="eyebrow center reveal">{{ tr("cats.eyebrow") }}</span>
          <h2 class="reveal" data-delay="1">{{ tr("cats.title") }}</h2>
          <p class="lead reveal" data-delay="2" style="margin-inline: auto">
            {{ tr("cats.sub") }}
          </p>
        </div>
        <div class="cat-grid">
          <button
            v-for="(category, index) in categories"
            :key="category.slug"
            type="button"
            class="cat-card reveal"
            :class="{ active: activeCategory === category.slug }"
            :data-delay="index || undefined"
            @click="selectCategory(category.slug)"
            style="
              appearance: none;
              border: none;
              background: transparent;
              padding: 0;
              text-align: left;
              cursor: pointer;
              font: inherit;
            "
          >
            <img
              :src="category.image"
              :alt="language === 'id' ? category.nameId : category.nameEn"
              loading="lazy"
            />
            <div class="cat-body">
              <h3>
                {{ language === "id" ? category.nameId : category.nameEn }}
              </h3>
              <span class="cat-count">
                {{
                  tr("cats.count", {
                    n: services.filter(
                      ({ category: slug }) => slug === category.slug,
                    ).length,
                  })
                }}
              </span>
              <span class="cat-rule"></span>
            </div>
          </button>
        </div>
      </div>
    </section>

    <section id="services-list" class="section tint">
      <div class="container">
        <div
          class="section-head"
          style="
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1.5rem;
            max-width: none;
          "
        >
          <div style="max-width: 46ch">
            <span class="eyebrow reveal">{{
              activeCategory ? tr("cats.eyebrow") : tr("feat.eyebrow")
            }}</span>
            <h2 class="reveal" data-delay="1" style="margin-top: 1rem">
              {{
                activeCategory
                  ? language === "id"
                    ? categories.find((c) => c.slug === activeCategory)?.nameId
                    : categories.find((c) => c.slug === activeCategory)?.nameEn
                  : tr("feat.title")
              }}
            </h2>
            <p
              class="reveal"
              data-delay="2"
              style="margin-top: 1rem; color: var(--muted)"
            >
              {{
                activeCategory
                  ? tr("sp.results", { n: filteredServices.length })
                  : tr("feat.sub")
              }}
            </p>
          </div>
          <button
            v-if="activeCategory"
            type="button"
            class="link-arrow reveal"
            data-delay="2"
            @click="selectCategory('')"
            style="
              appearance: none;
              border: none;
              background: transparent;
              font: inherit;
              cursor: pointer;
            "
          >
            <span>{{ tr("sp.all") }}</span>
          </button>
        </div>
        <div class="svc-grid">
          <ServiceCard
            v-for="(service, index) in filteredServices"
            :key="service.slug"
            :service="service"
            :delay="(index % 3) + 1"
          />
        </div>
      </div>
    </section>

    <section id="about" class="section">
      <div class="container">
        <div class="split">
          <div class="split__media reveal">
            <img
              src="/img/about.jpg"
              :alt="tr('about.title')"
              width="1200"
              height="1440"
              loading="lazy"
            />
            <div class="badge-float">
              <span class="num">{{ tr("about.badgen") }}</span>
              <small>{{ tr("about.badgel") }}</small>
            </div>
          </div>
          <div class="split__body">
            <span class="eyebrow reveal">{{ tr("about.eyebrow") }}</span>
            <h2 class="reveal" data-delay="1" style="margin-top: 1rem">
              {{ tr("about.title") }}
            </h2>
            <p
              class="reveal"
              data-delay="1"
              style="margin-top: 1.2rem; color: var(--muted)"
            >
              {{ tr("about.body") }}
            </p>
            <ul class="feature-list">
              <li class="reveal" data-delay="2">
                <span class="ic">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                  >
                    <path
                      d="M20 6L9 17l-5-5"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </span>
                <div>
                  <b>{{ tr("about.f1t") }}</b>
                  <p>{{ tr("about.f1d") }}</p>
                </div>
              </li>
              <li class="reveal" data-delay="3">
                <span class="ic">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                  >
                    <path
                      d="M12 2C9 6 6 8.5 6 13a6 6 0 0012 0c0-4.5-3-7-6-11Z"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </span>
                <div>
                  <b>{{ tr("about.f2t") }}</b>
                  <p>{{ tr("about.f2d") }}</p>
                </div>
              </li>
              <li class="reveal" data-delay="4">
                <span class="ic">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                  >
                    <path
                      d="M12 3l7 4v5c0 4-3 7-7 9-4-2-7-5-7-9V7l7-4Z"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </span>
                <div>
                  <b>{{ tr("about.f3t") }}</b>
                  <p>{{ tr("about.f3d") }}</p>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <section id="gallery" class="section tint">
      <div class="container container-wide">
        <div class="section-head center">
          <span class="eyebrow center reveal">{{ tr("gallery.eyebrow") }}</span>
          <h2 class="reveal" data-delay="1">{{ tr("gallery.title") }}</h2>
          <p class="lead reveal" data-delay="2" style="margin-inline: auto">
            {{ tr("gallery.sub") }}
          </p>
        </div>
        <div class="gallery reveal">
          <a
            v-for="(image, index) in galleryImgs"
            :key="image"
            :class="gallerySpans[index % gallerySpans.length]"
            :href="image"
            :aria-label="`${tr('gallery.lbView')} ${index + 1} / ${galleryImgs.length}`"
            @click.prevent="openLightbox(index)"
          >
            <img
              :src="image"
              :alt="`${tr('gallery.title')} ${index + 1}`"
              loading="lazy"
            />
          </a>
        </div>
      </div>
    </section>

    <div
      ref="lightboxElement"
      class="lightbox"
      :class="{ open: lightboxOpen }"
      role="dialog"
      aria-modal="true"
      :aria-label="tr('gallery.lbLabel')"
      :aria-hidden="!lightboxOpen"
      @click.self="closeLightbox"
      @keydown="handleLightboxKeydown"
    >
      <button
        ref="lightboxCloseButton"
        type="button"
        class="lightbox__close"
        :aria-label="tr('gallery.lbClose')"
        @click="closeLightbox"
      >
        &times;
      </button>
      <button
        type="button"
        class="lightbox__nav lightbox__prev"
        :aria-label="tr('gallery.lbPrev')"
        @click="moveLightbox(-1)"
      >
        &lsaquo;
      </button>
      <img
        class="lightbox__img"
        :src="lightboxOpen ? galleryImgs[lightboxIndex] : ''"
        :alt="lightboxOpen ? `${tr('gallery.title')} ${lightboxIndex + 1}` : ''"
      />
      <button
        type="button"
        class="lightbox__nav lightbox__next"
        :aria-label="tr('gallery.lbNext')"
        @click="moveLightbox(1)"
      >
        &rsaquo;
      </button>
      <span class="lightbox__count" aria-hidden="true">
        {{ lightboxIndex + 1 }} / {{ galleryImgs.length }}
      </span>
    </div>

    <section class="section">
      <div class="container">
        <div class="section-head center">
          <span class="eyebrow center reveal">{{ tr("tst.eyebrow") }}</span>
          <h2 class="reveal" data-delay="1">{{ tr("tst.title") }}</h2>
        </div>
        <div class="tst-grid">
          <figure
            v-for="(testimonial, index) in testimonials"
            :key="testimonial.author"
            class="tst reveal"
            :data-delay="(index % 3) + 1"
          >
            <div class="stars" aria-hidden="true">
              <svg
                v-for="star in testimonial.rating"
                :key="star"
                viewBox="0 0 24 24"
                fill="currentColor"
              >
                <path
                  d="M12 2l2.9 6.3 6.8.7-5 4.6 1.4 6.7L12 17.8 5.9 20.3l1.4-6.7-5-4.6 6.8-.7Z"
                />
              </svg>
            </div>
            <blockquote>
              {{
                language === "id" ? testimonial.quoteId : testimonial.quoteEn
              }}
            </blockquote>
            <figcaption>
              <span class="avatar">{{ testimonial.initials }}</span>
              <span class="who">
                <b>{{ testimonial.author }}</b>
                <span>{{
                  language === "id"
                    ? testimonial.serviceId
                    : testimonial.serviceEn
                }}</span>
              </span>
            </figcaption>
          </figure>
        </div>
      </div>
    </section>

    <section id="faq" class="section tint">
      <div class="container">
        <div class="section-head center">
          <span class="eyebrow center reveal">{{ tr("faq.eyebrow") }}</span>
          <h2 class="reveal" data-delay="1">{{ tr("faq.title") }}</h2>
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

    <section id="contact" class="section tint">
      <div class="container">
        <div class="split reverse">
          <div class="split__media reveal">
            <iframe
              :title="tr('loc.title')"
              src="https://www.google.com/maps?q=Jl.+Ijen+No.+12,+Klojen,+Kota+Malang,+Jawa+Timur+65119&output=embed"
              style="
                width: 100%;
                aspect-ratio: 4/5;
                border: 0;
                border-radius: var(--r-lg);
                box-shadow: var(--shadow-card);
              "
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"
            ></iframe>
          </div>
          <div class="split__body">
            <span class="eyebrow reveal">{{ tr("loc.eyebrow") }}</span>
            <h2 class="reveal" data-delay="1" style="margin-top: 1rem">
              {{ tr("loc.title") }}
            </h2>
            <ul class="feature-list" style="margin-top: 2rem">
              <li class="reveal" data-delay="2">
                <span class="ic">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                  >
                    <path
                      d="M12 21s7-5.5 7-11a7 7 0 10-14 0c0 5.5 7 11 7 11Z"
                      stroke-linejoin="round"
                    />
                    <circle cx="12" cy="10" r="2.5" />
                  </svg>
                </span>
                <div>
                  <b>{{ tr("loc.addresst") }}</b>
                  <p>{{ tr("loc.address") }}</p>
                </div>
              </li>
              <li class="reveal" data-delay="3">
                <span class="ic">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                  >
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 6v6l4 2" />
                  </svg>
                </span>
                <div>
                  <b>{{ tr("loc.hourst") }}</b>
                  <p>{{ tr("loc.hours") }}</p>
                </div>
              </li>
              <li class="reveal" data-delay="4">
                <span class="ic">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                  >
                    <path
                      d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2Z"
                      stroke-linejoin="round"
                    />
                  </svg>
                </span>
                <div>
                  <b>{{ tr("loc.phonet") }}</b>
                  <p>{{ tr("loc.phone") }}</p>
                </div>
              </li>
            </ul>
            <p style="margin-top: 1.8rem">
              <a
                class="btn btn--ghost"
                href="https://www.google.com/maps?q=Jl.+Ijen+No.+12,+Klojen,+Kota+Malang,+Jawa+Timur+65119"
                target="_blank"
                rel="noopener"
              >
                {{ tr("loc.directions") }}
              </a>
            </p>
          </div>
        </div>
      </div>
    </section>

    <section class="section cta-band">
      <div class="container inner">
        <span class="eyebrow center reveal">{{ tr("cta.eyebrow") }}</span>
        <h2 class="reveal" data-delay="1" style="margin-top: 1rem">
          {{ tr("cta.title") }}
        </h2>
        <p class="reveal" data-delay="2">{{ tr("cta.sub") }}</p>
        <Link
          class="btn btn--gold btn--lg reveal"
          data-delay="3"
          href="/booking"
          >{{ tr("cta.btn") }}</Link
        >
      </div>
    </section>
  </MainLayout>
</template>
