<script setup lang="ts">
import { Link, router, useForm, usePage } from "@inertiajs/vue3";
import { DialogContent, DialogOverlay, DialogPortal } from "radix-vue";
import { computed, nextTick, ref, watch } from "vue";
import { Dialog, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { useLanguage } from "@/composables/useLanguage";
import AdminLayout from "@/layouts/admin/AdminLayout.vue";
import type {
  AdminFreshTelegramLink,
  AdminStaffMember,
  AdminStaffPage,
  AdminTelegramLinkChange,
  AppPageProps,
} from "@/types";

const props = defineProps<{
  staff: AdminStaffPage;
  telegramHistory?: AdminTelegramLinkChange[];
  telegramBotConfigured?: boolean;
  freshTelegramLink?: AdminFreshTelegramLink | null;
}>();
const { tr, language } = useLanguage();
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
const telegramBusy = ref(false);
const telegramPending = ref<{
  member: AdminStaffMember;
  action: "activate" | "revoke";
} | null>(null);
const telegramTrigger = ref<HTMLElement | null>(null);
const telegramMemberId = ref<number | null>(null);
const telegramFailure = ref("");
const telegramMessages = computed(
  () =>
    [
      ...["staff", "telegram", "enrollment"].map(
        (key) => page.props.errors?.[key],
      ),
      telegramFailure.value,
    ].filter(Boolean) as string[],
);
// Messages show in the row that was acted on; at the top only if that row is gone.
const telegramRowId = computed(() =>
  props.staff.data.some(
    (member) => member.id === telegramMemberId.value && member.telegram,
  )
    ? telegramMemberId.value
    : null,
);
const when = (iso: string) =>
  new Intl.DateTimeFormat(language.value === "id" ? "id-ID" : "en-GB", {
    timeZone: "Asia/Jakarta",
    dateStyle: "medium",
    timeStyle: "short",
    hourCycle: "h23",
  }).format(new Date(iso)) + " WIB";
const canLink = (member: AdminStaffMember) =>
  member.verified && member.canManageBookings;

// Back to the button that started it, or the row's Telegram block if that button is gone.
function focusTelegram(memberId: number): void {
  void nextTick(() =>
    (telegramTrigger.value?.isConnected
      ? telegramTrigger.value
      : document.querySelector<HTMLElement>(
          `[data-staff-id="${memberId}"] [data-telegram-state]`,
        )
    )?.focus(),
  );
}
function askTelegram(
  member: AdminStaffMember,
  action: "activate" | "revoke",
  event: Event,
): void {
  telegramTrigger.value = event.currentTarget as HTMLElement;
  telegramPending.value = { member, action };
}
function cancelTelegram(): void {
  const memberId = telegramPending.value?.member.id;
  telegramPending.value = null;
  if (memberId !== undefined) focusTelegram(memberId);
}
function sendTelegram(
  member: AdminStaffMember,
  action: "issue" | "activate" | "revoke",
  event?: Event,
): void {
  if (telegramBusy.value) return;
  if (event) telegramTrigger.value = event.currentTarget as HTMLElement;
  telegramBusy.value = true;
  telegramMemberId.value = member.id;
  telegramFailure.value = "";
  const failed = (): false => {
    telegramFailure.value = tr("ad.staff.failed");
    return false;
  };
  const options = {
    preserveScroll: true,
    onHttpException: failed,
    onNetworkError: failed,
    onFinish: () => {
      telegramBusy.value = false;
      telegramPending.value = null;
      focusTelegram(member.id);
    },
  };
  if (action === "issue")
    router.post(`/admin/staff/${member.id}/telegram-enrollment`, {}, options);
  else if (action === "activate" && member.telegram?.state === "claimed")
    router.post(
      `/admin/telegram-enrollments/${member.telegram.enrollmentId}/activate`,
      {},
      options,
    );
  else if (action === "revoke")
    router.delete(`/admin/staff/${member.id}/telegram-link`, options);
  else telegramBusy.value = false;
}
function confirmTelegram(): void {
  if (telegramPending.value)
    sendTelegram(telegramPending.value.member, telegramPending.value.action);
}
function identityOf(member: AdminStaffMember): string {
  const telegram = member.telegram;
  return telegram &&
    (telegram.state === "claimed" || telegram.state === "linked")
    ? telegram.identity
    : "";
}
function telegramText(member: AdminStaffMember): string {
  const telegram = member.telegram;
  switch (telegram?.state) {
    case "waiting":
      return tr("ad.telegram.waiting", {
        name: member.name,
        time: when(telegram.expiresAt),
      });
    case "claimed":
      return tr("ad.telegram.claimed", {
        identity: telegram.identity,
        time: when(telegram.claimedAt),
        name: member.name,
      });
    case "linked":
      return tr("ad.telegram.linked", {
        identity: telegram.identity,
        time: when(telegram.linkedAt),
      });
    default:
      return tr("ad.telegram.none");
  }
}
watch(telegramPending, (pending) => {
  if (pending)
    void nextTick(() =>
      document
        .querySelector<HTMLElement>("[data-telegram-confirm-button]")
        ?.focus(),
    );
});

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
        <p
          v-if="telegramMessages.length && telegramRowId === null"
          role="alert"
          class="admin-filter-error"
          data-telegram-errors
        >
          {{ telegramMessages.join(" ") }}
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
                <div
                  v-if="member.telegram"
                  class="mt-3 space-y-2 text-sm"
                  tabindex="-1"
                  :data-telegram-state="member.telegram.state"
                >
                  <p>
                    <strong>{{ tr("ad.telegram.heading") }}:</strong>
                    {{ telegramText(member) }}
                  </p>
                  <p
                    v-if="
                      member.telegram.state === 'linked' && !canLink(member)
                    "
                    class="admin-filter-error"
                    data-telegram-warning
                  >
                    {{ tr("ad.telegram.cannotManage") }}
                  </p>
                  <p
                    v-if="
                      member.telegram.state === 'claimed' && !canLink(member)
                    "
                    data-telegram-cannot-activate
                  >
                    {{ tr("ad.telegram.cannotActivate") }}
                  </p>
                  <div class="flex flex-wrap gap-2">
                    <button
                      v-if="
                        member.telegram.state !== 'linked' && canLink(member)
                      "
                      type="button"
                      class="admin-button"
                      data-telegram-issue
                      :disabled="telegramBusy"
                      @click="sendTelegram(member, 'issue', $event)"
                    >
                      {{
                        tr(
                          member.telegram.state === "none"
                            ? "ad.telegram.issue"
                            : "ad.telegram.reissue",
                        )
                      }}
                    </button>
                    <button
                      v-if="
                        member.telegram.state === 'claimed' && canLink(member)
                      "
                      type="button"
                      class="admin-button admin-button--primary"
                      data-telegram-activate
                      :disabled="telegramBusy"
                      @click="askTelegram(member, 'activate', $event)"
                    >
                      {{ tr("ad.telegram.activate") }}
                    </button>
                    <button
                      v-if="member.telegram.state !== 'none'"
                      type="button"
                      class="admin-button"
                      data-telegram-revoke
                      :disabled="telegramBusy"
                      @click="askTelegram(member, 'revoke', $event)"
                    >
                      {{
                        tr(
                          member.telegram.state === "linked"
                            ? "ad.telegram.revoke"
                            : "ad.telegram.cancelLink",
                        )
                      }}
                    </button>
                  </div>
                  <p
                    v-if="
                      telegramMessages.length && telegramRowId === member.id
                    "
                    role="alert"
                    class="admin-filter-error"
                    data-telegram-row-error
                  >
                    {{ telegramMessages.join(" ") }}
                  </p>
                  <div
                    v-if="telegramPending?.member.id === member.id"
                    role="group"
                    data-telegram-confirm
                    :aria-label="tr('ad.telegram.confirm')"
                    @keydown.esc="cancelTelegram"
                  >
                    <p>
                      {{
                        telegramPending.action === "activate"
                          ? tr("ad.telegram.confirmActivate", {
                              identity: identityOf(member),
                              name: member.name,
                            })
                          : tr(
                              member.telegram.state === "linked"
                                ? "ad.telegram.confirmRevoke"
                                : "ad.telegram.confirmCancel",
                              { name: member.name },
                            )
                      }}
                    </p>
                    <button
                      type="button"
                      class="admin-button admin-button--primary"
                      data-telegram-confirm-button
                      :disabled="telegramBusy"
                      @click="confirmTelegram"
                    >
                      {{ tr("ad.telegram.confirm") }}
                    </button>
                    <button
                      type="button"
                      class="admin-button"
                      data-telegram-cancel
                      @click="cancelTelegram"
                    >
                      {{ tr("ad.telegram.back") }}
                    </button>
                  </div>
                  <div
                    v-if="
                      freshTelegramLink &&
                      freshTelegramLink.staffId === member.id
                    "
                    role="status"
                    data-telegram-fresh
                    class="admin-filter-panel break-all"
                  >
                    <p>
                      {{
                        tr("ad.telegram.freshTitle", {
                          name: member.name,
                          time: when(freshTelegramLink.expiresAt),
                        })
                      }}
                    </p>
                    <p v-if="freshTelegramLink.url">
                      <a
                        :href="freshTelegramLink.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-semibold underline"
                        >{{ freshTelegramLink.url }}</a
                      >
                    </p>
                    <p>
                      {{
                        tr(
                          freshTelegramLink.url
                            ? "ad.telegram.freshCommand"
                            : "ad.telegram.freshCommandOnly",
                        )
                      }}
                      <code>{{ freshTelegramLink.command }}</code>
                    </p>
                    <p v-if="!telegramBotConfigured">
                      {{ tr("ad.telegram.botMissing") }}
                    </p>
                  </div>
                </div>
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
        <section
          v-if="telegramHistory"
          class="admin-filter-panel"
          data-telegram-history
          :aria-label="tr('ad.telegram.historyTitle')"
        >
          <h2 class="font-semibold">{{ tr("ad.telegram.historyTitle") }}</h2>
          <p v-if="!telegramHistory.length">
            {{ tr("ad.telegram.historyEmpty") }}
          </p>
          <ul v-else class="mt-2 space-y-1 text-sm">
            <li
              v-for="(change, index) in telegramHistory"
              :key="index"
              class="break-words"
            >
              {{ when(change.at) }} ·
              {{
                tr(
                  change.action === "activated"
                    ? "ad.telegram.historyActivated"
                    : "ad.telegram.historyRevoked",
                  {
                    actor: change.actorName,
                    identity: change.identity,
                    target: change.targetName,
                  },
                )
              }}
              <template v-if="change.targetDeleted">
                {{ tr("ad.telegram.accountDeleted") }}</template
              >
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
