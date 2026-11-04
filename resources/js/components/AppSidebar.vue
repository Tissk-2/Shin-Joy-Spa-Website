<script setup lang="ts">
import NavUser from "@/components/NavUser.vue";
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar";
import { useLanguage } from "@/composables/useLanguage";
import type { AppPageProps, NavItem } from "@/types";
import { Link, usePage } from "@inertiajs/vue3";
import { BookOpen, CalendarDays, House, Settings } from "lucide-vue-next";
import { computed } from "vue";
import AppLogo from "./AppLogo.vue";

const page = usePage<AppPageProps>();
const { tr } = useLanguage();
const mainNavItems = computed<NavItem[]>(() => [
  { title: tr("nav.bookings"), href: "/bookings", icon: CalendarDays },
  { title: tr("nav.services"), href: "/services", icon: BookOpen },
  { title: tr("nav.profile"), href: "/settings/profile", icon: Settings },
  ...(page.props.auth.canAccessAdmin
    ? [{ title: tr("ad.panel"), href: "/admin/bookings", icon: CalendarDays }]
    : []),
  { title: tr("nav.home"), href: "/", icon: House },
]);
function isActive(href: string) {
  const path = page.url.split("?")[0];
  return href === "/settings/profile"
    ? path.startsWith("/settings/")
    : path === href;
}
</script>

<template>
  <Sidebar collapsible="icon" variant="sidebar" class="account-sidebar">
    <SidebarHeader>
      <SidebarMenu>
        <SidebarMenuItem>
          <SidebarMenuButton size="lg" as-child>
            <Link href="/" aria-label="Shin Joy Spa"><AppLogo /></Link>
          </SidebarMenuButton>
        </SidebarMenuItem>
      </SidebarMenu>
    </SidebarHeader>
    <SidebarContent>
      <nav class="px-2" :aria-label="tr('nav.primary')">
        <SidebarMenu>
          <SidebarMenuItem v-for="item in mainNavItems" :key="item.href">
            <SidebarMenuButton
              as-child
              :is-active="isActive(item.href)"
              :tooltip="item.title"
              class="min-h-11"
            >
              <Link
                :href="item.href"
                :aria-current="isActive(item.href) ? 'page' : undefined"
              >
                <component :is="item.icon" aria-hidden="true" />
                <span>{{ item.title }}</span>
              </Link>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </nav>
    </SidebarContent>
    <SidebarFooter><NavUser v-if="page.props.auth.user" /></SidebarFooter>
  </Sidebar>
  <slot />
</template>
