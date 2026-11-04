<script setup lang="ts">
import { Link } from "@inertiajs/vue3";

import CatalogLifecycleActions from "@/components/admin/catalog/CatalogLifecycleActions.vue";

import CatalogStatusBadge from "@/components/admin/catalog/CatalogStatusBadge.vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type { AdminCatalogIndexProps, AdminCatalogPackageData } from "@/types";

const props = defineProps<AdminCatalogIndexProps>();
const { language, tr } = useLanguage();

function localizedName(record: AdminCatalogPackageData): string {
  return language.value === "id" ? record.nameId : record.nameEn;
}

function otherName(record: AdminCatalogPackageData): string {
  return language.value === "id" ? record.nameEn : record.nameId;
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.catalog.title')"
      :subtitle="tr('ad.catalog.subtitle')"
    >
      <div class="catalog-index">
        <section
          class="catalog-section"
          aria-labelledby="catalog-packages-title"
        >
          <div class="catalog-section-heading">
            <div>
              <p class="catalog-kicker">{{ tr("ad.catalog.package") }}</p>
              <h2 id="catalog-packages-title">
                {{ tr("ad.catalog.packages") }}
              </h2>
            </div>
          </div>

          <p
            v-if="!props.packages.length"
            class="catalog-empty"
            data-catalog-empty="packages"
            role="status"
          >
            {{ tr("ad.catalog.emptyPackages") }}
          </p>
          <ul v-else class="catalog-list" role="list">
            <li
              v-for="packageItem in props.packages"
              :key="packageItem.slug"
              class="catalog-package-row"
              :data-catalog-package="packageItem.slug"
            >
              <div class="catalog-record-copy">
                <div class="catalog-package-title">
                  <h3>{{ localizedName(packageItem) }}</h3>
                  <span v-if="packageItem.isVip" class="catalog-vip">{{
                    tr("ad.catalog.vip")
                  }}</span>
                </div>
                <p class="catalog-secondary">{{ otherName(packageItem) }}</p>
                <p class="catalog-count">
                  {{
                    tr("ad.catalog.optionCount", {
                      count: packageItem.optionCount,
                    })
                  }}
                  <span aria-hidden="true">·</span>
                  {{
                    tr("ad.catalog.variantCount", {
                      count: packageItem.variantCount,
                    })
                  }}
                  <span aria-hidden="true">·</span>
                  {{
                    tr("ad.catalog.extensionCount", {
                      count: packageItem.extensionCount,
                    })
                  }}
                </p>
              </div>
              <div class="catalog-record-meta">
                <CatalogStatusBadge :status="packageItem.status" />
                <div
                  class="catalog-actions"
                  :aria-label="localizedName(packageItem)"
                >
                  <Link
                    v-if="packageItem.can.update"
                    class="catalog-control"
                    :href="`/admin/catalog/packages/${encodeURIComponent(packageItem.slug)}/edit`"
                  >
                    {{ tr("ad.catalog.editPackage") }}
                  </Link>
                  <CatalogLifecycleActions :package="packageItem" />
                </div>
              </div>
            </li>
          </ul>
        </section>
      </div>
    </AdminLayout>
  </div>
</template>
