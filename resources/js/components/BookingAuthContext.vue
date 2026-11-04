<script setup lang="ts">
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import type { BookingAuthContext as BookingAuthContextData } from "@/types";

const props = defineProps<{
  bookingContext: BookingAuthContextData;
  step: "login" | "register" | "verify";
}>();

const { language, tr } = useLanguage();
const treatmentName = computed(() =>
  language.value === "id"
    ? props.bookingContext.nameId
    : props.bookingContext.nameEn,
);
const startingPrice = computed(
  () =>
    "Rp " +
    new Intl.NumberFormat("id-ID").format(
      props.bookingContext.startingPriceIdr,
    ),
);
</script>

<template>
  <section
    class="auth-booking-context"
    data-booking-auth-context
    :aria-label="tr('auth.booking.selection')"
  >
    <p class="auth-booking-context__eyebrow">
      {{ tr("auth.booking.selection") }}
    </p>
    <h2>{{ treatmentName }}</h2>
    <p class="auth-booking-context__price">
      <span>{{ tr("auth.booking.startingPrice") }}</span>
      <strong>{{ startingPrice }}</strong>
    </p>

    <div v-if="step === 'login'" class="auth-booking-context__steps">
      <p>{{ tr("auth.booking.login.existing") }}</p>
      <p>{{ tr("auth.booking.login.new") }}</p>
    </div>
    <p v-else-if="step === 'register'" class="auth-booking-context__steps">
      {{ tr("auth.booking.register.next") }}
    </p>
    <p v-else class="auth-booking-context__steps">
      {{ tr("auth.booking.verify.next") }}
    </p>
  </section>
</template>
