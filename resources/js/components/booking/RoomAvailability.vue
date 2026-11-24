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
  // On the review step the time cannot be changed here, so say how to go on.
  reviewing?: boolean;
  // What is still missing, so the prompt does not ask for a choice already made.
  needs?: "treatment" | "date";
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
        treatment: "Pilih perawatan dan durasinya untuk melihat ketersediaan.",
        date: "Pilih tanggal untuk melihat ketersediaan.",
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
          "Ketersediaan dapat berubah. Persetujuan staf memesan ruangan yang sesuai dan memastikan terapisnya.",
        blocked:
          "Periksa ketersediaan terbaru dan pilih kembali waktu yang tersedia sebelum melanjutkan.",
      }
    : {
        title: "Room availability",
        rooms: "Rooms",
        refresh: "Refresh availability",
        incomplete:
          "Choose treatment, date and guest count to see availability.",
        treatment: "Choose a treatment and its length to see availability.",
        date: "Choose a date to see availability.",
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
          "Availability can change. Staff approval reserves a suitable room and confirms the therapist.",
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
  if (props.status === "incomplete" && props.needs)
    return copy.value[props.needs];
  if (props.status !== "success")
    return props.status === "disabled" ? "" : copy.value[props.status];
  if (!props.slots.length) return copy.value.empty;
  if (!props.slots.some((slot) => slot.rooms.length))
    return copy.value.incompatible;
  if (!props.slots.some((slot) => slot.rooms.some((room) => room.available)))
    return copy.value.occupied;
  return selected.value ? "" : copy.value.choose;
});
const selectedAvailable = computed(
  () => selected.value?.rooms.some((room) => room.available) ?? false,
);
const checkedAt = computed(() =>
  props.generatedAt ? jakartaAppointment(props.generatedAt) : null,
);
</script>

<template>
  <section
    data-room-availability
    class="bk-step__block room-availability"
    aria-labelledby="room-availability-heading"
    :aria-busy="status === 'loading'"
  >
    <div class="room-availability__head">
      <h3 id="room-availability-heading">{{ copy.title }}</h3>
      <button
        v-if="status !== 'incomplete'"
        type="button"
        class="room-availability__refresh"
        data-availability-refresh
        :disabled="status === 'loading'"
        @click="$emit('refresh')"
      >
        {{ copy.refresh }}
      </button>
    </div>
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
    <p v-if="reviewing && !selectedAvailable" class="room-availability__note">
      {{ copy.blocked }}
    </p>
    <p
      v-if="status === 'success'"
      id="room-availability-caveat"
      class="room-availability__note"
    >
      {{ copy.caveat }}
      <template v-if="checkedAt">
        {{ copy.updated }}: {{ checkedAt.time }} WIB.</template
      >
    </p>
  </section>
</template>

<style scoped>
.room-availability {
  display: grid;
  gap: 0.5rem;
}
.room-availability p {
  margin: 0;
}
.room-availability__head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.25rem 1rem;
}
.room-availability__head h3 {
  margin: 0;
  font-family: inherit;
  font-size: 1rem;
  font-weight: 700;
}
.room-availability__refresh {
  min-height: 44px;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}
.room-availability__refresh:disabled {
  cursor: default;
  opacity: 0.55;
}
.room-availability__note {
  color: color-mix(in oklch, currentColor 72%, transparent);
  font-size: 0.85rem;
  line-height: 1.55;
}
.availability-rooms {
  margin: 0;
  padding-left: 1.25rem;
  overflow-wrap: anywhere;
}
.availability-rooms li {
  margin-block: 0.35rem;
}
</style>
