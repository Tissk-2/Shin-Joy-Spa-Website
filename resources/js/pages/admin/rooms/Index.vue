<script setup lang="ts">
import { computed, ref } from "vue";
import { usePoll } from "@inertiajs/vue3";
import { DoorClosed, Search } from "lucide-vue-next";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import RoomDetails from "@/components/admin/rooms/RoomDetails.vue";
import RoomMap from "@/components/admin/rooms/RoomMap.vue";
import RoomStatusBadges from "@/components/admin/rooms/RoomStatusBadges.vue";
import type {
  AdminRoomInventory,
  RoomStatus,
  RoomManagementAccess,
  RoomBlockAccess,
} from "@/types";

const props = defineProps<{
  inventory: AdminRoomInventory;
  management?: RoomManagementAccess;
  blockManagement?: RoomBlockAccess;
}>();
const { language, tr } = useLanguage();
// Keeps the room map current: guests arrive and leave without anyone reloading.
usePoll(60_000, { only: ["inventory"] });
const searchQuery = ref("");
const statusFilter = ref<RoomStatus>("all");
const typeFilter = ref("all");
// Tagged values keep actual strings such as "all" and "unset" distinct from filter choices.
const typeChoices = computed(() =>
  [...new Set(props.inventory.rooms.map((room) => room.type))]
    .sort((a, b) => (a === null ? -1 : b === null ? 1 : a.localeCompare(b)))
    .map((type) => ({
      value: JSON.stringify([type]),
      label: type ?? tr("ad.rooms.unsetType"),
    })),
);
const stats = computed(() => ({
  total: props.inventory.rooms.length,
  enabled: props.inventory.rooms.filter((room) => room.isActive).length,
  blocked: props.inventory.rooms.filter((room) => room.currentBlockCount > 0)
    .length,
  capacity: props.inventory.rooms
    .filter((room) => room.isActive)
    .reduce((sum, room) => sum + room.capacity, 0),
}));
const filteredRooms = computed(() =>
  props.inventory.rooms.filter((room) => {
    const search = searchQuery.value.toLowerCase();
    const matchesStatus =
      statusFilter.value === "all" ||
      (statusFilter.value === "enabled" && room.isActive) ||
      (statusFilter.value === "blocked" && room.currentBlockCount > 0) ||
      (statusFilter.value === "disabled" && !room.isActive);
    return (
      (room.code.toLowerCase().includes(search) ||
        room.name.toLowerCase().includes(search)) &&
      matchesStatus &&
      (typeFilter.value === "all" ||
        typeFilter.value === JSON.stringify([room.type]))
    );
  }),
);
const observation = computed(
  () =>
    new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
      timeZone: "Asia/Jakarta",
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23",
    }).format(new Date(props.inventory.generatedAt)) + " WIB",
);
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.rooms.title')"
      :subtitle="tr('ad.rooms.subtitle')"
    >
      <div class="admin-content">
        <div class="admin-filter-panel space-y-2 text-sm">
          <div class="flex flex-wrap gap-3">
            <a href="/admin/room-blocks" class="admin-button">{{
              tr("ad.blocks.title")
            }}</a>
            <a
              v-if="blockManagement?.canManageBlocks && blockManagement.ready"
              href="/admin/room-blocks/create"
              class="admin-button"
              >{{ tr("ad.blocks.create") }}</a
            >
          </div>
          <p v-if="!management?.ready">{{ tr("ad.rooms.readOnly") }}</p>
          <a
            v-if="management?.isOwner && management.ready"
            href="/admin/rooms/create"
            class="admin-button admin-button--primary w-fit"
            >{{ tr("ad.rooms.create") }}</a
          >
          <p v-if="management?.isOwner && !management.ready" role="status">
            {{ tr("ad.rooms.writeUnavailable") }}
          </p>
          <p class="text-[var(--admin-muted)]">
            {{ tr("ad.rooms.availabilityNote") }}
          </p>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p>
              {{ tr("ad.rooms.observed") }}
              <time :datetime="inventory.generatedAt">{{ observation }}</time>
            </p>
            <a href="/admin/rooms" class="admin-button">{{
              tr("ad.rooms.refresh")
            }}</a>
          </div>
          <p
            v-if="!inventory.schedulingEnabled"
            class="text-[var(--admin-muted)]"
          >
            {{ tr("ad.rooms.schedulingOff") }}
          </p>
        </div>
        <p
          v-if="inventory.state === 'unavailable'"
          class="admin-empty"
          role="status"
        >
          {{ tr("ad.rooms.unavailable") }}
        </p>
        <template v-else>
          <dl class="admin-count-grid">
            <div class="admin-count admin-count--today">
              <dt>{{ tr("ad.rooms.totalRooms") }}</dt>
              <dd>{{ stats.total }}</dd>
            </div>
            <div class="admin-count admin-count--confirmed">
              <dt>{{ tr("ad.rooms.enabledRooms") }}</dt>
              <dd class="text-[var(--admin-success)]">{{ stats.enabled }}</dd>
            </div>
            <div class="admin-count admin-count--pending">
              <dt>{{ tr("ad.rooms.blockedRooms") }}</dt>
              <dd class="text-[var(--admin-warning)]">{{ stats.blocked }}</dd>
            </div>
            <div class="admin-count">
              <dt>{{ tr("ad.rooms.enabledCapacity") }}</dt>
              <dd>
                {{ stats.capacity }}
                <span class="text-sm font-normal text-[var(--admin-muted)]">{{
                  tr("ad.rooms.guests")
                }}</span>
              </dd>
            </div>
          </dl>
          <p
            v-if="inventory.rooms.length === 0"
            class="admin-empty"
            role="status"
          >
            {{ tr("ad.rooms.emptyInventory") }}
          </p>
          <template v-else>
            <RoomMap
              :rooms="inventory.rooms"
              :generated-at="inventory.generatedAt"
              :scheduling-enabled="inventory.schedulingEnabled"
              :can-edit="!!(management?.isOwner && management.ready)"
            />
            <div class="admin-filter-panel">
              <div class="flex flex-1 flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1 basis-60">
                  <label
                    for="room-search"
                    class="mb-1 block text-xs font-semibold"
                    >{{ tr("ad.rooms.search") }}</label
                  >
                  <div class="relative">
                    <Search
                      aria-hidden="true"
                      class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--admin-input)]"
                    />
                    <input
                      id="room-search"
                      v-model="searchQuery"
                      type="search"
                      class="admin-control pl-10"
                      :placeholder="tr('ad.rooms.searchPlaceholder')"
                    />
                  </div>
                </div>
                <div class="w-full sm:w-auto">
                  <label
                    for="room-status"
                    class="mb-1 block text-xs font-semibold"
                    >{{ tr("ad.rooms.status") }}</label
                  >
                  <select
                    id="room-status"
                    v-model="statusFilter"
                    class="admin-control"
                  >
                    <option value="all">{{ tr("ad.rooms.filterAll") }}</option>
                    <option value="enabled">
                      {{ tr("ad.rooms.enabled") }}
                    </option>
                    <option value="blocked">
                      {{ tr("ad.rooms.blocked") }}
                    </option>
                    <option value="disabled">
                      {{ tr("ad.rooms.disabled") }}
                    </option>
                  </select>
                </div>
                <div class="w-full sm:w-auto">
                  <label
                    for="room-type"
                    class="mb-1 block text-xs font-semibold"
                    >{{ tr("ad.rooms.type") }}</label
                  >
                  <select
                    id="room-type"
                    v-model="typeFilter"
                    class="admin-control"
                  >
                    <option value="all">{{ tr("ad.rooms.typeAll") }}</option>
                    <option
                      v-for="choice in typeChoices"
                      :key="choice.value"
                      :value="choice.value"
                    >
                      {{ choice.label }}
                    </option>
                  </select>
                </div>
              </div>
            </div>
            <p class="text-xs text-[var(--admin-muted)]">
              {{ tr("ad.rooms.blockWindow") }}
            </p>
            <p
              v-if="filteredRooms.length === 0"
              class="admin-empty"
              role="status"
            >
              {{ tr("ad.rooms.empty") }}
            </p>
            <div v-else class="admin-booking-table-wrap">
              <table class="admin-booking-table admin-rooms-table w-full">
                <caption class="sr-only">
                  {{
                    tr("ad.rooms.title")
                  }}
                </caption>
                <thead>
                  <tr>
                    <th scope="col">
                      {{ tr("ad.rooms.code") }} &amp; {{ tr("ad.rooms.name") }}
                    </th>
                    <th scope="col">{{ tr("ad.rooms.capacity") }}</th>
                    <th scope="col">{{ tr("ad.rooms.cleaning") }}</th>
                    <th scope="col">{{ tr("ad.rooms.treatments") }}</th>
                    <th scope="col">{{ tr("ad.rooms.status") }}</th>
                    <th scope="col">{{ tr("ad.rooms.blocks") }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="room in filteredRooms"
                    :key="room.id"
                    :data-room-id="room.id"
                    class="transition-colors hover:bg-[var(--admin-canvas)]"
                  >
                    <td>
                      <div class="flex items-center gap-2.5">
                        <span
                          class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-md bg-[var(--admin-selected)] text-[var(--admin-plum)]"
                          ><DoorClosed aria-hidden="true" class="h-4 w-4"
                        /></span>
                        <div class="min-w-0 break-words">
                          <p class="font-bold text-[var(--admin-plum)]">
                            {{ room.name }}
                          </p>
                          <p class="admin-record-secondary font-mono">
                            {{ room.code }}
                            <a
                              :href="`/admin/room-blocks?roomId=${room.id}`"
                              class="block text-xs underline"
                              >{{ tr("ad.blocks.title") }}</a
                            >
                          </p>
                          <a
                            v-if="management?.isOwner && management.ready"
                            :href="`/admin/rooms/${room.id}/edit`"
                            class="block text-xs font-semibold underline"
                            >{{ tr("ad.rooms.edit") }}</a
                          >
                          <span
                            class="rounded bg-[var(--admin-line)] px-2 py-0.5 text-xs text-[var(--admin-muted)]"
                            >{{ room.type ?? tr("ad.rooms.unsetType") }}</span
                          >
                        </div>
                      </div>
                    </td>
                    <td>{{ room.capacity }} {{ tr("ad.rooms.guests") }}</td>
                    <td>
                      {{ room.cleaningMinutes }} {{ tr("ad.rooms.minutes") }}
                    </td>
                    <td>
                      <RoomDetails
                        :room="room"
                        :generated-at="inventory.generatedAt"
                        section="treatments"
                      />
                    </td>
                    <td><RoomStatusBadges :room="room" /></td>
                    <td>
                      <RoomDetails
                        :room="room"
                        :generated-at="inventory.generatedAt"
                        section="blocks"
                      />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <ul class="admin-booking-cards" role="list">
              <li
                v-for="room in filteredRooms"
                :key="room.id"
                :data-room-card="room.id"
                class="space-y-3"
              >
                <div class="space-y-2">
                  <h2 class="break-words font-bold text-[var(--admin-plum)]">
                    {{ room.name }}
                  </h2>
                  <p
                    class="break-words font-mono text-xs text-[var(--admin-muted)]"
                  >
                    {{ room.code }} ·
                    {{ room.type ?? tr("ad.rooms.unsetType") }}
                  </p>
                  <RoomStatusBadges :room="room" />
                  <a
                    :href="`/admin/room-blocks?roomId=${room.id}`"
                    class="block text-sm underline"
                    >{{ tr("ad.blocks.title") }}</a
                  >
                  <a
                    v-if="management?.isOwner && management.ready"
                    :href="`/admin/rooms/${room.id}/edit`"
                    class="block text-sm font-semibold underline"
                    >{{ tr("ad.rooms.edit") }}</a
                  >
                </div>
                <dl class="grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <dt class="text-[var(--admin-muted)]">
                      {{ tr("ad.rooms.capacity") }}
                    </dt>
                    <dd class="font-semibold">
                      {{ room.capacity }} {{ tr("ad.rooms.guests") }}
                    </dd>
                  </div>
                  <div>
                    <dt class="text-[var(--admin-muted)]">
                      {{ tr("ad.rooms.cleaning") }}
                    </dt>
                    <dd class="font-semibold">
                      {{ room.cleaningMinutes }} {{ tr("ad.rooms.minutes") }}
                    </dd>
                  </div>
                </dl>
                <section>
                  <h3 class="mb-1 text-xs font-semibold">
                    {{ tr("ad.rooms.treatments") }}
                  </h3>
                  <RoomDetails
                    :room="room"
                    :generated-at="inventory.generatedAt"
                    section="treatments"
                  />
                </section>
                <section>
                  <h3 class="mb-1 text-xs font-semibold">
                    {{ tr("ad.rooms.blocks") }}
                  </h3>
                  <RoomDetails
                    :room="room"
                    :generated-at="inventory.generatedAt"
                    section="blocks"
                  />
                </section>
              </li>
            </ul>
          </template>
        </template>
      </div>
    </AdminLayout>
  </div>
</template>

<style scoped>
#room-search {
  padding-left: 2.5rem;
}
.admin-rooms-table :is(th, td) {
  vertical-align: middle;
}
.admin-rooms-table td:nth-child(2),
.admin-rooms-table td:nth-child(3),
.admin-rooms-table td:nth-child(4) {
  min-width: 0 !important;
  max-width: none !important;
}
.admin-rooms-table :is(th, td):nth-child(2) {
  width: 7rem;
  white-space: nowrap;
}
.admin-rooms-table :is(th, td):nth-child(3) {
  width: 7.5rem;
  white-space: nowrap;
}
.admin-rooms-table :is(th, td):nth-child(5) {
  width: 8rem;
}
.admin-rooms-table :is(th, td):last-child {
  width: 19rem;
  min-width: 14rem;
  white-space: normal;
}
.admin-rooms-table td {
  overflow-wrap: anywhere;
}
</style>
