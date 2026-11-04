<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { useLanguage } from "@/composables/useLanguage";

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
    guests?: number;
  }>(),
  { compact: false, showBookingLink: true, guests: 1 },
);
const emit = defineEmits<{
  "update:modelValue": [selection: ServiceSelection];
}>();
const page = usePage<AppPageProps>();
const { tr } = useLanguage();
const selection = ref(
  normalizeSelection(props.servicePackage, props.modelValue),
);
const locale = computed(() => page.props.locale);
const total = computed(() =>
  selectionTotal(props.servicePackage, selection.value, props.guests),
);
const pricingBasisLabel = computed(() => {
  if (!props.servicePackage.pricingVerified) return "";
  return tr("b.totalGuests", { count: props.guests });
});
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

function nextRadio(
  event: KeyboardEvent,
  select: (value: string) => void,
): void {
  const group = (event.currentTarget as HTMLElement).closest(
    '[role="radiogroup"]',
  );
  const radios = Array.from(
    group?.querySelectorAll<HTMLButtonElement>('[role="radio"]') ?? [],
  );
  const current = radios.indexOf(event.currentTarget as HTMLButtonElement);
  if (radios.length === 0 || current < 0) return;

  let next = current;
  if (event.key === "ArrowRight" || event.key === "ArrowDown")
    next = (current + 1) % radios.length;
  else if (event.key === "ArrowLeft" || event.key === "ArrowUp")
    next = (current - 1 + radios.length) % radios.length;
  else if (event.key === "Home") next = 0;
  else if (event.key === "End") next = radios.length - 1;
  else return;

  event.preventDefault();
  const target = radios[next];
  const value = target.dataset.mode ?? target.dataset.duration;
  if (!value) return;
  select(value);
  target.focus();
}

function selectModeFromKeyboard(mode: string): void {
  if (mode === "regular" || mode === "double_therapist") chooseMode(mode);
}

function selectDurationFromKeyboard(duration: string): void {
  const parsed = Number(duration);
  if (Number.isInteger(parsed)) chooseDuration(parsed);
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
        selectType: "Pilih jenis perawatan terlebih dahulu",
        select: "Pilih durasi terlebih dahulu",
      }
    : {
        treatment: "Treatment type",
        duration: "Duration",
        extension: "Extension",
        total: "Total",
        book: "Book now",
        selectType: "Choose a treatment type first",
        select: "Choose a duration first",
      },
);
</script>

<template>
  <section
    class="package-configurator"
    :class="{
      'package-configurator--compact': compact,
      'package-configurator--vip': servicePackage.isVip,
    }"
  >
    <div
      class="package-configurator__group"
      role="radiogroup"
      :aria-label="labels.treatment"
    >
      <span class="package-configurator__label">{{ labels.treatment }}</span>
      <button
        v-for="(mode, index) in modes"
        :key="mode"
        type="button"
        role="radio"
        :data-mode="mode"
        :aria-checked="selection.mode === mode"
        :aria-pressed="selection.mode === mode"
        :tabindex="
          selection.mode === mode || (!selection.mode && index === 0) ? 0 : -1
        "
        @click="chooseMode(mode)"
        @keydown="nextRadio($event, selectModeFromKeyboard)"
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
        v-for="(variant, index) in durations"
        :key="variant.code"
        type="button"
        role="radio"
        :data-duration="variant.duration"
        :aria-checked="selection.duration === variant.duration"
        :aria-pressed="selection.duration === variant.duration"
        :tabindex="
          selection.duration === variant.duration ||
          (selection.duration === null && index === 0)
            ? 0
            : -1
        "
        @click="chooseDuration(variant.duration)"
        @keydown="nextRadio($event, selectDurationFromKeyboard)"
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

    <div role="status" aria-live="polite" aria-atomic="true">
      <p v-if="isValid" class="package-configurator__total">
        {{ labels.total
        }}<template v-if="pricingBasisLabel">
          · {{ pricingBasisLabel }}</template
        >: {{ total.duration }} min · Rp
        {{ total.price.toLocaleString("id-ID") }}
      </p>
      <p v-else class="package-configurator__guidance">
        {{ selection.mode ? labels.select : labels.selectType }}
      </p>
    </div>

    <template v-if="showBookingLink">
      <Link
        v-if="isValid"
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
        {{ selection.mode ? labels.select : labels.selectType }}
      </button>
    </template>
  </section>
</template>
