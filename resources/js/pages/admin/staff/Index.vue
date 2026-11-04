<script setup lang="ts">
import { Link, useForm, usePage } from "@inertiajs/vue3";
import { DialogContent, DialogOverlay, DialogPortal } from "radix-vue";
import { computed, nextTick, ref } from "vue";
import { Dialog, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type { AdminStaffMember, AdminStaffPage, AppPageProps } from "@/types";

defineProps<{ staff: AdminStaffPage }>();
const { tr } = useLanguage();
const page = usePage<AppPageProps>();
const selected = ref<AdminStaffMember | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const heading = ref<HTMLElement | null>(null);
const feedback = ref<HTMLElement | null>(null);
const form = useForm({ allowed: false });
const error = computed(() => Object.values(form.errors).join(" "));
const operation = computed(() =>
  tr(form.allowed ? "ad.staff.grant" : "ad.staff.revoke"),
);

function choose(member: AdminStaffMember, event: MouseEvent): void {
  if (
    form.processing ||
    member.role === "owner" ||
    (!member.verified && !member.canManageBookings)
  )
    return;
  trigger.value = event.currentTarget as HTMLButtonElement;
  selected.value = { ...member };
  form.allowed = !member.canManageBookings;
  form.clearErrors();
}
function close(open = false): void {
  if (!open && !form.processing) selected.value = null;
}
function restoreFocus(event: Event): void {
  event.preventDefault();
  void nextTick(() => trigger.value?.focus());
}
function failedRequest(): false {
  form.setError("allowed", tr("ad.staff.failed"));
  void nextTick(() => feedback.value?.focus());
  return false;
}
function submit(): void {
  if (!selected.value || form.processing) return;
  form
    .transform(() => ({ allowed: form.allowed }))
    .patch(`/admin/staff/${selected.value.id}/booking-permission`, {
      preserveScroll: true,
      onSuccess: () => {
        selected.value = null;
      },
      onError: () => {
        void nextTick(() => feedback.value?.focus());
      },
      onHttpException: failedRequest,
      onNetworkError: failedRequest,
      onFinish: () => {
        if (selected.value === null)
          void nextTick(() => trigger.value?.focus());
      },
    });
}
</script>

<template>
  <div class="admin-shell">
    <AdminLayout
      :title="tr('ad.staff.title')"
      :subtitle="tr('ad.staff.subtitle')"
    >
      <div class="admin-content">
        <p
          v-if="page.props.flash?.status"
          role="status"
          class="admin-filter-panel"
        >
          {{ page.props.flash.status }}
        </p>
        <section class="admin-filter-panel" :aria-label="tr('ad.staff.title')">
          <p class="mb-4 text-sm">{{ tr("ad.staff.explanation") }}</p>
          <p v-if="!staff.data.length" role="status">
            {{ tr("ad.staff.empty") }}
          </p>
          <ul v-else class="divide-y divide-[var(--admin-line)]">
            <li
              v-for="member in staff.data"
              :key="member.id"
              :data-staff-id="member.id"
              class="flex flex-col items-stretch gap-4 py-5 sm:flex-row sm:items-center sm:justify-between"
            >
              <div class="min-w-0 flex-1 break-words">
                <h2 class="font-semibold">{{ member.name }}</h2>
                <p class="break-all text-sm">{{ member.email }}</p>
                <p class="mt-2 text-sm">
                  {{ tr(`ad.staff.${member.role}`) }} ·
                  {{
                    tr(
                      member.verified
                        ? "ad.staff.verified"
                        : "ad.staff.unverified",
                    )
                  }}
                </p>
                <p class="text-sm">
                  {{ tr("ad.staff.permission") }}:
                  <strong>{{
                    tr(
                      member.canManageBookings
                        ? "ad.staff.allowed"
                        : "ad.staff.denied",
                    )
                  }}</strong>
                </p>
              </div>
              <p v-if="member.role === 'owner'" class="text-sm">
                {{ tr("ad.staff.inherent") }}
              </p>
              <div v-else>
                <button
                  type="button"
                  class="admin-button"
                  :disabled="
                    form.processing ||
                    (!member.verified && !member.canManageBookings)
                  "
                  @click="choose(member, $event)"
                >
                  {{
                    tr(
                      member.canManageBookings
                        ? "ad.staff.revoke"
                        : "ad.staff.grant",
                    )
                  }}
                </button>
                <p
                  v-if="!member.verified && !member.canManageBookings"
                  class="mt-1 text-sm"
                >
                  {{ tr("ad.staff.verifyFirst") }}
                </p>
              </div>
            </li>
          </ul>
        </section>
        <nav
          v-if="staff.last_page > 1"
          class="flex flex-wrap items-center justify-between gap-3"
          :aria-label="tr('ad.staff.pagination')"
        >
          <Link
            v-if="staff.prev_page_url"
            :href="staff.prev_page_url"
            class="admin-button"
            >{{ tr("ad.staff.previous") }}</Link
          >
          <span>{{
            tr("ad.staff.page", {
              current: staff.current_page,
              last: staff.last_page,
            })
          }}</span>
          <Link
            v-if="staff.next_page_url"
            :href="staff.next_page_url"
            class="admin-button"
            data-staff-next
            >{{ tr("ad.staff.next") }}</Link
          >
        </nav>
      </div>
    </AdminLayout>
    <Dialog :open="selected !== null" @update:open="close">
      <DialogPortal>
        <DialogOverlay class="admin-dialog-overlay" />
        <DialogContent
          role="alertdialog"
          class="admin-shell admin-dialog-content"
          @interact-outside.prevent
          @open-auto-focus="
            (event) => {
              event.preventDefault();
              heading?.focus();
            }
          "
          @close-auto-focus="restoreFocus"
          @escape-key-down="
            (event) => {
              if (form.processing) event.preventDefault();
            }
          "
        >
          <DialogTitle as-child
            ><h2 ref="heading" tabindex="-1">{{ operation }}</h2></DialogTitle
          >
          <DialogDescription class="break-words">{{
            tr("ad.staff.confirm", {
              operation,
              name: selected?.name ?? "",
              email: selected?.email ?? "",
            })
          }}</DialogDescription>
          <p v-if="form.processing" role="status">
            {{ tr("ad.confirm.processing") }}
          </p>
          <form
            :aria-busy="form.processing"
            :aria-describedby="error ? 'staff-error' : undefined"
            @submit.prevent="submit"
          >
            <p
              v-if="error"
              id="staff-error"
              ref="feedback"
              role="alert"
              tabindex="-1"
              class="admin-filter-error"
            >
              {{ error }}
            </p>
            <div class="mt-5 flex flex-wrap justify-end gap-3">
              <button
                type="button"
                class="admin-button"
                :disabled="form.processing"
                @click="close()"
              >
                {{ tr("ad.staff.cancel") }}
              </button>
              <button
                type="submit"
                class="admin-button admin-button--primary"
                :disabled="form.processing"
              >
                {{ tr("ad.staff.confirmButton") }}
              </button>
            </div>
          </form>
        </DialogContent>
      </DialogPortal>
    </Dialog>
  </div>
</template>
