<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import PackageConfigurator from "@/components/PackageConfigurator.vue";
import { useLanguage } from "@/composables/useLanguage";
import {
  timeSlots,
  todayIso,
  validateBooking,
  type BookingErrors,
} from "@/lib/booking";
import {
  emptySelection,
  selectionTotal,
  type ServiceSelection,
} from "@/lib/serviceSelection";
import MainLayout from "@/layouts/MainLayout.vue";
import type { AppPageProps, PackageData } from "@/types";

interface Details {
  name: string;
  phone: string;
  date: string;
  time: string;
  guests: number;
  notes: string;
}
const props = defineProps<{
  packages: PackageData[];
  selection: ServiceSelection;
}>();
const page = usePage<AppPageProps>();
const { language, tr } = useLanguage();
const user = page.props.auth.user;
const selected = ref<ServiceSelection>(props.selection);
const details = ref<Details>({
  name: user?.name ?? "",
  phone: user?.phone ?? "",
  date: "",
  time: "",
  guests: 1,
  notes: "",
});
const errors = ref<BookingErrors>({});
const reviewing = ref(false);
const servicePackage = computed(() =>
  props.packages.find((item) => item.slug === selected.value.service),
);
const total = computed(() =>
  servicePackage.value
    ? selectionTotal(servicePackage.value, selected.value)
    : { duration: 0, price: 0 },
);
const packageName = computed(() =>
  servicePackage.value
    ? language.value === "id"
      ? servicePackage.value.nameId
      : servicePackage.value.nameEn
    : tr("b.sumempty"),
);
const modeLabel = computed(() =>
  selected.value.mode === "regular"
    ? language.value === "id"
      ? "Reguler"
      : "Regular"
    : selected.value.mode === "double_therapist"
      ? language.value === "id"
        ? "Dua terapis"
        : "Double therapist"
      : tr("b.sumempty"),
);
const labels = computed(() =>
  language.value === "id"
    ? {
        choose: "Pilih paket",
        review: "Tinjau reservasi Anda",
        action: "Tinjau reservasi",
        noReservation: "Reservasi belum dibuat",
        edit: "Ubah detail",
        email: "Email akun",
        mode: "Jenis perawatan",
        base: "Durasi dasar",
        extension: "Tambahan",
      }
    : {
        choose: "Choose a package",
        review: "Review your booking",
        action: "Review booking",
        noReservation: "No reservation has been created yet",
        edit: "Edit details",
        email: "Account email",
        mode: "Treatment type",
        base: "Base duration",
        extension: "Extension",
      },
);
const extensionLabel = computed(() => {
  const extension = servicePackage.value?.extensions.find(
    (item) => item.code === selected.value.extension,
  );
  return extension
    ? `${extension.duration} ${tr("d.min")} · Rp ${extension.price.toLocaleString("id-ID")}`
    : "—";
});
function choosePackage(slug: string): void {
  selected.value = emptySelection(slug);
  errors.value = {};
}
function submit(): void {
  errors.value = validateBooking(
    { ...details.value, ...selected.value },
    props.packages,
  );
  if (!Object.keys(errors.value).length) reviewing.value = true;
}
</script>

<template>
  <Head :title="`${tr('b.title')} · Shin Joy Spa Malang`" />
  <MainLayout>
    <section class="page-hero">
      <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <Link class="ulink" href="/">{{ tr("nav.home") }}</Link
          ><span aria-hidden="true">›</span><span>{{ tr("nav.book") }}</span>
        </nav>
        <h1>{{ tr("b.title") }}</h1>
        <p class="lead">{{ tr("menu.bookingReview") }}</p>
      </div>
    </section>
    <section class="section">
      <div class="container">
        <section v-if="reviewing" class="form-success show" role="status">
          <h2 class="h3">{{ labels.review }}</h2>
          <p class="muted">{{ labels.noReservation }}</p>
          <div class="booking-recap">
            <div class="summary-row">
              <span>{{ tr("b.sumservice") }}</span
              ><span>{{ packageName }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.mode }}</span
              ><span>{{ modeLabel }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumduration") }}</span
              ><span>{{ total.duration }} {{ tr("d.min") }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumdate") }}</span
              ><span>{{ details.date }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumtime") }}</span
              ><span>{{ details.time }} WIB</span>
            </div>
            <div class="summary-total">
              <span>{{ tr("b.total") }}</span
              ><span class="amt"
                >Rp {{ total.price.toLocaleString("id-ID") }}</span
              >
            </div>
          </div>
          <button
            type="button"
            class="btn btn--primary"
            @click="reviewing = false"
          >
            {{ labels.edit }}
          </button>
        </section>
        <div v-else class="booking-layout">
          <form novalidate @submit.prevent="submit">
            <div class="form-grid">
              <div class="field full">
                <label for="f-name">{{ tr("b.fname") }}</label
                ><input
                  id="f-name"
                  v-model="details.name"
                  type="text"
                  autocomplete="name"
                /><span class="err">{{
                  errors.name ? tr(errors.name) : ""
                }}</span>
              </div>
              <div class="field">
                <label for="f-phone">{{ tr("b.fphone") }}</label
                ><input
                  id="f-phone"
                  v-model="details.phone"
                  type="tel"
                  autocomplete="tel"
                /><span class="err">{{
                  errors.phone ? tr(errors.phone) : ""
                }}</span>
              </div>
              <div class="field">
                <label for="f-email">{{ labels.email }}</label
                ><input
                  id="f-email"
                  :value="user?.email ?? ''"
                  type="email"
                  readonly
                />
              </div>
              <fieldset class="field full">
                <legend>{{ labels.choose }}</legend>
                <div class="package-choice-buttons">
                  <button
                    v-for="item in props.packages"
                    :key="item.slug"
                    type="button"
                    :aria-pressed="selected.service === item.slug"
                    @click="choosePackage(item.slug)"
                  >
                    {{ language === "id" ? item.nameId : item.nameEn }}
                  </button>
                </div>
                <span class="err">{{
                  errors.service ? tr(errors.service) : ""
                }}</span>
              </fieldset>
              <div v-if="servicePackage" class="full">
                <PackageConfigurator
                  v-model="selected"
                  :service-package="servicePackage"
                  :show-booking-link="false"
                /><span class="err">{{
                  errors.mode
                    ? tr(errors.mode)
                    : errors.duration
                      ? tr(errors.duration)
                      : errors.extension
                        ? tr(errors.extension)
                        : ""
                }}</span>
              </div>
              <div class="field">
                <label for="f-date">{{ tr("b.fdate") }}</label
                ><input
                  id="f-date"
                  v-model="details.date"
                  type="date"
                  :min="todayIso()"
                /><span class="err">{{
                  errors.date ? tr(errors.date) : ""
                }}</span>
              </div>
              <div class="field">
                <label for="f-time">{{ tr("b.ftime") }}</label
                ><select id="f-time" v-model="details.time">
                  <option value="">{{ tr("b.ftime") }}</option>
                  <option v-for="slot in timeSlots" :key="slot" :value="slot">
                    {{ slot }}
                  </option></select
                ><span class="err">{{
                  errors.time ? tr(errors.time) : ""
                }}</span>
              </div>
              <div class="field full">
                <label for="f-guests">{{ tr("b.fguests") }}</label
                ><select id="f-guests" v-model="details.guests">
                  <option v-for="guest in 4" :key="guest" :value="guest">
                    {{ tr(`b.guest${guest}`) }}
                  </option>
                </select>
              </div>
              <div class="field full">
                <label for="f-notes">{{ tr("b.fnotes") }}</label
                ><textarea
                  id="f-notes"
                  v-model="details.notes"
                  :placeholder="tr('b.fnotesph')"
                ></textarea>
              </div>
              <div class="full">
                <button
                  type="submit"
                  class="btn btn--primary btn--lg btn--block"
                >
                  {{ labels.action }}
                </button>
              </div>
            </div>
          </form>
          <aside class="summary-card" :aria-label="tr('b.summary')">
            <h3>{{ tr("b.summary") }}</h3>
            <div class="summary-row">
              <span>{{ tr("b.sumservice") }}</span
              ><span>{{ packageName }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.mode }}</span
              ><span>{{ modeLabel }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.base }}</span
              ><span>{{
                selected.duration ? `${selected.duration} ${tr("d.min")}` : "—"
              }}</span>
            </div>
            <div class="summary-row">
              <span>{{ labels.extension }}</span
              ><span>{{ extensionLabel }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumduration") }}</span
              ><span>{{
                total.duration ? `${total.duration} ${tr("d.min")}` : "—"
              }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumdate") }}</span
              ><span>{{ details.date || tr("b.sumempty") }}</span>
            </div>
            <div class="summary-row">
              <span>{{ tr("b.sumtime") }}</span
              ><span>{{ details.time || tr("b.sumempty") }}</span>
            </div>
            <div class="summary-total">
              <span>{{ tr("b.total") }}</span
              ><span class="amt">{{
                total.duration
                  ? `Rp ${total.price.toLocaleString("id-ID")}`
                  : "—"
              }}</span>
            </div>
          </aside>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
