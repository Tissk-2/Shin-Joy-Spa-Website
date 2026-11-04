<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import BlockHistory from "@/components/admin/rooms/BlockHistory.vue";
import { useLanguage } from "@/composables/useLanguage";
import type { RoomBlockAccess, RoomBlockData, RoomBlockRoom } from "@/types";
const props = defineProps<{
  blockManagement: RoomBlockAccess;
  block: RoomBlockData | null;
  rooms: readonly RoomBlockRoom[];
}>();
const { tr } = useLanguage();
const page = usePage();
const flash = computed(() => (page.props.flash as { status?: string })?.status);
const valuesFor = (b: RoomBlockData | null) => ({
  roomId: b ? String(b.roomId) : "",
  startsAt: b?.startsAtInput ?? "",
  endsAt: b?.endsAtInput ?? "",
  reason: b?.reason ?? "",
});
const values = ref(valuesFor(props.block));
const blockId = ref(props.block?.id);
const version = ref(props.block?.version);
const scope = ref("room");
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const currentBlock = ref(props.block);
watch(
  () => props.block,
  (block) => {
    currentBlock.value = block;
  },
);
const lockedStart = computed(() => currentBlock.value?.state === "active");
const ended = computed(
  () =>
    !!currentBlock.value &&
    !["future", "active"].includes(currentBlock.value.state),
);
const terminalIntent = ref<"cancel_future" | "end_now" | null>(null);
const terminalReason = ref("");
const terminalInput = ref<HTMLTextAreaElement>();
const canTerminate = computed(
  () =>
    !!currentBlock.value &&
    !ended.value &&
    props.blockManagement.canTerminateBlocks &&
    props.blockManagement.terminationReady,
);
async function confirmTermination() {
  if (!canTerminate.value || processing.value) return;
  terminalIntent.value = lockedStart.value ? "end_now" : "cancel_future";
  await nextTick();
  terminalInput.value?.focus();
}
function acceptSaved(response: { props: Record<string, unknown> }) {
  const saved = response.props.block as RoomBlockData | null;
  if (!saved) return;
  currentBlock.value = saved;
  values.value = valuesFor(saved);
  blockId.value = saved.id;
  version.value = saved.version;
}
function terminate() {
  if (!canTerminate.value || !terminalIntent.value || processing.value) return;
  processing.value = true;
  errors.value = {};
  router.post(
    `/admin/room-blocks/${blockId.value}/terminate`,
    {
      intent: terminalIntent.value,
      version: version.value,
      reason: terminalReason.value,
    },
    {
      preserveScroll: true,
      onError: (response) => {
        errors.value = response;
      },
      onSuccess: (response) => {
        acceptSaved(response);
        terminalIntent.value = null;
      },
      onFinish: () => {
        processing.value = false;
      },
    },
  );
}
function submit() {
  if (
    processing.value ||
    ended.value ||
    !props.blockManagement.canManageBlocks ||
    !props.blockManagement.ready
  )
    return;
  processing.value = true;
  errors.value = {};
  const { roomId, ...period } = values.value;
  const data = {
    ...period,
    ...(blockId.value
      ? { roomId, version: version.value }
      : { scope: scope.value, ...(scope.value === "room" ? { roomId } : {}) }),
  };
  const options = {
    preserveScroll: true,
    onError: (response: Record<string, string>) => {
      errors.value = response;
    },
    onSuccess: acceptSaved,
    onFinish: () => {
      processing.value = false;
    },
  };
  if (blockId.value)
    router.patch(`/admin/room-blocks/${blockId.value}`, data, options);
  else router.post("/admin/room-blocks", data, options);
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="
        tr(
          ended
            ? 'ad.blocks.detailsTitle'
            : blockId
              ? 'ad.blocks.edit'
              : 'ad.blocks.create',
        )
      "
      :subtitle="tr('ad.blocks.subtitle')"
    >
      <div class="admin-content max-w-4xl space-y-5">
        <a href="/admin/room-blocks" class="admin-button">{{
          tr("ad.blocks.back")
        }}</a>
        <p v-if="flash" role="status" class="admin-filter-panel">{{ flash }}</p>
        <p
          v-if="!blockManagement.ready || !blockManagement.canManageBlocks"
          role="status"
          class="admin-empty"
        >
          {{ tr("ad.blocks.unavailable") }}
        </p>
        <template v-else>
          <div class="admin-filter-panel block space-y-2 text-sm">
            <p>{{ tr("ad.blocks.staffOnly") }}</p>
            <p v-if="!blockId && scope === 'all'">
              {{ tr("ad.blocks.allHelp") }}
            </p>
            <p>{{ tr("ad.blocks.limits") }}</p>
          </div>
          <p v-if="ended" role="status" class="admin-empty">
            {{ tr("ad.blocks.endedHelp") }}
          </p>
          <BlockHistory v-if="ended && currentBlock" :block="currentBlock" />
          <div
            v-if="Object.keys(errors).length"
            role="alert"
            class="rounded border border-red-700 p-4 text-red-800"
          >
            <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
            <a
              v-if="errors.version || errors.intent || errors.block"
              :href="`/admin/room-blocks/${blockId}/edit`"
              class="font-semibold underline"
              >{{ tr("ad.blocks.reload") }}</a
            >
          </div>
          <form
            v-if="!ended"
            :aria-busy="processing"
            class="space-y-5"
            @submit.prevent="submit"
          >
            <fieldset
              :disabled="processing"
              class="admin-filter-panel block min-w-0 space-y-5"
            >
              <legend class="sr-only">{{ tr("ad.blocks.period") }}</legend>
              <div v-if="!blockId">
                <label for="scope" class="mb-1 block font-semibold">{{
                  tr("ad.blocks.scope")
                }}</label>
                <select id="scope" v-model="scope" class="admin-control">
                  <option value="room">{{ tr("ad.blocks.single") }}</option>
                  <option value="all">{{ tr("ad.blocks.allExisting") }}</option>
                </select>
              </div>
              <div v-if="blockId || scope === 'room'">
                <label for="roomId" class="mb-1 block font-semibold">{{
                  tr("ad.blocks.room")
                }}</label>
                <select
                  id="roomId"
                  v-model="values.roomId"
                  required
                  :disabled="lockedStart"
                  class="admin-control"
                  :aria-invalid="!!errors.roomId"
                  aria-describedby="roomId-error"
                >
                  <option value="" disabled>
                    {{ tr("ad.blocks.selectRoom") }}
                  </option>
                  <option
                    v-for="room in rooms"
                    :key="room.id"
                    :value="String(room.id)"
                  >
                    {{ room.code }} · {{ room.name
                    }}{{
                      room.isActive ? "" : ` · ${tr("ad.blocks.inactive")}`
                    }}
                  </option>
                </select>
                <p
                  v-if="errors.roomId"
                  id="roomId-error"
                  class="mt-1 text-sm text-red-800"
                >
                  {{ errors.roomId }}
                </p>
              </div>
              <p v-if="lockedStart" class="text-sm">
                {{ tr("ad.blocks.activeHelp") }}
              </p>
              <div class="grid gap-5 sm:grid-cols-2">
                <div
                  v-for="field in ['startsAt', 'endsAt'] as const"
                  :key="field"
                  class="min-w-0"
                >
                  <label :for="field" class="mb-1 block font-semibold"
                    >{{ tr(`ad.blocks.${field}`) }} · WIB</label
                  >
                  <input
                    :id="field"
                    v-model="values[field]"
                    type="datetime-local"
                    required
                    step="60"
                    :disabled="field === 'startsAt' && lockedStart"
                    class="admin-control min-w-0"
                    :aria-invalid="!!errors[field]"
                    :aria-describedby="`${field}-error`"
                  />
                  <p
                    v-if="errors[field]"
                    :id="`${field}-error`"
                    class="mt-1 text-sm text-red-800"
                  >
                    {{ errors[field] }}
                  </p>
                </div>
              </div>
              <div>
                <label for="reason" class="mb-1 block font-semibold">{{
                  tr("ad.blocks.reason")
                }}</label>
                <textarea
                  id="reason"
                  v-model="values.reason"
                  required
                  maxlength="1000"
                  rows="4"
                  class="admin-control"
                  :aria-invalid="!!errors.reason"
                  aria-describedby="reason-error"
                />
                <p
                  v-if="errors.reason"
                  id="reason-error"
                  class="mt-1 text-sm text-red-800"
                >
                  {{ errors.reason }}
                </p>
              </div>
            </fieldset>
            <div class="flex flex-wrap gap-3 pb-6">
              <button
                type="submit"
                class="admin-button admin-button--primary"
                :disabled="processing || !rooms.length"
              >
                {{
                  tr(processing ? "ad.rooms.saving" : "ad.blocks.save")
                }}</button
              ><a href="/admin/room-blocks" class="admin-button">{{
                tr("ad.rooms.cancel")
              }}</a>
            </div>
          </form>
          <button
            v-if="canTerminate"
            type="button"
            :data-terminate="lockedStart ? 'end_now' : 'cancel_future'"
            class="admin-button"
            :disabled="processing"
            @click="confirmTermination"
          >
            {{
              tr(lockedStart ? "ad.blocks.end_now" : "ad.blocks.cancel_future")
            }}
          </button>
          <section
            v-if="terminalIntent && canTerminate && currentBlock"
            role="region"
            aria-labelledby="termination-title"
            class="admin-filter-panel block space-y-4"
          >
            <h2 id="termination-title" class="font-bold">
              {{ tr(`ad.blocks.${terminalIntent}`) }}
            </h2>
            <p>
              {{ rooms.find((room) => room.id === currentBlock?.roomId)?.name }}
              · #{{ currentBlock.id }}
            </p>
            <BlockHistory :block="currentBlock" />
            <p>{{ tr("ad.blocks.terminationHelp") }}</p>
            <form
              data-terminal-form
              :aria-busy="processing"
              class="space-y-4"
              @submit.prevent="terminate"
            >
              <label for="termination-reason" class="block font-semibold">{{
                tr("ad.blocks.terminationReason")
              }}</label>
              <textarea
                id="termination-reason"
                ref="terminalInput"
                v-model="terminalReason"
                required
                maxlength="1000"
                rows="3"
                class="admin-control"
                :disabled="processing"
                :aria-invalid="!!errors.reason"
              />
              <div class="flex flex-wrap gap-3">
                <button
                  type="submit"
                  class="admin-button admin-button--primary"
                  :disabled="processing || !terminalReason.trim()"
                >
                  {{ tr("ad.blocks.confirmTermination") }}</button
                ><button
                  type="button"
                  class="admin-button"
                  :disabled="processing"
                  @click="terminalIntent = null"
                >
                  {{ tr("ad.rooms.cancel") }}
                </button>
              </div>
            </form>
          </section>
        </template>
      </div>
    </AdminLayout>
  </div>
</template>
