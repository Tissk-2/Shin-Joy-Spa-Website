import { describe, expect, it } from "vitest";

import {
  bookingUrl,
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
});
