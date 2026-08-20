import "../css/app.css";

import { createInertiaApp, router } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import type { DefineComponent } from "vue";
import { createApp, h } from "vue";
import { ZiggyVue } from "../../vendor/tightenco/ziggy";

interface ImportMetaEnv {
  readonly VITE_APP_NAME: string;
}

const appName = import.meta.env.VITE_APP_NAME || "Laravel";

document.documentElement.classList.add("js");

function initializeReveals(): void {
  const elements = document.querySelectorAll<HTMLElement>(".reveal:not(.in)");

  if (!elements.length) return;

  if (
    !("IntersectionObserver" in window) ||
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  ) {
    elements.forEach((element) => element.classList.add("in"));
    return;
  }

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        entry.target.classList.add("in");
        observer.unobserve(entry.target);
      }
    },
    { threshold: 0.12 },
  );

  elements.forEach((element) => observer.observe(element));
}

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: (name) =>
    resolvePageComponent(
      `./pages/${name}.vue`,
      import.meta.glob<DefineComponent>("./pages/**/*.vue"),
    ),
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(ZiggyVue)
      .mount(el);

    requestAnimationFrame(initializeReveals);
  },
  progress: {
    color: "#4B5563",
  },
});

router.on("navigate", () => requestAnimationFrame(initializeReveals));
