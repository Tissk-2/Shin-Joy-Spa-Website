<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";

import PackageConfigurator from "@/components/PackageConfigurator.vue";
import { useLanguage } from "@/composables/useLanguage";
import { applyImageFallback } from "@/lib/media";
import { emptySelection } from "@/lib/serviceSelection";
import type { AppPageProps, PackageData } from "@/types";

const props = defineProps<{ servicePackage: PackageData }>();
const page = usePage<AppPageProps>();
const { tr } = useLanguage();
const selection = ref(emptySelection(props.servicePackage.slug));
const name = computed(() =>
  page.props.locale === "id"
    ? props.servicePackage.nameId
    : props.servicePackage.nameEn,
);
const description = computed(() =>
  page.props.locale === "id"
    ? props.servicePackage.descId
    : props.servicePackage.descEn,
);
const benefit = computed(() =>
  page.props.locale === "id"
    ? props.servicePackage.benefitId
    : props.servicePackage.benefitEn,
);
const detailUrl = computed(() => `/services/${props.servicePackage.slug}`);
const startingPrice = computed(() => {
  const prices = props.servicePackage.variants
    .map((variant) => variant.price)
    .filter((price) => Number.isFinite(price) && price >= 0);

  return prices.length > 0 ? Math.min(...prices) : null;
});
const formattedStartingPrice = computed(() =>
  startingPrice.value === null
    ? tr("menu.unavailable")
    : `${tr("menu.from")} Rp ${new Intl.NumberFormat("id-ID").format(startingPrice.value)}`,
);
</script>

<template>
  <article class="package-menu-card" :aria-label="name" data-package-card>
    <img
      :src="servicePackage.image"
      :alt="name"
      loading="lazy"
      @error="applyImageFallback"
    />
    <span v-if="servicePackage.isVip" class="package-menu-card__vip">VIP</span>
    <h3>{{ name }}</h3>
    <p>{{ description }}</p>
    <p class="package-menu-card__starting-price" data-starting-price>
      {{ formattedStartingPrice }}
    </p>
    <p v-if="benefit" class="package-menu-card__benefit">{{ benefit }}</p>
    <PackageConfigurator
      v-model="selection"
      :service-package="servicePackage"
    />
    <Link class="package-menu-card__detail" :href="detailUrl">
      {{ tr("menu.viewDetails") }}
    </Link>
  </article>
</template>
