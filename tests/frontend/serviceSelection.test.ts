import { describe, expect, it } from "vitest";

import {
  bookingUrl,
  guestCoverage,
  normalizeSelection,
  selectionTotal,
} from "@/lib/serviceSelection";
import { applyImageFallback } from "@/lib/media";
import type { PackageData } from "@/types";

const nezuko: PackageData = {
  slug: "nezuko",
  nameId: "Nezuko",
  nameEn: "Nezuko",
  isVip: false,
  descId: "Pijat lembut.",
  descEn: "Gentle massage.",
  longId: "Pijat lembut.",
  longEn: "Gentle massage.",
  benefitId: "Tidur lebih baik.",
  benefitEn: "Better sleep.",
  image: "/img/nezuko.jpg",
  gallery: [],
  variants: [
    { code: "nezuko-regular-90", mode: "regular", duration: 90, price: 265000 },
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
};

describe("service selection", () => {
  it("normalizes exact compatible choices without mutating package data", () => {
    const original = structuredClone(nezuko);
    const selected = normalizeSelection(nezuko, {
      service: "nezuko",
      mode: "regular",
      duration: 90,
      extension: "nezuko-regular-extra-60",
    });

    expect(selectionTotal(nezuko, selected)).toEqual({
      duration: 150,
      price: 340000,
    });
    expect(
      normalizeSelection(nezuko, { ...selected, mode: "double_therapist" }),
    ).toEqual({
      service: "nezuko",
      mode: "double_therapist",
      duration: null,
      extension: "",
    });
    expect(bookingUrl(selected)).toBe(
      "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
    );
    expect(nezuko).toEqual(original);
  });

  it("applies a local image fallback once", () => {
    const image = document.createElement("img");
    image.src = "/img/missing.jpg";

    image.addEventListener("error", applyImageFallback);
    image.dispatchEvent(new Event("error"));
    expect(image.src).toContain("/img/massage.jpg");
    expect(image.dataset.fallbackApplied).toBe("true");

    image.dispatchEvent(new Event("error"));
    expect(image.src).toContain("/img/massage.jpg");
  });

  it("multiplies the verified per-person unit by the guest count", () => {
    const verified: PackageData = {
      ...nezuko,
      pricingBasis: "per_person",
      minGuests: 1,
      maxGuests: 4,
      pricingVerified: true,
    };
    const selected = normalizeSelection(verified, {
      service: "nezuko",
      mode: "regular",
      duration: 90,
      extension: "nezuko-regular-extra-60",
    });

    expect(selectionTotal(verified, selected, 1)).toEqual({
      duration: 150,
      price: 340000,
    });
    expect(selectionTotal(verified, selected, 2)).toEqual({
      duration: 150,
      price: 680000,
    });
    expect(selectionTotal(verified, selected, 3)).toEqual({
      duration: 150,
      price: 1020000,
    });
    expect(guestCoverage(verified)).toEqual({ min: 1, max: 4 });
  });

  it("keeps one verified per-booking total regardless of guests", () => {
    const verified: PackageData = {
      ...nezuko,
      pricingBasis: "per_booking",
      minGuests: 2,
      maxGuests: 4,
      pricingVerified: true,
    };
    const selected = normalizeSelection(verified, {
      service: "nezuko",
      mode: "regular",
      duration: 90,
      extension: "nezuko-regular-extra-60",
    });

    expect(selectionTotal(verified, selected, 2).price).toBe(340000);
    expect(selectionTotal(verified, selected, 4).price).toBe(340000);
    expect(guestCoverage(verified)).toEqual({ min: 2, max: 4 });
  });

  it("keeps legacy packages on the unit total and the full guest range", () => {
    const selected = normalizeSelection(nezuko, {
      service: "nezuko",
      mode: "regular",
      duration: 90,
      extension: "nezuko-regular-extra-60",
    });
    const basisOnly: PackageData = {
      ...nezuko,
      pricingBasis: "per_person",
      minGuests: 1,
      maxGuests: 4,
      pricingVerified: false,
    };

    expect(selectionTotal(nezuko, selected, 3).price).toBe(340000);
    expect(selectionTotal(basisOnly, selected, 3).price).toBe(340000);
    expect(guestCoverage(nezuko)).toEqual({ min: 1, max: 4 });
    expect(guestCoverage(basisOnly)).toEqual({ min: 1, max: 4 });
  });
});
