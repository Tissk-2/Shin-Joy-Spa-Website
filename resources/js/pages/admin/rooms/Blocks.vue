<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import BlockHistory from "@/components/admin/rooms/BlockHistory.vue";
import { useLanguage } from "@/composables/useLanguage";
import type { RoomBlockSchedule } from "@/types";
defineProps<{ schedule: RoomBlockSchedule }>();
const { tr, language } = useLanguage();
const page = usePage();
const flash = computed(() => (page.props.flash as { status?: string })?.status);
const display = (value: string) =>
  new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    timeZone: "Asia/Jakarta",
    dateStyle: "medium",
    timeStyle: "short",
    hourCycle: "h23",
  }).format(new Date(value)) + " WIB";
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.blocks.title')"
      :subtitle="tr('ad.blocks.subtitle')"
    >
      <div class="admin-content space-y-5">
        <div class="flex flex-wrap gap-3">
          <a href="/admin/rooms" class="admin-button">{{
            tr("ad.rooms.back")
          }}</a>
          <a
            v-if="
              schedule.blockManagement.canManageBlocks &&
              schedule.blockManagement.ready
            "
            href="/admin/room-blocks/create"
            class="admin-button admin-button--primary"
            >{{ tr("ad.blocks.create") }}</a
          >
        </div>
        <p v-if="flash" role="status" class="admin-filter-panel">{{ flash }}</p>
        <div class="admin-filter-panel space-y-3">
          <nav class="flex flex-wrap gap-3" :aria-label="tr('ad.blocks.views')">
            <a
              v-for="view in ['active', 'history'] as const"
              :key="view"
              :href="`/admin/room-blocks?${schedule.roomId ? `roomId=${schedule.roomId}&` : ''}view=${view}`"
              :aria-current="
                (schedule.view ?? 'active') === view ? 'page' : undefined
              "
              class="admin-button"
              >{{ tr(`ad.blocks.view_${view}`) }}</a
            >
          </nav>
          <p>{{ tr("ad.blocks.staffOnly") }}</p>
          <p class="text-sm text-[var(--admin-muted)]">
            {{ tr("ad.blocks.limits") }}
          </p>
          <form
            action="/admin/room-blocks"
            method="get"
            class="flex flex-wrap items-end gap-3"
          >
            <input
              type="hidden"
              name="view"
              :value="schedule.view ?? 'active'"
            />
            <div class="min-w-0 flex-1">
              <label
                for="room-filter"
                class="mb-1 block text-sm font-semibold"
                >{{ tr("ad.blocks.room") }}</label
              >
              <select
                id="room-filter"
                name="roomId"
                :value="schedule.roomId ?? ''"
                class="admin-control"
                :disabled="!schedule.rooms.length"
              >
                <option value="" disabled>
                  {{ tr("ad.blocks.selectRoom") }}
                </option>
                <option
                  v-for="room in schedule.rooms"
                  :key="room.id"
                  :value="room.id"
                >
                  {{ room.code }} · {{ room.name }}
                </option>
              </select>
            </div>
            <button
              class="admin-button"
              type="submit"
              :disabled="!schedule.rooms.length"
            >
              {{ tr("ad.blocks.filter") }}
            </button>
            <a
              :href="`/admin/room-blocks?view=${schedule.view ?? 'active'}`"
              class="admin-button"
              >{{ tr("ad.blocks.allRooms") }}</a
            >
          </form>
        </div>
        <p
          v-if="schedule.state === 'unavailable'"
          class="admin-empty"
          role="status"
        >
          {{ tr("ad.blocks.unavailable") }}
        </p>
        <template v-else>
          <p v-if="!schedule.blocks.length" class="admin-empty">
            {{ tr("ad.blocks.empty") }}
          </p>
          <ol class="space-y-3">
            <li
              v-for="block in schedule.blocks"
              :key="block.id"
              class="admin-filter-panel block min-w-0 space-y-3 break-words"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-bold">
                  {{ block.roomCode }} · #{{ block.id }}
                </h2>
                <span>{{ tr(`ad.blocks.${block.state}`) }}</span>
              </div>
              <BlockHistory v-if="schedule.view === 'history'" :block="block" />
              <p v-else>
                <time :datetime="block.startsAt">{{
                  display(block.startsAt)
                }}</time>
                →
                <time :datetime="block.endsAt">{{
                  display(block.endsAt)
                }}</time>
              </p>
              <p v-if="schedule.view !== 'history'" class="whitespace-pre-wrap">
                {{ block.reason }}
              </p>
              <a
                v-if="
                  schedule.blockManagement.canManageBlocks &&
                  schedule.blockManagement.ready
                "
                :href="`/admin/room-blocks/${block.id}/edit`"
                class="font-semibold text-[var(--admin-plum)] underline"
                >{{
                  tr(
                    ["future", "active"].includes(block.state)
                      ? "ad.blocks.edit"
                      : "ad.blocks.details",
                  )
                }}</a
              >
            </li>
          </ol>
          <nav
            class="flex flex-wrap items-center gap-4"
            :aria-label="tr('ad.blocks.pagination')"
          >
            <a
              v-if="schedule.pagination.previous"
              :href="schedule.pagination.previous"
              class="admin-button"
              >{{ tr("ad.blocks.previous") }}</a
            >
            <p>
              {{ schedule.pagination.page }} /
              {{ schedule.pagination.lastPage }} ·
              {{ schedule.pagination.total }}
            </p>
            <a
              v-if="schedule.pagination.next"
              :href="schedule.pagination.next"
              class="admin-button"
              >{{ tr("ad.blocks.next") }}</a
            >
          </nav>
        </template>
      </div>
    </AdminLayout>
  </div>
</template>
