import { computed, onScopeDispose, readonly, ref, watch, type Ref } from "vue";
import {
  monthAvailabilityQueryKey,
  parseMonthAvailability,
  type MonthAvailabilityQuery,
} from "@/lib/roomAvailability";

export function useMonthAvailability(
  enabled: Readonly<Ref<unknown>>,
  query: Readonly<Ref<MonthAvailabilityQuery | null>>,
  endpoint = "/booking/availability/month",
) {
  const fullDates = ref<string[]>([]);
  const key = computed(() =>
    enabled.value === true ? monthAvailabilityQueryKey(query.value) : null,
  );
  let generation = 0;
  let controller: AbortController | undefined;
  let disposed = false;

  async function refresh(): Promise<void> {
    const request = ++generation;
    controller?.abort();
    fullDates.value = [];
    if (disposed || enabled.value !== true) return;
    const identity = key.value;
    if (!identity) return;
    const expectedMonth = query.value?.month;
    controller =
      typeof AbortController === "undefined"
        ? undefined
        : new AbortController();
    try {
      const response = await fetch(`${endpoint}?${identity}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        signal: controller?.signal,
      });
      if (disposed || request !== generation || !response.ok) return;
      const data = parseMonthAvailability(await response.json());
      if (disposed || request !== generation) return;
      if (!data || data.month !== expectedMonth) return;
      fullDates.value = data.full_dates;
    } catch {
      if (!disposed && request === generation) fullDates.value = [];
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
    fullDates.value = [];
  });

  return {
    fullDates: readonly(fullDates),
    refresh,
  };
}
