<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { computed, nextTick, ref } from "vue";

import CategoryEditor from "@/components/admin/catalog/CategoryEditor.vue";
import CatalogLifecycleActions from "@/components/admin/catalog/CatalogLifecycleActions.vue";

import CatalogStatusBadge from "@/components/admin/catalog/CatalogStatusBadge.vue";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type {
  AdminCatalogAbilities,
  AdminCatalogCategoryData,
  AdminCatalogIndexProps,
  AdminCatalogPackageData,
} from "@/types";

const props = defineProps<AdminCatalogIndexProps>();
const { language, tr } = useLanguage();
const lifecycleActions = ["publish", "archive", "restore", "delete"] as const;
const editor = ref<{
  category: AdminCatalogCategoryData | null;
  key: number;
} | null>(null);
const editorProcessing = ref(false);
const editorTrigger = ref<HTMLButtonElement | null>(null);
let editorKey = 0;

function openEditor(
  category: AdminCatalogCategoryData | null,
  event: MouseEvent,
): void {
  if (editorProcessing.value) return;
  editorTrigger.value = event.currentTarget as HTMLButtonElement;
  editor.value = { category, key: ++editorKey };
}

function closeEditor(): void {
  if (editorProcessing.value) return;
  editor.value = null;
  void nextTick(() => editorTrigger.value?.focus());
}

const categoryBySlug = computed(
  () => new Map(props.categories.map((category) => [category.slug, category])),
);

function localizedName(
  record: AdminCatalogCategoryData | AdminCatalogPackageData,
): string {
  return language.value === "id" ? record.nameId : record.nameEn;
}

function otherName(
  record: AdminCatalogCategoryData | AdminCatalogPackageData,
): string {
  return language.value === "id" ? record.nameEn : record.nameId;
}

function actionLabel(action: keyof AdminCatalogAbilities): string {
  return tr(`ad.catalog.${action}`);
}

function availableActions(
  can: Readonly<AdminCatalogAbilities>,
): readonly (typeof lifecycleActions)[number][] {
  return lifecycleActions.filter((action) => can[action]);
}

function packageHiddenByCategory(
  packageItem: AdminCatalogPackageData,
): boolean {
  return (
    packageItem.status === "published" &&
    categoryBySlug.value.get(packageItem.categorySlug)?.status !== "published"
  );
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
          aria-labelledby="catalog-categories-title"
        >
          <div class="catalog-section-heading">
            <div>
              <p class="catalog-kicker">{{ tr("ad.catalog.category") }}</p>
              <h2 id="catalog-categories-title">
                {{ tr("ad.catalog.categories") }}
              </h2>
            </div>
            <button
              v-if="props.can.createCategory"
              class="catalog-control catalog-control--primary"
              type="button"
              data-catalog-create="category"
              :disabled="editorProcessing"
              @click="openEditor(null, $event)"
            >
              {{ tr("ad.catalog.createCategory") }}
            </button>
          </div>

          <CategoryEditor
            v-if="editor"
            :key="editor.key"
            :category="editor.category"
            @processing="editorProcessing = $event"
            @saved="closeEditor"
            @cancel="closeEditor"
          />

          <p
            v-if="!props.categories.length"
            class="catalog-empty"
            data-catalog-empty="categories"
            role="status"
          >
            {{ tr("ad.catalog.emptyCategories") }}
          </p>
          <ul v-else class="catalog-list" role="list">
            <li
              v-for="category in props.categories"
              :key="category.slug"
              class="catalog-category-row"
              :data-catalog-category="category.slug"
            >
              <div class="catalog-record-copy">
                <h3>{{ localizedName(category) }}</h3>
                <p class="catalog-secondary">{{ otherName(category) }}</p>
                <p class="catalog-count">
                  {{
                    tr("ad.catalog.packageCount", {
                      count: category.packageCount,
                    })
                  }}
                </p>
              </div>
              <div class="catalog-record-meta">
                <CatalogStatusBadge :status="category.status" />
                <div
                  class="catalog-actions"
                  :aria-label="localizedName(category)"
                >
                  <button
                    v-if="category.can.update"
                    class="catalog-control"
                    type="button"
                    :data-catalog-action="`${category.slug}-edit`"
                    :disabled="editorProcessing"
                    @click="openEditor(category, $event)"
                  >
                    {{ tr("ad.catalog.editCategory") }}
                  </button>
                  <CatalogLifecycleActions
                    :category="category"
                    :disabled="editorProcessing"
                  />
                </div>
              </div>
            </li>
          </ul>
        </section>

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
            <Link
              v-if="props.can.createPackage"
              class="catalog-control catalog-control--primary"
              href="/admin/catalog/packages/create"
            >
              {{ tr("ad.catalog.createPackage") }}
            </Link>
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
                <p
                  v-if="packageHiddenByCategory(packageItem)"
                  class="catalog-warning"
                  :data-catalog-warning="packageItem.slug"
                  role="alert"
                >
                  {{ tr("ad.catalog.hiddenByCategory") }}
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
                  <button
                    v-for="action in availableActions(packageItem.can)"
                    :key="action"
                    class="catalog-control"
                    type="button"
                    :data-catalog-action="`${packageItem.slug}-${action}`"
                  >
                    {{ actionLabel(action) }}
                  </button>
                </div>
              </div>
            </li>
          </ul>
        </section>
      </div>
    </AdminLayout>
  </div>
</template>
