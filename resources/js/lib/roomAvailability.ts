export interface AvailabilityRoom {
  code: string;
  name: string;
  type: string | null;
  capacity: number;
  available: boolean;
}
export interface AvailabilitySlot {
  start_at: string;
  rooms: AvailabilityRoom[];
}
export interface AvailabilityResult {
  generated_at: string;
  slots: AvailabilitySlot[];
}
export interface AvailabilityQuery {
  service: string;
  mode: string;
  duration: number | null;
  extension: string;
  date: string;
  guests: number;
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function utcTimestamp(value: unknown): value is string {
  if (
    typeof value !== "string" ||
    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(value)
  )
    return false;
  const date = new Date(value);
  return (
    Number.isFinite(date.getTime()) &&
    date.toISOString().slice(0, 19) === value.slice(0, 19)
  );
}
export function jakartaAppointment(timestamp: string): {
  date: string;
  time: string;
} {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Jakarta",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(new Date(timestamp));
  const part = (type: string) =>
    parts.find((item) => item.type === type)?.value ?? "";
  return {
    date: `${part("year")}-${part("month")}-${part("day")}`,
    time: `${part("hour")}:${part("minute")}`,
  };
}
export function parseAvailability(value: unknown): AvailabilityResult | null {
  if (
    !record(value) ||
    !utcTimestamp(value.generated_at) ||
    !Array.isArray(value.slots)
  )
    return null;
  const slots: AvailabilitySlot[] = [];
  for (const slot of value.slots) {
    if (
      !record(slot) ||
      !utcTimestamp(slot.start_at) ||
      !Array.isArray(slot.rooms)
    )
      return null;
    const rooms: AvailabilityRoom[] = [];
    for (const room of slot.rooms) {
      if (
        !record(room) ||
        typeof room.code !== "string" ||
        !room.code ||
        typeof room.name !== "string" ||
        !room.name ||
        (room.type !== null && typeof room.type !== "string") ||
        !Number.isInteger(room.capacity) ||
        Number(room.capacity) < 1 ||
        typeof room.available !== "boolean"
      )
        return null;
      rooms.push({
        code: room.code,
        name: room.name,
        type: room.type,
        capacity: Number(room.capacity),
        available: room.available,
      });
    }
    if (new Set(rooms.map((room) => room.code)).size !== rooms.length)
      return null;
    slots.push({ start_at: slot.start_at, rooms });
  }
  if (new Set(slots.map((slot) => slot.start_at)).size !== slots.length)
    return null;
  return { generated_at: value.generated_at, slots };
}
export function availabilityQueryKey(
  query: AvailabilityQuery | null,
): string | null {
  if (
    !query ||
    !query.service ||
    query.service.length > 80 ||
    !["regular", "double_therapist"].includes(query.mode) ||
    !Number.isInteger(query.duration) ||
    Number(query.duration) < 1 ||
    Number(query.duration) > 480 ||
    !Number.isInteger(query.guests) ||
    query.guests < 1 ||
    query.guests > 4 ||
    query.extension.length > 80 ||
    !/^\d{4}-\d{2}-\d{2}$/.test(query.date)
  )
    return null;
  const date = new Date(`${query.date}T00:00:00Z`);
  if (
    !Number.isFinite(date.getTime()) ||
    date.toISOString().slice(0, 10) !== query.date
  )
    return null;
  const params = new URLSearchParams({
    service: query.service,
    mode: query.mode,
    duration: String(query.duration),
    date: query.date,
    guests: String(query.guests),
  });
  if (query.extension) params.set("extension", query.extension);
  return params.toString();
}
