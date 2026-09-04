<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

import {
  bookingUrl,
  normalizeSelection,
  selectionTotal,
  type ServiceSelection,
} from "@/lib/serviceSelection";
import type { AppPageProps, PackageData, TreatmentMode } from "@/types";

const props = withDefaults(
  defineProps<{
    modelValue: ServiceSelection;
    servicePackage: PackageData;
    compact?: boolean;
    showBookingLink?: boolean;
  }>(),
  { compact: false, showBookingLink: true },
);
const emit = defineEmits<{
  "update:modelValue": [selection: ServiceSelection];
}>();
const page = usePage<AppPageProps>();
const selection = ref(
  normalizeSelection(props.servicePackage, props.modelValue),
);
const locale = computed(() => page.props.locale);
const total = computed(() =>
  selectionTotal(props.servicePackage, selection.value),
);
const href = computed(() => bookingUrl(selection.value));
const isValid = computed(() => total.value.duration > 0);
const modes = computed(() =>
  (["regular", "double_therapist"] as const).filter((mode) =>
    props.servicePackage.variants.some((variant) => variant.mode === mode),
  ),
);
const durations = computed(() =>
  selection.value.mode
    ? props.servicePackage.variants.filter(
        (variant) => variant.mode === selection.value.mode,
      )
    : [],
);
const extension = computed(() =>
  selection.value.mode
    ? props.servicePackage.extensions.find(
        (item) => item.mode === selection.value.mode,
      )
    : undefined,
);

watch(
  () => [props.servicePackage, props.modelValue] as const,
  ([servicePackage, modelValue]) => {
    selection.value = normalizeSelection(servicePackage, modelValue);
  },
  { deep: true },
);

function update(next: ServiceSelection): void {
  const normalized = normalizeSelection(props.servicePackage, next);
  selection.value = normalized;
  emit("update:modelValue", normalized);
}

function chooseMode(mode: TreatmentMode): void {
  update({
    ...selection.value,
    service: props.servicePackage.slug,
    mode,
    duration: null,
    extension: "",
  });
}

function chooseDuration(duration: number): void {
  update({ ...selection.value, duration, extension: "" });
}

function toggleExtension(checked: boolean): void {
  update({
    ...selection.value,
    extension: checked ? (extension.value?.code ?? "") : "",
  });
}

function modeLabel(mode: TreatmentMode): string {
  if (locale.value === "id")
    return mode === "regular" ? "Reguler" : "Dua terapis";
  return mode === "regular" ? "Regular" : "Double therapist";
}

const labels = computed(() =>
  locale.value === "id"
    ? {
        treatment: "Jenis perawatan",
        duration: "Durasi",
        extension: "Tambahan",
        total: "Total",
        book: "Pesan sekarang",
        select: "Pilih durasi terlebih dahulu",
      }
    : {
        treatment: "Treatment type",
        duration: "Duration",
        extension: "Extension",
        total: "Total",
        book: "Book now",
        select: "Choose a duration first",
      },
);
</script>

<template>
  <section
    class="package-configurator"
    :class="{ 'package-configurator--compact': compact }"
  >
    <div
      class="package-configurator__group"
      role="radiogroup"
      :aria-label="labels.treatment"
    >
      <span class="package-configurator__label">{{ labels.treatment }}</span>
      <button
        v-for="mode in modes"
        :key="mode"
        type="button"
        role="radio"
        :data-mode="mode"
        :aria-checked="selection.mode === mode"
        :aria-pressed="selection.mode === mode"
        @click="chooseMode(mode)"
      >
        {{ modeLabel(mode) }}
      </button>
    </div>

    <div
      v-if="selection.mode"
      class="package-configurator__group"
      role="radiogroup"
      :aria-label="labels.duration"
    >
      <span class="package-configurator__label">{{ labels.duration }}</span>
      <button
        v-for="variant in durations"
        :key="variant.code"
        type="button"
        role="radio"
        :data-duration="variant.duration"
        :aria-checked="selection.duration === variant.duration"
        :aria-pressed="selection.duration === variant.duration"
        @click="chooseDuration(variant.duration)"
      >
        {{ variant.duration }} min · Rp
        {{ variant.price.toLocaleString("id-ID") }}
      </button>
    </div>

    <label
      v-if="extension && selection.duration"
      class="package-configurator__extension"
    >
      <input
        type="checkbox"
        :value="extension.code"
        :checked="selection.extension === extension.code"
        @change="toggleExtension(($event.target as HTMLInputElement).checked)"
      />
      {{ labels.extension }} +{{ extension.duration }} min · Rp
      {{ extension.price.toLocaleString("id-ID") }}
    </label>

    <p class="package-configurator__total" aria-live="polite">
      {{ labels.total }}: {{ total.duration }} min · Rp
      {{ total.price.toLocaleString("id-ID") }}
    </p>

    <Link
      v-if="showBookingLink && isValid"
      class="package-configurator__cta"
      :href="href"
      data-booking-cta="desktop"
      >{{ labels.book }}</Link
    >
    <button
      v-else
      class="package-configurator__cta"
      type="button"
      disabled
      data-booking-cta="desktop"
    >
      {{ labels.select }}
    </button>
    <div class="package-configurator__mobile-cta">
      <Link
        v-if="showBookingLink && isValid"
        class="package-configurator__cta"
        :href="href"
        >{{ labels.book }}</Link
      >
      <button v-else class="package-configurator__cta" type="button" disabled>
        {{ labels.select }}
      </button>
    </div>
  </section>
</template>
