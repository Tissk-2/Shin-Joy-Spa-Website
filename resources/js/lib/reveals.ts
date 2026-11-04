let observer: IntersectionObserver | undefined;

export function initializeReveals(): void {
  observer?.disconnect();
  observer = undefined;

  if (
    typeof window.IntersectionObserver !== "function" ||
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  ) {
    return;
  }

  const elements = document.querySelectorAll<HTMLElement>(".reveal:not(.in)");
  if (!elements.length) return;

  const nextObserver = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        entry.target.classList.add("in");
        nextObserver.unobserve(entry.target);
      }
    },
    { threshold: 0.12 },
  );

  observer = nextObserver;
  elements.forEach((element) => nextObserver.observe(element));
}
