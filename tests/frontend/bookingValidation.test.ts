import { describe, expect, it } from "vitest";

import {
  timeSlots,
  todayIso,
  validateBooking,
} from "../../resources/js/lib/booking";
import { isValidPhoneNumber } from "../../resources/js/lib/phone";

const services = [{ slug: "aromatherapy" }];

describe("booking validation helpers", () => {
  it("validates phone numbers without accepting short input", () => {
    expect(isValidPhoneNumber("+62 812-3456-7890")).toBe(true);
    expect(isValidPhoneNumber("123")).toBe(false);
    expect(isValidPhoneNumber(null)).toBe(false);
  });

  it("formats a local calendar date as ISO without mutating it", () => {
    const date = new Date(2026, 7, 20, 12, 0, 0);

    expect(todayIso(date)).toBe("2026-08-20");
    expect(date.getHours()).toBe(12);
  });

  it("returns field errors for invalid booking input", () => {
    expect(
      validateBooking(
        {
          name: " ",
          phone: "123",
          service: "missing",
          date: "2026-08-19",
          time: "08:30",
        },
        services,
        "2026-08-20",
      ),
    ).toEqual({
      name: "b.err.name",
      phone: "b.err.phone",
      service: "b.err.service",
      date: "b.err.date",
      time: "b.err.time",
    });
    expect(timeSlots).toContain("09:00");
    expect(timeSlots).toContain("20:00");
  });
});
