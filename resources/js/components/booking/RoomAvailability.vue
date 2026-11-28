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
    readonly has_waiting_requests?: boolean;
    readonly waiting_requests_count?: number;
  }[];
  selectedTime: string;
  generatedAt: string | null;
  // On the review step the time cannot be changed here, so say how to go on.
  reviewing?: boolean;
  // Staff see individual rooms; guests only need a plain-language summary.
  showRooms?: boolean;
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
        chooseRooms: "Pilih waktu untuk melihat ruangan yang sesuai.",
        chooseSummary: "Pilih waktu untuk memeriksa ketersediaan.",
        summaryAvailable: "Jam {time} WIB tersedia.",
        summaryLastRoom: "Tinggal 1 ruangan kosong untuk jam {time} WIB.",
        available: "Tersedia",
        busy: "Tidak tersedia",
        capacity: "Kapasitas",
        updated: "Diperiksa",
        caveat:
          "Ketersediaan dapat berubah. Persetujuan staf memesan ruangan yang sesuai dan memastikan terapisnya.",
        waitingGuest:
          "Sudah ada tamu lain yang lebih dulu memesan jam {time} WIB. Anda tetap bisa mengirim pesanan, atau pilih jam lain agar lebih pasti.",
        waitingGuestLast:
          "Tinggal 1 ruangan di jam {time} WIB dan sudah ada tamu lain yang lebih dulu memesan. Anda tetap bisa mengirim pesanan, atau pilih jam lain agar lebih pasti.",
        waitingOne:
          "{count} permintaan sedang menunggu konfirmasi untuk waktu ini.",
        waitingCount:
          "{count} permintaan sedang menunggu konfirmasi untuk waktu ini.",
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
        chooseRooms: "Choose a time to see suitable rooms.",
        chooseSummary: "Choose a time to check availability.",
        summaryAvailable: "{time} WIB is available.",
        summaryLastRoom: "Only 1 room left for {time} WIB.",
        available: "Available",
        busy: "Unavailable",
        capacity: "Capacity",
        updated: "Checked",
        caveat:
          "Availability can change. Staff approval reserves a suitable room and confirms the therapist.",
        waitingGuest:
          "Other guests have already requested {time} WIB. You can still send your request, or pick another time to be surer.",
        waitingGuestLast:
          "Only 1 room is left for {time} WIB and another guest has already requested it. You can still send your request, or pick another time to be surer.",
        waitingOne:
          "1 pending request is waiting for confirmation at this time.",
        waitingCount:
          "{count} pending requests are waiting for confirmation at this time.",
        blocked:
          "Check the latest availability and select an available time again before continuing.",
      },
);
const selected = computed(() =>
  props.slots.find(
    (slot) => jakartaAppointment(slot.start_at).time === props.selectedTime,
  ),
);
const freeRoomCount = computed(
  () => selected.value?.rooms.filter((room) => room.available).length ?? 0,
);
const isLastRoom = computed(
  () => freeRoomCount.value === 1 && (selected.value?.rooms.length ?? 0) > 1,
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
  return selected.value
    ? ""
    : props.showRooms
      ? copy.value.chooseRooms
      : copy.value.chooseSummary;
});
const selectedAvailable = computed(() => freeRoomCount.value > 0);
const waitingNote = computed(() => {
  if (
    props.status !== "success" ||
    !selected.value ||
    !selectedAvailable.value ||
    selected.value.has_waiting_requests !== true
  )
    return "";
  const count = selected.value.waiting_requests_count;
  if (typeof count === "number" && count > 0) {
    if (props.language !== "id" && count === 1) return copy.value.waitingOne;
    return copy.value.waitingCount.replace("{count}", String(count));
  }
  const template = isLastRoom.value
    ? copy.value.waitingGuestLast
    : copy.value.waitingGuest;
  return template.replace("{time}", props.selectedTime);
});
const guestSummary = computed(() => {
  if (
    props.showRooms ||
    props.status !== "success" ||
    !selected.value ||
    !selectedAvailable.value ||
    waitingNote.value
  )
    return "";
  const template = isLastRoom.value
    ? copy.value.summaryLastRoom
    : copy.value.summaryAvailable;
  return template.replace("{time}", props.selectedTime);
});
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
    <p
      v-if="message"
      id="room-availability-status"
      role="status"
      aria-live="polite"
    >
      {{ message }}
    </p>
    <p
      v-else-if="guestSummary"
      id="room-availability-status"
      data-availability-summary
      class="room-availability__summary"
      role="status"
      aria-live="polite"
    >
      {{ guestSummary }}
    </p>
    <p
      v-if="waitingNote"
      id="room-availability-waiting"
      data-availability-waiting
      class="room-availability__waiting"
      role="status"
      aria-live="polite"
    >
      {{ waitingNote }}
    </p>
    <ul
      v-if="showRooms && selected"
      class="availability-rooms"
      :aria-label="copy.rooms"
    >
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
.room-availability__summary {
  font-size: 0.9375rem;
  font-weight: 600;
  line-height: 1.5;
}
.room-availability__note {
  color: color-mix(in oklch, currentColor 72%, transparent);
  font-size: 0.85rem;
  line-height: 1.55;
}
.room-availability__waiting {
  padding: 0.625rem 0.875rem;
  border: 1px solid
    color-mix(
      in srgb,
      var(--admin-warning, var(--gold-deep, currentColor)) 30%,
      transparent
    );
  border-radius: var(--admin-radius, 0.625rem);
  background: var(--admin-warning-bg, var(--gold-soft, transparent));
  color: var(--admin-warning, var(--ink, currentColor));
  font-size: 0.875rem;
  font-weight: 600;
  line-height: 1.5;
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
