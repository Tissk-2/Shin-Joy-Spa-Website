<script setup lang="ts">
import { computed } from "vue";
import type { AvailabilityStatus } from "@/composables/useRoomAvailability";
import {
  jakartaAppointment,
  type AvailabilitySlot,
} from "@/lib/roomAvailability";

const props = defineProps<{
  language: string;
  status: AvailabilityStatus;
  slots: readonly {
    readonly start_at: string;
    readonly rooms: Readonly<AvailabilitySlot["rooms"]>;
  }[];
  selectedTime: string;
  generatedAt: string | null;
}>();
defineEmits<{ refresh: [] }>();
const copy = computed(() =>
  props.language === "id"
    ? {
        title: "Ketersediaan ruangan",
        rooms: "Ruangan",
        refresh: "Muat ulang ketersediaan",
        incomplete:
          "Pilih perawatan, tanggal, dan jumlah tamu untuk melihat ketersediaan.",
        loading: "Memuat ketersediaan ruangan…",
        invalid:
          "Pilihan tidak valid. Periksa perawatan, tanggal, dan jumlah tamu, lalu muat ulang.",
        "rate-limit":
          "Terlalu banyak permintaan. Tunggu sebentar, lalu muat ulang.",
        auth: "Sesi Anda berakhir atau akses tidak tersedia. Masuk kembali, lalu muat ulang.",
        unavailable:
          "Ketersediaan belum dapat dimuat. Muat ulang untuk mencoba lagi.",
        empty: "Tidak ada waktu mulai untuk tanggal dan perawatan ini.",
        incompatible:
          "Tidak ada ruangan yang sesuai dengan perawatan dan jumlah tamu ini.",
        occupied:
          "Semua ruangan yang sesuai sudah terisi untuk waktu yang ditampilkan. Pilih tanggal atau perawatan lain, atau muat ulang.",
        choose: "Pilih waktu untuk melihat ruangan yang sesuai.",
        available: "Tersedia",
        busy: "Tidak tersedia",
        capacity: "Kapasitas",
        updated: "Diperiksa",
        caveat:
          "Ketersediaan dapat berubah. Persetujuan staf akan memesan ruangan yang sesuai. Kesesuaian terapis perlu dikonfirmasi staf.",
        blocked:
          "Periksa ketersediaan terbaru dan pilih kembali waktu yang tersedia sebelum melanjutkan.",
      }
    : {
        title: "Room availability",
        rooms: "Rooms",
        refresh: "Refresh availability",
        incomplete:
          "Choose treatment, date and guest count to see availability.",
        loading: "Loading room availability…",
        invalid:
          "Invalid selection. Check treatment, date and guests, then refresh.",
        "rate-limit": "Too many requests. Wait a moment, then refresh.",
        auth: "Your session expired or access is unavailable. Sign in again, then refresh.",
        unavailable: "Availability could not be loaded. Refresh to try again.",
        empty: "No start times are available for this date and treatment.",
        incompatible: "No compatible rooms for this treatment and guest count.",
        occupied:
          "Suitable rooms are fully occupied for the displayed times. Choose another date or treatment, or refresh.",
        choose: "Choose a time to see suitable rooms.",
        available: "Available",
        busy: "Unavailable",
        capacity: "Capacity",
        updated: "Checked",
        caveat:
          "Availability can change. Staff approval reserves a suitable room. Therapist suitability needs staff confirmation.",
        blocked:
          "Check the latest availability and select an available time again before continuing.",
      },
);
const selected = computed(() =>
  props.slots.find(
    (slot) => jakartaAppointment(slot.start_at).time === props.selectedTime,
  ),
);
const message = computed(() => {
  if (props.status !== "success")
    return props.status === "disabled" ? "" : copy.value[props.status];
  if (!props.slots.length) return copy.value.empty;
  if (!props.slots.some((slot) => slot.rooms.length))
    return copy.value.incompatible;
  if (!props.slots.some((slot) => slot.rooms.some((room) => room.available)))
    return copy.value.occupied;
  return selected.value ? "" : copy.value.choose;
});
const checkedAt = computed(() =>
  props.generatedAt ? jakartaAppointment(props.generatedAt) : null,
);
</script>

<template>
  <section
    data-room-availability
    class="bk-step__block"
    aria-labelledby="room-availability-heading"
    :aria-busy="status === 'loading'"
  >
    <h3 id="room-availability-heading" class="h3">{{ copy.title }}</h3>
    <p id="room-availability-status" role="status" aria-live="polite">
      {{ message }}
    </p>
    <ul v-if="selected" class="availability-rooms" :aria-label="copy.rooms">
      <li v-for="room in selected.rooms" :key="room.code">
        <strong>{{ room.name }}</strong
        ><span v-if="room.type"> · {{ room.type }}</span>
        <span>
          · {{ copy.capacity }}: {{ room.capacity }} ·
          {{ room.available ? copy.available : copy.busy }}</span
        >
      </li>
    </ul>
    <p v-if="checkedAt" class="muted">
      {{ copy.updated }}: {{ checkedAt.date }} {{ checkedAt.time }} WIB
    </p>
    <p id="room-availability-caveat" class="estimate-scope">
      {{ copy.caveat }}
    </p>
    <p
      v-if="!selected || !selected.rooms.some((room) => room.available)"
      class="muted"
    >
      {{ copy.blocked }}
    </p>
    <button
      type="button"
      class="btn btn--primary"
      data-availability-refresh
      :disabled="status === 'loading' || status === 'incomplete'"
      @click="$emit('refresh')"
    >
      {{ copy.refresh }}
    </button>
  </section>
</template>

<style scoped>
.availability-rooms {
  padding-left: 1.25rem;
  overflow-wrap: anywhere;
}
.availability-rooms li {
  margin-block: 0.5rem;
}
</style>
