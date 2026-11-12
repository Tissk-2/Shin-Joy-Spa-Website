<script setup lang="ts">
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import type { AdminRoom } from "@/types";

const props = defineProps<{
  rooms: readonly AdminRoom[];
  generatedAt: string;
  schedulingEnabled: boolean;
  canEdit: boolean;
}>();
const { language, tr } = useLanguage();

type MapState = "guest" | "cleaning" | "off" | "blocked" | "free";
const states: readonly MapState[] = [
  "guest",
  "cleaning",
  "free",
  "blocked",
  "off",
];
const stateOf = (room: AdminRoom): MapState =>
  room.occupancy?.state ??
  (!room.isActive ? "off" : room.currentBlockCount > 0 ? "blocked" : "free");
const label = (state: MapState) =>
  ({
    guest: tr("ad.rooms.mapGuest"),
    cleaning: tr("ad.rooms.mapCleaning"),
    // Without scheduling no room is reserved, so an enabled room is only known to be open.
    free: tr(props.schedulingEnabled ? "ad.rooms.mapFree" : "ad.rooms.mapOpen"),
    blocked: tr("ad.rooms.blocked"),
    off: tr("ad.rooms.disabled"),
  })[state];
const counts = computed(() =>
  Object.fromEntries(
    states.map((state) => [
      state,
      props.rooms.filter((room) => stateOf(room) === state).length,
    ]),
  ),
);
// Beds drawn from above: a disabled room is shown empty, a room for two holds two beds.
const beds = (room: AdminRoom) =>
  stateOf(room) === "off" ? [] : room.capacity >= 2 ? [0, 1] : [0];
const occupied = (room: AdminRoom, bed: number) =>
  room.occupancy?.state === "guest" && bed < room.occupancy.guests;
// Counted from the observation, not the viewer's clock, so the map agrees with itself
// until the next refresh. A room still marked in use always has at least a minute left.
const minutesUntil = (value: string) =>
  Math.max(
    1,
    Math.ceil((Date.parse(value) - Date.parse(props.generatedAt)) / 60_000),
  );
const timeLeft = (value: string) => {
  const minutes = minutesUntil(value);
  if (minutes < 60) return tr("ad.rooms.mapLeftMinutes", { minutes });
  const hours = Math.floor(minutes / 60);
  return minutes % 60
    ? tr("ad.rooms.mapLeftHoursMinutes", { hours, minutes: minutes % 60 })
    : tr("ad.rooms.mapLeftHours", { hours });
};
// The plan: rooms 1–4 above the corridor, 5–8 below it, reception at its end.
// Phones turn the real plan a quarter clockwise, never mirror it: reception drops to the
// bottom, 1–4 run down the right of the corridor and 5–8 down the left, as in the building.
// Tab order follows the room numbers printed on each room.
// ponytail: places two rows of four; a ninth room onwards falls back to automatic placement.
const place = (index: number) =>
  index < 8
    ? {
        "--plan-row": index < 4 ? 1 : 3,
        "--plan-column": (index % 4) + 1,
        "--turned-row": (index % 4) + 1,
        "--turned-column": index < 4 ? 3 : 1,
      }
    : {};
// With scheduling off nothing records guests, so the key shows no count for them.
const keyStates = computed(() =>
  props.schedulingEnabled
    ? states
    : states.filter(
        (state) =>
          (state !== "guest" && state !== "cleaning") || counts.value[state],
      ),
);
// Only owners may edit rooms; other staff manage a room through its blocks.
const href = (room: AdminRoom) =>
  props.canEdit
    ? `/admin/rooms/${room.id}/edit`
    : `/admin/room-blocks?roomId=${room.id}`;
</script>

<template>
  <section class="admin-filter-panel space-y-4" aria-labelledby="room-map">
    <h2 id="room-map" class="room-map__title">{{ tr("ad.rooms.map") }}</h2>
    <div class="room-map" data-room-map>
      <template v-for="(room, index) in rooms" :key="room.id">
        <div
          v-if="index === 4"
          class="room-map__corridor"
          data-room-map-corridor
          aria-hidden="true"
        >
          <span>{{ tr("ad.rooms.mapCorridor") }}</span>
        </div>
        <a
          :href="href(room)"
          class="room-map__room"
          :class="[
            `room-map--${stateOf(room)}`,
            index < 4 ? 'room-map__room--upper' : 'room-map__room--lower',
          ]"
          :style="place(index)"
          :data-room-map-id="room.id"
          :data-room-state="stateOf(room)"
        >
          <svg
            class="room-map__door"
            viewBox="0 0 30 30"
            aria-hidden="true"
            focusable="false"
          >
            <path class="room-map__swing" d="M29 1 A28 28 0 0 1 1 29" />
            <line class="room-map__leaf" x1="1" y1="1" x2="29" y2="1" />
          </svg>
          <span class="room-map__name">{{ room.name }}</span>
          <span class="room-map__code">{{ room.code }}</span>
          <span v-if="beds(room).length" class="room-map__beds">
            <svg
              v-for="bed in beds(room)"
              :key="bed"
              class="room-map__bed"
              viewBox="0 0 28 44"
              aria-hidden="true"
              focusable="false"
            >
              <rect
                class="room-map__frame"
                x="1"
                y="1"
                width="26"
                height="42"
                rx="3"
              />
              <template v-if="stateOf(room) !== 'cleaning'">
                <rect
                  class="room-map__pillow"
                  x="5"
                  y="4"
                  width="18"
                  height="7"
                  rx="3"
                />
                <template v-if="occupied(room, bed)">
                  <rect
                    class="room-map__blanket"
                    x="3"
                    y="15"
                    width="22"
                    height="26"
                    rx="2"
                  />
                  <circle class="room-map__guest" cx="14" cy="9.5" r="4.5" />
                </template>
                <path v-else class="room-map__fold" d="M3 18 H25" />
              </template>
            </svg>
          </span>
          <span class="room-map__status">
            <span class="room-map__state">{{ label(stateOf(room)) }}</span>
            <template v-if="room.occupancy?.state === 'guest'">
              <span>{{ room.occupancy.customerName }}</span>
              <span>{{
                language === "id"
                  ? room.occupancy.packageNameId
                  : room.occupancy.packageNameEn
              }}</span>
              <span class="room-map__chips">
                <span class="room-map__chip">
                  <svg viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                    <circle cx="6" cy="6" r="5" />
                    <path d="M6 3.2 V6 L8 7.3" />
                  </svg>
                  {{ timeLeft(room.occupancy.treatmentEndsAt) }}
                </span>
                <span class="room-map__chip">
                  <svg viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                    <circle cx="6" cy="3.8" r="2.3" />
                    <path d="M1.8 11.2 C2.4 7.6 9.6 7.6 10.2 11.2" />
                  </svg>
                  {{
                    tr("ad.rooms.mapGuestsOf", {
                      guests: room.occupancy.guests,
                      capacity: room.capacity,
                    })
                  }}
                </span>
              </span>
            </template>
            <span v-else-if="room.occupancy" class="room-map__chips">
              <span class="room-map__chip">
                <svg viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                  <circle cx="6" cy="6" r="5" />
                  <path d="M6 3.2 V6 L8 7.3" />
                </svg>
                {{
                  tr("ad.rooms.mapReadyIn", {
                    minutes: minutesUntil(room.occupancy.endsAt),
                  })
                }}
              </span>
            </span>
            <span v-else-if="room.currentBlockCount > 0 && room.blocks[0]">{{
              room.blocks[0].reason
            }}</span>
          </span>
        </a>
      </template>
      <div class="room-map__reception" aria-hidden="true">
        <svg
          class="room-map__desk"
          viewBox="0 0 64 44"
          aria-hidden="true"
          focusable="false"
        >
          <path d="M4 4 H60 V40 H44 V18 H4 Z" />
          <circle cx="30" cy="31" r="6" />
        </svg>
        <span>{{ tr("ad.rooms.mapReception") }}</span>
      </div>
    </div>
    <ul class="room-map__key" role="list">
      <li v-for="state in keyStates" :key="state" :data-room-map-key="state">
        <span class="room-map__swatch" :class="`room-map--${state}`" />
        <span>{{ label(state) }}</span>
        <span class="room-map__count">{{ counts[state] }}</span>
      </li>
    </ul>
    <p v-if="!schedulingEnabled" class="text-sm text-[var(--admin-muted)]">
      {{ tr("ad.rooms.mapSchedulingOff") }}
    </p>
  </section>
</template>

<style scoped>
/*
 * The map is drawn as an architect's plan: the grid's background is the wall ink,
 * its gaps are the walls, and each cell is a floor. A room's floor shows its state:
 * lamp-lit with a guest, mopped while cleaned, hatched while blocked.
 */
.room-map {
  --wall: 2px;
  --door: 1.75rem;
  --ink: var(--admin-plum);
  --floor: var(--admin-ivory);
  display: grid;
  grid-template-columns: minmax(0, 1fr) 4.5rem minmax(0, 1fr);
  grid-template-rows: repeat(4, auto) auto;
  gap: var(--wall);
  padding: var(--wall);
  background: var(--ink);
}
.room-map__title {
  font-family: var(--font-display);
  font-size: 1.375rem;
  line-height: 1.2;
}
.room-map__room {
  position: relative;
  z-index: 1;
  grid-row: var(--turned-row);
  grid-column: var(--turned-column);
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-height: 11rem;
  padding: 0.75rem;
  font-size: 0.75rem;
  line-height: 1.35;
  overflow-wrap: break-word;
  hyphens: auto;
  text-decoration: none;
}
/* The narrowest phones give the rooms the room: a slimmer corridor and smaller doors. */
@media (max-width: 399px) {
  .room-map {
    --door: 1.25rem;
    grid-template-columns: minmax(0, 1fr) 2.5rem minmax(0, 1fr);
  }
  .room-map__room {
    padding: 0.5rem;
  }
}
.room-map__room:focus-visible {
  outline-offset: -6px;
}
/* Pressed: the room's own ink rings its floor, distinct from hover's opening door. */
.room-map__room:active {
  box-shadow: inset 0 0 0 3px currentColor;
}
/* The doorway: a gap cut into the corridor wall, its door swinging into the corridor. */
.room-map__room::after {
  content: "";
  position: absolute;
  background: var(--floor);
}
.room-map__door {
  position: absolute;
  width: var(--door);
  height: var(--door);
  overflow: visible;
  fill: none;
  stroke: var(--ink);
}
.room-map__leaf {
  stroke-width: 2.5;
  transform-box: fill-box;
  transform-origin: 0 50%;
  transition: transform 0.35s cubic-bezier(0.3, 0.7, 0.2, 1);
}
.room-map__swing {
  stroke-width: 1;
  stroke-dasharray: 2 3;
  opacity: 0.55;
  transition: opacity 0.35s;
}
.room-map__room:is(:hover, :focus-visible) .room-map__leaf {
  transform: rotate(90deg);
}
.room-map__room:is(:hover, :focus-visible) .room-map__swing {
  opacity: 1;
}
/* Turned a quarter clockwise, each desktop door turns with it: the upper rooms' doors move
   to their left wall near the top, the lower rooms' to their right wall near the bottom. */
.room-map__room--upper::after {
  right: 100%;
  top: 0.75rem;
  width: var(--wall);
  height: var(--door);
}
.room-map__room--upper .room-map__door {
  right: calc(100% + var(--wall));
  top: 0.75rem;
  transform: rotate(90deg);
}
.room-map__room--lower::after {
  left: 100%;
  bottom: 0.75rem;
  width: var(--wall);
  height: var(--door);
}
.room-map__room--lower .room-map__door {
  left: calc(100% + var(--wall));
  bottom: 0.75rem;
  transform: rotate(-90deg);
}
.room-map__name {
  font-family: var(--font-display);
  font-size: 1rem;
  line-height: 1.15;
}
.room-map__code {
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.02em;
}
.room-map__beds {
  display: flex;
  justify-content: center;
  gap: 0.375rem;
  padding: 0.625rem 0;
}
.room-map__bed {
  height: 2.75rem;
  width: auto;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.25;
}
.room-map__frame {
  fill: var(--admin-ivory);
}
.room-map--cleaning .room-map__frame {
  fill: none;
  stroke-dasharray: 3 2.5;
}
.room-map__fold {
  stroke-width: 1;
}
.room-map__blanket,
.room-map__guest {
  fill: currentColor;
  stroke: none;
}
.room-map__blanket {
  opacity: 0.28;
}
.room-map__status {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  margin-top: auto;
}
.room-map__state {
  font-weight: 700;
}
/* Small labels for what changes minute to minute, as in the reference table maps. */
.room-map__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  margin-top: 0.375rem;
}
.room-map__chip {
  display: inline-flex;
  align-items: flex-start;
  gap: 0.3125rem;
  padding: 0.125rem 0.4375rem;
  border-radius: 4px;
  background: var(--admin-ivory);
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.room-map__chip svg {
  flex: none;
  width: 0.75rem;
  height: 0.75rem;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.3;
  stroke-linecap: round;
  /* Sits on the first line when a narrow room wraps the chip. */
  margin-top: 0.125rem;
}
.room-map__corridor {
  position: relative;
  grid-column: 2;
  grid-row: 1 / span 4;
  display: grid;
  place-items: center;
  background: var(--floor);
  color: var(--admin-muted);
  font-family: var(--font-display);
  font-size: 0.75rem;
  letter-spacing: 0.08em;
}
.room-map__corridor span {
  writing-mode: vertical-rl;
}
/* The corridor runs straight on into reception, so no wall stands between them. */
.room-map__corridor::after {
  content: "";
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  height: var(--wall);
  background: var(--floor);
}
.room-map__reception {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  min-height: 4.5rem;
  background: var(--floor);
  color: var(--admin-muted);
  font-family: var(--font-display);
  font-size: 0.875rem;
}
.room-map__desk {
  width: 2.75rem;
  fill: none;
  stroke: var(--ink);
  stroke-width: 2;
}
/* Floors are each state's tint thinned with the paper colour, for a light, airy plan. */
.room-map--guest {
  /* The lamp is on: a warm pool of light over the room. */
  background:
    radial-gradient(circle at 50% 40%, var(--admin-lamp), transparent 65%),
    color-mix(in srgb, var(--admin-selected) 70%, var(--admin-ivory));
  color: var(--admin-plum);
}
.room-map--cleaning {
  /* Fresh mop streaks across the floor. */
  background:
    repeating-linear-gradient(
      135deg,
      transparent 0 7px,
      color-mix(in srgb, var(--admin-info) 16%, transparent) 7px 8px
    ),
    color-mix(in srgb, var(--admin-info-bg) 70%, var(--admin-ivory));
  color: var(--admin-info);
}
.room-map--free {
  background: color-mix(
    in srgb,
    var(--admin-success-bg) 70%,
    var(--admin-ivory)
  );
  color: var(--admin-success);
}
.room-map--blocked {
  /* Taped off. */
  background:
    repeating-linear-gradient(
      -45deg,
      color-mix(in srgb, var(--admin-warning) 12%, transparent) 0 6px,
      transparent 6px 14px
    ),
    color-mix(in srgb, var(--admin-warning-bg) 70%, var(--admin-ivory));
  color: var(--admin-warning);
}
.room-map--off {
  background: var(--admin-ivory);
  color: var(--admin-muted);
  outline: 1px dashed var(--admin-input);
  outline-offset: -8px;
}
.room-map__key {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem 1.25rem;
  font-size: 0.8125rem;
}
.room-map__key li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.room-map__swatch {
  width: 1rem;
  height: 1rem;
  border: 2px solid var(--admin-plum);
  outline: none;
}
/* Key swatches: a pool of light is too faint at this size, so a lit room shows its colour. */
.room-map__swatch.room-map--guest {
  background: var(--admin-selected);
}
.room-map__swatch.room-map--off {
  border-style: dashed;
}
.room-map__count {
  font-family: var(--font-display);
  font-size: 1rem;
  font-variant-numeric: tabular-nums;
  color: var(--admin-plum);
}
@media (min-width: 768px) {
  .room-map {
    --wall: 3px;
    --door: 1.75rem;
    grid-template-columns: repeat(4, minmax(0, 1fr)) 7.5rem;
    grid-template-rows: auto 4.5rem auto;
  }
  .room-map__room {
    grid-row: var(--plan-row);
    grid-column: var(--plan-column);
    min-height: 12.5rem;
    padding: 1rem;
  }
  .room-map__name {
    font-size: 1.125rem;
  }
  .room-map__bed {
    height: 3.25rem;
  }
  .room-map__room--upper::after {
    right: auto;
    left: 1rem;
    top: 100%;
    width: var(--door);
    height: var(--wall);
  }
  .room-map__room--upper .room-map__door {
    right: auto;
    left: 1rem;
    top: calc(100% + var(--wall));
    transform: none;
  }
  .room-map__room--lower::after {
    left: auto;
    right: 1rem;
    bottom: 100%;
    width: var(--door);
    height: var(--wall);
  }
  .room-map__room--lower .room-map__door {
    left: auto;
    right: 1rem;
    bottom: calc(100% + var(--wall));
    transform: rotate(180deg);
  }
  .room-map__corridor {
    grid-column: 1 / span 4;
    grid-row: 2;
  }
  .room-map__corridor span {
    writing-mode: horizontal-tb;
  }
  .room-map__corridor::after {
    top: 0;
    bottom: 0;
    left: 100%;
    right: auto;
    width: var(--wall);
    height: auto;
  }
  .room-map__reception {
    grid-column: 5;
    grid-row: 1 / span 3;
    flex-direction: column;
  }
}
@media (prefers-reduced-motion: reduce) {
  .room-map__leaf,
  .room-map__swing {
    transition: none;
  }
}
</style>
