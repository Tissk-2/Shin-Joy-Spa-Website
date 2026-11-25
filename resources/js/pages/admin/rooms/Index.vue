<script setup lang="ts">
import { computed, ref } from "vue";
import { usePoll } from "@inertiajs/vue3";
import { DoorClosed, Plus, RefreshCw, Search } from "lucide-vue-next";
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
// One line instead of an empty "compatible treatments" box on every room.
const roomsWithoutTreatments = computed(() =>
  props.inventory.rooms
    .filter((room) => room.isActive && room.compatibleVariants.length === 0)
    .map((room) => room.name),
);
const hasBlocks = (room: AdminRoomInventory["rooms"][number]) =>
  room.currentBlockCount + room.upcomingBlockCount > 0;
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
      <template #actions>
        <a href="/admin/room-blocks" class="admin-button">{{
          tr("ad.blocks.title")
        }}</a>
        <a
          v-if="blockManagement?.canManageBlocks && blockManagement.ready"
          href="/admin/room-blocks/create"
          class="admin-button"
          >{{ tr("ad.blocks.create") }}</a
        >
        <a
          v-if="management?.isOwner && management.ready"
          href="/admin/rooms/create"
          class="admin-button admin-button--primary"
          ><Plus aria-hidden="true" />{{ tr("ad.rooms.create") }}</a
        >
      </template>
      <div class="admin-content">
        <div class="admin-notes">
          <p v-if="!management?.ready">{{ tr("ad.rooms.readOnly") }}</p>
          <p
            v-if="management?.isOwner && !management.ready"
            class="admin-callout admin-callout--warning"
            role="status"
          >
            {{ tr("ad.rooms.writeUnavailable") }}
          </p>
          <p>
            {{
              tr(
                inventory.schedulingEnabled
                  ? "ad.rooms.availabilityNote"
                  : "ad.rooms.schedulingOffNote",
              )
            }}
          </p>
          <p
            v-if="roomsWithoutTreatments.length > 0"
            :class="{
              'admin-callout admin-callout--warning':
                inventory.schedulingEnabled,
            }"
            data-rooms-without-treatments
          >
            {{
              tr("ad.rooms.noTreatmentsSummary", {
                rooms: roomsWithoutTreatments.join(", "),
              })
            }}
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
          <p
            v-if="inventory.rooms.length === 0"
            class="admin-empty"
            role="status"
          >
            {{ tr("ad.rooms.emptyInventory") }}
          </p>
          <template v-else>
            <div class="admin-observed">
              <p>
                {{ tr("ad.rooms.observed") }}
                <time :datetime="inventory.generatedAt">{{ observation }}</time>
              </p>
              <a href="/admin/rooms" class="admin-button admin-button--compact"
                ><RefreshCw aria-hidden="true" />{{ tr("ad.rooms.refresh") }}</a
              >
            </div>
            <RoomMap
              :rooms="inventory.rooms"
              :generated-at="inventory.generatedAt"
              :scheduling-enabled="inventory.schedulingEnabled"
              :can-edit="!!(management?.isOwner && management.ready)"
            />
            <div class="admin-filter-bar admin-filter-bar--rooms">
              <div class="admin-filter-search">
                <label for="room-search">{{ tr("ad.rooms.search") }}</label>
                <div class="admin-search-field">
                  <Search aria-hidden="true" />
                  <input
                    id="room-search"
                    v-model="searchQuery"
                    type="search"
                    class="admin-control"
                    :placeholder="tr('ad.rooms.searchPlaceholder')"
                  />
                </div>
              </div>
              <div>
                <label for="room-status">{{ tr("ad.rooms.status") }}</label>
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
              <div>
                <label for="room-type">{{ tr("ad.rooms.type") }}</label>
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
            <p
              v-if="filteredRooms.length === 0"
              class="admin-empty"
              role="status"
            >
              {{ tr("ad.rooms.empty") }}
            </p>
            <div v-else class="admin-booking-table-wrap">
              <table class="admin-booking-table admin-rooms-table">
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
                  >
                    <td>
                      <div class="admin-room-cell">
                        <span class="admin-room-cell__icon"
                          ><DoorClosed aria-hidden="true"
                        /></span>
                        <div class="admin-room-cell__text">
                          <p class="admin-row-link__name">{{ room.name }}</p>
                          <p class="admin-room-cell__meta">
                            <span class="admin-code">{{ room.code }}</span>
                            <span class="admin-room-cell__type">{{
                              room.type ?? tr("ad.rooms.unsetType")
                            }}</span>
                          </p>
                          <p class="admin-room-cell__links">
                            <a :href="`/admin/room-blocks?roomId=${room.id}`">{{
                              tr("ad.blocks.title")
                            }}</a>
                            <a
                              v-if="management?.isOwner && management.ready"
                              :href="`/admin/rooms/${room.id}/edit`"
                              >{{ tr("ad.rooms.edit") }}</a
                            >
                          </p>
                        </div>
                      </div>
                    </td>
                    <td class="admin-nowrap">
                      {{ room.capacity }} {{ tr("ad.rooms.guests") }}
                    </td>
                    <td class="admin-nowrap">
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
                class="admin-room-card"
              >
                <div class="admin-room-card__top">
                  <div class="admin-room-cell__text">
                    <h2 class="admin-row-link__name">{{ room.name }}</h2>
                    <p class="admin-room-cell__meta">
                      <span class="admin-code">{{ room.code }}</span>
                      <span class="admin-room-cell__type">{{
                        room.type ?? tr("ad.rooms.unsetType")
                      }}</span>
                    </p>
                  </div>
                  <RoomStatusBadges :room="room" />
                </div>
                <p class="admin-booking-list__meta">
                  {{ room.capacity }} {{ tr("ad.rooms.guests") }} ·
                  {{ tr("ad.rooms.cleaning") }} {{ room.cleaningMinutes }}
                  {{ tr("ad.rooms.minutes") }}
                </p>
                <section v-if="room.compatibleVariants.length > 0">
                  <h3 class="admin-room-card__label">
                    {{ tr("ad.rooms.treatments") }}
                  </h3>
                  <RoomDetails
                    :room="room"
                    :generated-at="inventory.generatedAt"
                    section="treatments"
                  />
                </section>
                <section v-if="hasBlocks(room)">
                  <h3 class="admin-room-card__label">
                    {{ tr("ad.rooms.blocks") }}
                  </h3>
                  <RoomDetails
                    :room="room"
                    :generated-at="inventory.generatedAt"
                    section="blocks"
                  />
                </section>
                <p class="admin-room-cell__links">
                  <a :href="`/admin/room-blocks?roomId=${room.id}`">{{
                    tr("ad.blocks.title")
                  }}</a>
                  <a
                    v-if="management?.isOwner && management.ready"
                    :href="`/admin/rooms/${room.id}/edit`"
                    >{{ tr("ad.rooms.edit") }}</a
                  >
                </p>
              </li>
            </ul>
            <p v-if="inventory.rooms.some(hasBlocks)" class="admin-loading">
              {{ tr("ad.rooms.blockWindow") }}
            </p>
          </template>
        </template>
      </div>
    </AdminLayout>
  </div>
</template>
