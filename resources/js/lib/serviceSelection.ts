import type { PackageData, TreatmentMode } from "@/types";

export interface ServiceSelection {
  service: string;
  mode: TreatmentMode | "";
  duration: number | null;
  extension: string;
}

export interface SelectionTotal {
  duration: number;
  price: number;
}

export function emptySelection(service: string): ServiceSelection {
  return { service, mode: "", duration: null, extension: "" };
}

export function normalizeSelection(
  servicePackage: PackageData,
  candidate: ServiceSelection,
): ServiceSelection {
  const service = servicePackage.slug;
  const mode = candidate.service === service ? candidate.mode : "";

  if (mode === "") return emptySelection(service);

  const variant = servicePackage.variants.find(
    (item) => item.mode === mode && item.duration === candidate.duration,
  );
  if (!variant) return { service, mode, duration: null, extension: "" };

  const extension = servicePackage.extensions.find(
    (item) => item.mode === mode && item.code === candidate.extension,
  );

  return {
    service,
    mode,
    duration: variant.duration,
    extension: extension?.code ?? "",
  };
}

export function selectionTotal(
  servicePackage: PackageData,
  candidate: ServiceSelection,
): SelectionTotal {
  const selection = normalizeSelection(servicePackage, candidate);
  const variant = servicePackage.variants.find(
    (item) =>
      item.mode === selection.mode && item.duration === selection.duration,
  );
  if (!variant) return { duration: 0, price: 0 };

  const extension = servicePackage.extensions.find(
    (item) => item.mode === selection.mode && item.code === selection.extension,
  );
  return {
    duration: variant.duration + (extension?.duration ?? 0),
    price: variant.price + (extension?.price ?? 0),
  };
}

export function bookingUrl(selection: ServiceSelection): string {
  if (!selection.service || !selection.mode || selection.duration === null)
    return "";

  const query = new URLSearchParams({
    service: selection.service,
    mode: selection.mode,
    duration: String(selection.duration),
  });
  if (selection.extension) query.set("extension", selection.extension);

  return `/booking?${query.toString()}`;
}
