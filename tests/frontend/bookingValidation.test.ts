import { describe, expect, it } from "vitest";

import {
  timeSlots,
  todayIso,
  validateBooking,
} from "../../resources/js/lib/booking";
import { isValidPhoneNumber } from "../../resources/js/lib/phone";
import type { PackageData } from "../../resources/js/types";

const packages: PackageData[] = [
  {
    slug: "nezuko",
    nameId: "Nezuko",
    nameEn: "Nezuko",
    isVip: false,
    descId: "",
    descEn: "",
    longId: "",
    longEn: "",
    benefitId: null,
    benefitEn: null,
    image: "",
    gallery: [],
    variants: [
      {
        code: "nezuko-regular-90",
        mode: "regular",
        duration: 90,
        price: 265000,
      },
      {
        code: "nezuko-double-60",
        mode: "double_therapist",
        duration: 60,
        price: 250000,
      },
    ],
    extensions: [
      {
        code: "nezuko-regular-extra-60",
        mode: "regular",
        duration: 60,
        price: 75000,
      },
      {
        code: "nezuko-double-extra-30",
        mode: "double_therapist",
        duration: 30,
        price: 100000,
      },
    ],
  },
];

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

  it("rejects incomplete, unknown, inactive, incompatible, or array-like package selections", () => {
    expect(
      validateBooking(
        {
          name: " ",
          phone: "123",
          service: "missing" as string,
          mode: "" as "",
          duration: null,
          extension: "nezuko-regular-extra-60",
          date: "2026-08-19",
          time: "08:30",
        },
        packages,
        "2026-08-20",
      ),
    ).toEqual({
      name: "b.err.name",
      phone: "b.err.phone",
      service: "b.err.service",
      mode: "b.err.mode",
      duration: "b.err.duration",
      extension: "b.err.extension",
      date: "b.err.date",
      time: "b.err.time",
    });
    expect(
      validateBooking(
        {
          name: "Nadia",
          phone: "+62 812-3456-7890",
          service: "nezuko",
          mode: "double_therapist",
          duration: 60,
          extension: "nezuko-regular-extra-60",
          date: "2026-08-20",
          time: "09:00",
        },
        packages,
        "2026-08-20",
      ),
    ).toEqual({ extension: "b.err.extension" });
    expect(
      validateBooking(
        {
          name: "Nadia",
          phone: "+62 812-3456-7890",
          service: ["nezuko"] as unknown as string,
          mode: "regular",
          duration: 90,
          extension: "",
          date: "2026-08-20",
          time: "09:00",
        },
        packages,
        "2026-08-20",
      ),
    ).toEqual({ service: "b.err.service" });
    expect(timeSlots).toContain("09:00");
    expect(timeSlots).toContain("20:00");
  });

  it("accepts an exact active Nezuko regular 90-minute selection with its extension", () => {
    expect(
      validateBooking(
        {
          name: "Nadia",
          phone: "+62 812-3456-7890",
          service: "nezuko",
          mode: "regular",
          duration: 90,
          extension: "nezuko-regular-extra-60",
          date: "2026-08-20",
          time: "09:00",
        },
        packages,
        "2026-08-20",
      ),
    ).toEqual({});
  });

  it("rejects non-scalar, malformed, impossible, past, and lexically future junk dates", () => {
    const validSelection = {
      name: "Nadia",
      phone: "+62 812-3456-7890",
      service: "nezuko",
      mode: "regular" as const,
      duration: 90,
      extension: "nezuko-regular-extra-60",
      time: "09:00",
    };

    for (const date of [
      "2026-2-20",
      "2026-02-30",
      "2026-08-20oops",
      "9999-99-99",
      ["2026-08-20"],
    ]) {
      expect(
        validateBooking(
          { ...validSelection, date: date as string },
          packages,
          "2026-08-20",
        ),
      ).toMatchObject({ date: "b.err.date" });
    }

    expect(
      validateBooking(
        { ...validSelection, date: "2026-08-19" },
        packages,
        "2026-08-20",
      ),
    ).toMatchObject({ date: "b.err.date" });
    expect(
      validateBooking(
        { ...validSelection, date: "2026-08-20" },
        packages,
        "2026-08-20",
      ),
    ).toEqual({});
  });
});
