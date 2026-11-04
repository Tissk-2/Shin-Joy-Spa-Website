<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { Calendar } from "lucide-vue-next";
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import { applyImageFallback } from "@/lib/media";
import type { AppPageProps, PackageData, PackageVariantData } from "@/types";

const props = defineProps<{ servicePackage: PackageData }>();
const page = usePage<AppPageProps>();
const { tr } = useLanguage();
const isId = computed(() => (page.props.locale ?? "id") === "id");

const name = computed(() =>
  isId.value ? props.servicePackage.nameId : props.servicePackage.nameEn,
);
const description = computed(() =>
  isId.value ? props.servicePackage.descId : props.servicePackage.descEn,
);
const benefit = computed(() => {
  const value = isId.value
    ? props.servicePackage.benefitId
    : props.servicePackage.benefitEn;
  return value?.trim() || null;
});
const isVip = computed(() => Boolean(props.servicePackage.isVip));
const showBookingRequirement = computed(() => page.props.auth?.user === null);
const detailUrl = computed(
  () => `/booking?service=${props.servicePackage.slug}`,
);

const startingPrice = computed(() => {
  const prices = props.servicePackage.variants
    .map((variant) => variant.price)
    .filter((price) => Number.isFinite(price) && price >= 0);
  return prices.length ? Math.min(...prices) : null;
});
const formattedStartingPrice = computed(() =>
  startingPrice.value === null
    ? tr("menu.unavailable")
    : `Rp ${new Intl.NumberFormat("id-ID").format(startingPrice.value)}`,
);

function sortVariants(variants: readonly PackageVariantData[]) {
  return [...variants].sort(
    (left, right) =>
      left.duration - right.duration || left.mode.localeCompare(right.mode),
  );
}

const regularVariants = computed(() =>
  sortVariants(
    props.servicePackage.variants.filter(
      (variant) => variant.mode === "regular",
    ),
  ),
);
const doubleTherapistVariants = computed(() =>
  sortVariants(
    props.servicePackage.variants.filter(
      (variant) => variant.mode === "double_therapist",
    ),
  ),
);
const primaryVariants = computed(() =>
  regularVariants.value.length
    ? regularVariants.value
    : sortVariants(props.servicePackage.variants),
);
const extensions = computed(() => {
  const availableModes = new Set(
    props.servicePackage.variants.map((variant) => variant.mode),
  );
  return [...props.servicePackage.extensions]
    .filter((extension) => availableModes.has(extension.mode))
    .sort(
      (left, right) =>
        left.duration - right.duration || left.mode.localeCompare(right.mode),
    );
});

function modeLabel(mode: string): string {
  if (mode === "double_therapist") return tr("menu.doubleTherapist");
  return tr("menu.regular");
}

function durationLabel(duration: number): string {
  return `${duration} ${isId.value ? "menit" : "min"}`;
}

function formatPriceFull(price: number): string {
  return `Rp ${new Intl.NumberFormat("id-ID").format(price)}`;
}

function keepFocusedDisclosureVisible(event: FocusEvent): void {
  const summary = event.currentTarget;
  if (!(summary instanceof HTMLElement) || !summary.matches(":focus-visible")) {
    return;
  }

  const bounds = summary.getBoundingClientRect();
  if (bounds.top < 0 || bounds.bottom > window.innerHeight) {
    summary.scrollIntoView({
      block: "nearest",
      inline: "nearest",
      behavior: "instant",
    });
  }
}
</script>

<template>
  <article
    class="package-menu-card"
    :class="isVip ? 'package-menu-card--vip' : 'package-menu-card--regular'"
    :aria-label="name"
    data-package-card
  >
    <img
      v-if="servicePackage.image"
      :src="servicePackage.image"
      :alt="name"
      class="sr-only"
      loading="lazy"
      @error="applyImageFallback"
    />
    <p class="package-menu-card__starting-price sr-only" data-starting-price>
      <template v-if="startingPrice !== null">{{
        `${tr("menu.from")} `
      }}</template>
      <span class="price-amount">{{ formattedStartingPrice }}</span>
    </p>

    <header class="package-menu-card__header">
      <p class="package-menu-card__type">
        {{ isVip ? tr("menu.packageVip") : tr("menu.packageRegular") }}
      </p>
      <h3>{{ name }}</h3>
      <p class="package-menu-card__description">{{ description }}</p>
      <p v-if="benefit" class="package-menu-card__benefit">{{ benefit }}</p>
      <p
        v-if="servicePackage.pricingVerified"
        class="package-menu-card__booking-note"
        data-pricing-scope
      >
        {{
          tr(
            servicePackage.pricingBasis === "per_person"
              ? "menu.pricesPerPerson"
              : "menu.pricesPerBooking",
            {
              min: servicePackage.minGuests ?? 1,
              max: servicePackage.maxGuests ?? 4,
            },
          )
        }}
      </p>
    </header>

    <table
      v-if="primaryVariants.length"
      class="package-menu-card__comparison"
      :aria-label="`${tr('menu.duration')} & ${tr('menu.price')} — ${name}`"
    >
      <thead>
        <tr>
          <th v-if="!regularVariants.length" scope="col">
            {{ tr("menu.mode") }}
          </th>
          <th scope="col">{{ tr("menu.duration") }}</th>
          <th scope="col">{{ tr("menu.price") }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="variant in primaryVariants" :key="variant.code">
          <td v-if="!regularVariants.length">{{ modeLabel(variant.mode) }}</td>
          <th scope="row">{{ durationLabel(variant.duration) }}</th>
          <td>{{ formatPriceFull(variant.price) }}</td>
        </tr>
      </tbody>
    </table>
    <p v-else class="package-menu-card__unavailable">
      {{ tr("menu.unavailable") }}
    </p>

    <div class="package-menu-card__action">
      <p
        v-if="showBookingRequirement"
        class="package-menu-card__booking-note"
        data-booking-auth-note
        role="note"
      >
        {{ tr("menu.bookingAuthRequired") }}
      </p>
      <Link
        class="package-menu-card__booking-link"
        :href="detailUrl"
        :aria-label="`${tr('menu.cardCta')}: ${name}`"
      >
        <Calendar aria-hidden="true" />
        {{ `${tr("menu.cardCta")} ${name}` }}
      </Link>
    </div>

    <details
      v-if="regularVariants.length && doubleTherapistVariants.length"
      class="package-menu-card__optional"
    >
      <summary @focus="keepFocusedDisclosureVisible">
        {{ tr("menu.doubleTherapist") }}
      </summary>
      <table class="package-menu-card__comparison">
        <thead>
          <tr>
            <th scope="col">{{ tr("menu.duration") }}</th>
            <th scope="col">{{ tr("menu.price") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="variant in doubleTherapistVariants" :key="variant.code">
            <th scope="row">{{ durationLabel(variant.duration) }}</th>
            <td>{{ formatPriceFull(variant.price) }}</td>
          </tr>
        </tbody>
      </table>
    </details>

    <details v-if="extensions.length" class="package-menu-card__optional">
      <summary @focus="keepFocusedDisclosureVisible">
        {{ tr("menu.extension") }}
      </summary>
      <table class="package-menu-card__comparison">
        <thead>
          <tr>
            <th scope="col">{{ tr("menu.mode") }}</th>
            <th scope="col">{{ tr("menu.extension") }}</th>
            <th scope="col">{{ tr("menu.extensionPrice") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="extension in extensions" :key="extension.code">
            <td>{{ modeLabel(extension.mode) }}</td>
            <th scope="row">{{ durationLabel(extension.duration) }}</th>
            <td>{{ formatPriceFull(extension.price) }}</td>
          </tr>
        </tbody>
      </table>
    </details>
  </article>
</template>
