<script setup lang="ts">
import type { NavItem } from "@/types";
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";

const sidebarNavItems: NavItem[] = [
  { title: "Profile", href: "/settings/profile" },
  { title: "Password", href: "/settings/password" },
  { title: "Appearance", href: "/settings/appearance" },
];
const page = usePage();
const currentPath = computed(() => page.url.split("?")[0]);
</script>

<template>
  <div class="settings-layout">
    <header class="settings-heading">
      <h1>Settings</h1>
      <p>Manage your profile and account settings</p>
    </header>
    <div class="settings-grid">
      <aside>
        <nav class="settings-nav" aria-label="Account settings">
          <Link
            v-for="item in sidebarNavItems"
            :key="item.href"
            :href="item.href"
            :aria-current="currentPath === item.href ? 'page' : undefined"
            >{{ item.title }}</Link
          >
        </nav>
      </aside>
      <div class="settings-panel">
        <section class="space-y-12"><slot /></section>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* These messages in the existing forms predate semantic theme colors. */
.settings-panel :deep(.text-neutral-800) {
  color: hsl(var(--foreground));
}
.settings-panel :deep(.text-neutral-600) {
  color: hsl(var(--muted-foreground));
}
.settings-panel :deep(a.text-neutral-600:hover) {
  color: hsl(var(--primary));
}
</style>
