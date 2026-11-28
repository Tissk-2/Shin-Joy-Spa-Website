<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import { staffGuide } from "@/data/staffGuide";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type { AppPageProps } from "@/types";

const props = defineProps<{
  roomSchedulingEnabled: boolean;
  customerNoticesEnabled: boolean;
  telegramActionsEnabled: boolean;
  canCreateBooking: boolean;
}>();

const { language, tr } = useLanguage();
const page = usePage<AppPageProps>();
const isOwner = computed(() => Boolean(page.props.auth.isOwner));
const copy = computed(() => staffGuide[language.value] ?? staffGuide.id);
const visibleSections = computed(() =>
  copy.value.sections.filter((section) => !section.ownerOnly || isOwner.value),
);

const statusItems = computed(() => [
  {
    key: "scheduling",
    label: copy.value.statusScheduling,
    on: props.roomSchedulingEnabled,
  },
  {
    key: "emails",
    label: copy.value.statusEmails,
    on: props.customerNoticesEnabled,
  },
  {
    key: "telegram",
    label: copy.value.statusTelegram,
    on: props.telegramActionsEnabled,
  },
]);
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.guide.title')"
      :subtitle="tr('ad.guide.subtitle')"
    >
      <div class="admin-content admin-guide">
        <section
          class="admin-panel admin-guide__status"
          :aria-label="copy.statusHeading"
          data-guide-status
        >
          <p class="admin-guide__status-title">{{ copy.statusHeading }}</p>
          <ul class="admin-guide__status-list" role="list">
            <li
              v-for="item in statusItems"
              :key="item.key"
              :data-guide-flag="item.key"
            >
              <span>{{ item.label }}:</span>
              <strong
                class="admin-status"
                :class="
                  item.on ? 'admin-status--confirmed' : 'admin-status--pending'
                "
              >
                {{ item.on ? copy.statusOn : copy.statusOff }}
              </strong>
            </li>
          </ul>
        </section>

        <nav class="admin-jump" :aria-label="copy.jumpLabel">
          <a
            v-for="section in visibleSections"
            :key="section.id"
            :href="`#guide-${section.id}`"
          >
            {{ section.title }}
          </a>
        </nav>

        <div class="admin-guide__sections">
          <section
            v-for="section in visibleSections"
            :id="`guide-${section.id}`"
            :key="section.id"
            class="admin-panel admin-guide__card"
            :data-guide-section="section.id"
            :aria-labelledby="`guide-title-${section.id}`"
          >
            <h2 :id="`guide-title-${section.id}`">{{ section.title }}</h2>
            <p class="admin-guide__summary">{{ section.summary }}</p>
            <ol class="admin-guide__steps">
              <li v-for="(step, index) in section.steps" :key="index">
                {{ step }}
              </li>
            </ol>
            <p v-if="section.note" class="admin-guide__note">
              {{ section.note }}
            </p>
            <div
              v-if="
                section.links?.some(
                  (link) => !link.requiresCreate || canCreateBooking,
                )
              "
              class="admin-guide__links"
            >
              <Link
                v-for="link in section.links.filter(
                  (entry) => !entry.requiresCreate || canCreateBooking,
                )"
                :key="link.href"
                :href="link.href"
                class="admin-button"
              >
                {{ link.label }}
              </Link>
            </div>
          </section>
        </div>
      </div>
    </AdminLayout>
  </div>
</template>
