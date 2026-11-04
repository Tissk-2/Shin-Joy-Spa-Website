<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { ref } from "vue";

// Components
import { useLanguage } from "@/composables/useLanguage";
import HeadingSmall from "@/components/HeadingSmall.vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const passwordInput = ref<HTMLInputElement | null>(null);
const { tr } = useLanguage();

const form = useForm({
  password: "",
});

const deleteUser = (e: Event) => {
  e.preventDefault();

  form.delete(route("profile.destroy"), {
    preserveScroll: true,
    onSuccess: () => closeModal(),
    onError: () => passwordInput.value?.focus(),
    onFinish: () => form.reset(),
  });
};

const closeModal = () => {
  form.clearErrors();
  form.reset();
};
</script>

<template>
  <div class="space-y-6">
    <HeadingSmall
      :title="tr('profile.delete.title')"
      :description="tr('profile.delete.subtitle')"
    />
    <div
      class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
    >
      <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
        <p class="font-medium">{{ tr("profile.delete.warning") }}</p>
        <p class="text-sm">{{ tr("profile.delete.warningBody") }}</p>
      </div>
      <Dialog>
        <DialogTrigger as-child>
          <Button variant="destructive">{{
            tr("profile.delete.title")
          }}</Button>
        </DialogTrigger>
        <DialogContent>
          <form class="space-y-6" @submit="deleteUser">
            <DialogHeader class="space-y-3">
              <DialogTitle>{{ tr("profile.delete.dialogTitle") }}</DialogTitle>
              <DialogDescription>
                {{ tr("profile.delete.dialogBody") }}
              </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
              <Label for="password" class="sr-only">{{
                tr("profile.delete.password")
              }}</Label>
              <Input
                id="password"
                type="password"
                name="password"
                ref="passwordInput"
                v-model="form.password"
                :placeholder="tr('profile.delete.password')"
              />
              <InputError :message="form.errors.password" />
            </div>

            <DialogFooter>
              <DialogClose as-child>
                <Button variant="secondary" @click="closeModal">
                  {{ tr("profile.delete.cancel") }}
                </Button>
              </DialogClose>

              <Button variant="destructive" :disabled="form.processing">
                <button type="submit">
                  {{ tr("profile.delete.confirm") }}
                </button>
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  </div>
</template>
