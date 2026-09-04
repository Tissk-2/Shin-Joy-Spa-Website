export function applyImageFallback(
  event: Event,
  fallback = "/img/massage.jpg",
): void {
  const image = event.currentTarget;

  if (!(image instanceof HTMLImageElement)) return;
  if (image.dataset.fallbackApplied === "true") return;

  image.dataset.fallbackApplied = "true";
  image.src = fallback;
}
