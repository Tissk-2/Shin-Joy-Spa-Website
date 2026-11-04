import "../css/app.css";

import { createInertiaApp, router } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import type { DefineComponent } from "vue";
import { createApp, h, nextTick } from "vue";
import { initializeReveals } from "@/lib/reveals";
import { ZiggyVue } from "../../vendor/tightenco/ziggy";

const appName = import.meta.env.VITE_APP_NAME || "Laravel";

document.documentElement.classList.add("js");

let revealFrame: number | undefined;

async function scheduleReveals(): Promise<void> {
  await nextTick();
  if (revealFrame !== undefined) cancelAnimationFrame(revealFrame);
  revealFrame = requestAnimationFrame(() => {
    revealFrame = undefined;
    initializeReveals();
  });
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

    void scheduleReveals();
  },
  progress: {
    color: "#4B5563",
  },
});

router.on("navigate", () => void scheduleReveals());
