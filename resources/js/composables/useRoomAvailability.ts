import { computed, onScopeDispose, readonly, ref, watch, type Ref } from "vue";
import {
  availabilityQueryKey,
  jakartaAppointment,
  parseAvailability,
  type AvailabilityQuery,
  type AvailabilitySlot,
} from "@/lib/roomAvailability";

export type AvailabilityStatus =
  | "disabled"
  | "incomplete"
  | "loading"
  | "success"
  | "invalid"
  | "rate-limit"
  | "auth"
  | "unavailable";

export function useRoomAvailability(
  enabled: Readonly<Ref<unknown>>,
  query: Readonly<Ref<AvailabilityQuery | null>>,
) {
  const slots = ref<AvailabilitySlot[]>([]);
  const generatedAt = ref<string | null>(null);
  const status = ref<AvailabilityStatus>("disabled");
  const loading = computed(() => status.value === "loading");
  const error = computed(() =>
    ["invalid", "rate-limit", "auth", "unavailable"].includes(status.value)
      ? status.value
      : null,
  );
  const key = computed(() =>
    enabled.value === true ? availabilityQueryKey(query.value) : null,
  );
  let generation = 0;
  let controller: AbortController | undefined;
  let disposed = false;
  async function refresh(): Promise<void> {
    const request = ++generation;
    controller?.abort();
    slots.value = [];
    generatedAt.value = null;
    if (disposed || enabled.value !== true) {
      status.value = "disabled";
      return;
    }
    const identity = key.value;
    if (!identity) {
      status.value = "incomplete";
      return;
    }
    const expectedDate = query.value?.date;
    controller =
      typeof AbortController === "undefined"
        ? undefined
        : new AbortController();
    status.value = "loading";
    try {
      const response = await fetch(`/booking/availability?${identity}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        signal: controller?.signal,
      });
      if (disposed || request !== generation) return;
      if (!response.ok) {
        status.value = [401, 403, 419].includes(response.status)
          ? "auth"
          : response.status === 422
            ? "invalid"
            : response.status === 429
              ? "rate-limit"
              : "unavailable";
        return;
      }
      const data = parseAvailability(await response.json());
      if (disposed || request !== generation) return;
      if (
        !data ||
        data.slots.some(
          (slot) => jakartaAppointment(slot.start_at).date !== expectedDate,
        ) ||
        new Set(
          data.slots.map((slot) => jakartaAppointment(slot.start_at).time),
        ).size !== data.slots.length
      ) {
        status.value = "unavailable";
        return;
      }
      slots.value = data.slots;
      generatedAt.value = data.generated_at;
      status.value = "success";
    } catch {
      if (!disposed && request === generation) status.value = "unavailable";
    }
  }
  watch([enabled, key], () => void refresh(), {
    immediate: true,
    flush: "sync",
  });
  onScopeDispose(() => {
    disposed = true;
    generation++;
    controller?.abort();
    slots.value = [];
    generatedAt.value = null;
    status.value = "disabled";
  });
  return {
    slots: readonly(slots),
    generatedAt: readonly(generatedAt),
    loading,
    error,
    status: readonly(status),
    refresh,
  };
}
