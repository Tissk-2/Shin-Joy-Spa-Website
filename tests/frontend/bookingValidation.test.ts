import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  jakartaTimeIso,
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
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-08-20T00:00:00.000Z"));
  });

  afterEach(() => vi.useRealTimers());

  it("validates phone numbers without accepting short input", () => {
    expect(isValidPhoneNumber("+62 812-3456-7890")).toBe(true);
    expect(isValidPhoneNumber("123")).toBe(false);
    expect(isValidPhoneNumber(null)).toBe(false);
  });

  it("treats the WhatsApp number as optional when left blank", () => {
    const validSelection = {
      name: "Nadia",
      service: "nezuko",
      mode: "regular" as const,
      duration: 90,
      extension: "nezuko-regular-extra-60",
      date: "2026-08-20",
      time: "09:00",
    };

    for (const phone of ["", "   "]) {
      expect(
        validateBooking({ ...validSelection, phone }, packages, "2026-08-20"),
      ).toEqual({});
    }
    expect(
      validateBooking(
        { ...validSelection, phone: "+62 812-3456-7890" },
        packages,
        "2026-08-20",
      ),
    ).toEqual({});
  });

  it("formats the Jakarta calendar date as ISO without mutating it", () => {
    const date = new Date("2026-08-20T17:30:00.000Z");

    expect(todayIso(date)).toBe("2026-08-21");
    expect(date.toISOString()).toBe("2026-08-20T17:30:00.000Z");
  });

  it("uses Jakarta time when checking whether today's slot has elapsed", () => {
    expect(jakartaTimeIso(new Date("2026-08-20T08:15:00.000Z"))).toBe("15:15");

    const booking = {
      name: "Nadia",
      phone: "",
      service: "nezuko",
      mode: "regular" as const,
      duration: 90,
      extension: "",
      date: "2026-08-20",
      time: "15:00",
    };

    expect(validateBooking(booking, packages, "2026-08-20", "15:15")).toEqual({
      time: "b.err.time",
    });
    expect(
      validateBooking(
        { ...booking, time: "15:30" },
        packages,
        "2026-08-20",
        "15:15",
      ),
    ).toEqual({});
  });

  it("rejects incomplete, unknown, inactive, incompatible, or array-like package selections", () => {
    expect(
      validateBooking(
        {
          name: " ",
          phone: "123",
          service: "missing" as string,
          mode: "" as const,
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

  it("rejects sessions ending after 21:00 including their extension", () => {
    const booking = {
      name: "Nadia",
      phone: "",
      service: "nezuko",
      mode: "regular" as const,
      duration: 90,
      extension: "",
      date: "2026-08-21",
      time: "20:00",
    };

    expect(validateBooking(booking, packages, "2026-08-20")).toEqual({
      time: "b.err.time",
    });
    expect(
      validateBooking(
        { ...booking, time: "19:00", extension: "nezuko-regular-extra-60" },
        packages,
        "2026-08-20",
      ),
    ).toEqual({ time: "b.err.time" });
    expect(
      validateBooking({ ...booking, time: "19:30" }, packages, "2026-08-20"),
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
