<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { DialogContent, DialogOverlay, DialogPortal } from "radix-vue";
import { ref } from "vue";
import { Dialog, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { useLanguage } from "@/composables/useLanguage";

const { tr } = useLanguage();
const open = ref(false);
const passwordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
  password: "",
});

function close(): void {
  open.value = false;
  form.clearErrors();
  form.reset();
}

function deleteUser(): void {
  form.delete("/settings/profile", {
    // Stay in place for a wrong password; a deleted account opens the home page at the top.
    preserveScroll: "errors",
    onSuccess: () => close(),
    onError: () => passwordInput.value?.focus(),
    onFinish: () => form.reset(),
  });
}
</script>

<template>
  <section
    class="account-block account-block--danger"
    aria-labelledby="delete-account-title"
  >
    <h2 id="delete-account-title">{{ tr("profile.delete.title") }}</h2>
    <p class="account-hint">
      {{ tr("profile.delete.subtitle") }}
      {{ tr("profile.delete.warningBody") }}
    </p>
    <div class="account-actions">
      <button
        type="button"
        class="btn btn--ghost account-danger-button"
        @click="open = true"
      >
        {{ tr("profile.delete.title") }}
      </button>
    </div>

    <Dialog :open="open" @update:open="(value) => (value ? null : close())">
      <DialogPortal>
        <DialogOverlay class="account-dialog-overlay" />
        <DialogContent class="account-dialog" role="alertdialog">
          <form @submit.prevent="deleteUser">
            <DialogTitle as-child>
              <h2>{{ tr("profile.delete.dialogTitle") }}</h2>
            </DialogTitle>
            <DialogDescription as-child>
              <p class="account-hint">{{ tr("profile.delete.dialogBody") }}</p>
            </DialogDescription>
            <div class="field">
              <label for="delete-password">{{
                tr("profile.delete.password")
              }}</label>
              <input
                id="delete-password"
                ref="passwordInput"
                v-model="form.password"
                type="password"
                name="password"
                autocomplete="current-password"
                :aria-invalid="form.errors.password ? 'true' : undefined"
                :aria-describedby="
                  form.errors.password ? 'delete-password-error' : undefined
                "
              />
              <span
                v-if="form.errors.password"
                id="delete-password-error"
                class="err"
                >{{ form.errors.password }}</span
              >
            </div>
            <div class="account-actions">
              <button type="button" class="btn btn--ghost" @click="close">
                {{ tr("profile.delete.cancel") }}
              </button>
              <button
                type="submit"
                class="btn btn--primary account-danger-submit"
                :disabled="form.processing"
              >
                {{ tr("profile.delete.confirm") }}
              </button>
            </div>
          </form>
        </DialogContent>
      </DialogPortal>
    </Dialog>
  </section>
</template>
