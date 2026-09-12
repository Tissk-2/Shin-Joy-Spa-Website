<script setup lang="ts">
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import type { CatalogChoiceInput, TreatmentMode } from "@/types";

const props = withDefaults(
  defineProps<{
    kind: "variants" | "extensions";
    rows: readonly CatalogChoiceInput[];
    errors?: Readonly<Record<string, string>>;
    disabled?: boolean;
  }>(),
  { errors: () => ({}), disabled: false },
);
const emit = defineEmits<{
  "update:rows": [rows: readonly CatalogChoiceInput[]];
}>();
const { tr } = useLanguage();
let rowSequence = 0;
const heading = computed(() =>
  props.kind === "variants"
    ? "ad.catalog.form.variants"
    : "ad.catalog.form.extensions",
);

function error(index: number, field: string): string | undefined {
  return props.errors[`${props.kind}.${index}.${field}`];
}
function key(index: number, field: string): string {
  return `${props.kind}.${index}.${field}`;
}
function add(): void {
  emit("update:rows", [
    ...props.rows,
    {
      clientKey: `${props.kind}-${++rowSequence}`,
      code: "",
      mode: "regular",
      durationMinutes: "",
      priceIdr: "",
      isActive: true,
      displayOrder: String(props.rows.length),
      isPublished: false,
    },
  ]);
}
function update(
  index: number,
  field: keyof CatalogChoiceInput,
  value: string | boolean,
): void {
  emit(
    "update:rows",
    props.rows.map((row, rowIndex) =>
      rowIndex === index ? { ...row, [field]: value } : row,
    ),
  );
}
function remove(index: number): void {
  emit(
    "update:rows",
    props.rows.filter((_, rowIndex) => rowIndex !== index),
  );
}
</script>

<template>
  <fieldset class="catalog-choice-rows" :disabled="disabled">
    <legend>{{ tr(heading) }}</legend>
    <p v-if="!rows.length" class="catalog-help">
      {{ tr("ad.catalog.form.emptyRows") }}
    </p>
    <section
      v-for="(row, index) in rows"
      :key="row.clientKey"
      class="catalog-choice-row"
      role="group"
      :data-choice-row="`${kind}.${index}`"
      :aria-label="`${tr(heading)} ${index + 1}`"
      :aria-invalid="!!error(index, 'id')"
      :aria-describedby="
        error(index, 'id') ? `${key(index, 'id')}-error` : undefined
      "
    >
      <p
        v-if="error(index, 'id')"
        :id="`${key(index, 'id')}-error`"
        class="catalog-error"
        role="alert"
      >
        {{ error(index, "id") }}
      </p>
      <div
        v-for="field in [
          'code',
          'mode',
          'durationMinutes',
          'priceIdr',
          'displayOrder',
        ]"
        :key="field"
        class="catalog-field"
      >
        <label :for="key(index, field)">{{
          tr(`ad.catalog.form.${field}`)
        }}</label>
        <select
          v-if="field === 'mode'"
          :id="key(index, field)"
          :name="key(index, field)"
          :value="row.mode"
          :aria-invalid="!!error(index, field)"
          :aria-describedby="
            error(index, field) ? `${key(index, field)}-error` : undefined
          "
          @change="
            update(
              index,
              'mode',
              ($event.target as HTMLSelectElement).value as TreatmentMode,
            )
          "
        >
          <option value="regular">
            {{ tr("ad.catalog.form.modeRegular") }}
          </option>
          <option value="double_therapist">
            {{ tr("ad.catalog.form.modeDoubleTherapist") }}
          </option>
        </select>
        <input
          v-else
          :id="key(index, field)"
          :name="key(index, field)"
          :type="field === 'code' ? 'text' : 'number'"
          :min="field === 'displayOrder' ? 0 : field === 'code' ? undefined : 1"
          step="1"
          :value="row[field as keyof CatalogChoiceInput] as string"
          :readonly="field === 'code' && row.isPublished"
          :aria-invalid="!!error(index, field)"
          :aria-describedby="
            error(index, field) ? `${key(index, field)}-error` : undefined
          "
          @input="
            update(
              index,
              field as keyof CatalogChoiceInput,
              ($event.target as HTMLInputElement).value,
            )
          "
        />
        <p
          v-if="error(index, field)"
          :id="`${key(index, field)}-error`"
          class="catalog-error"
        >
          {{ error(index, field) }}
        </p>
      </div>
      <label class="catalog-choice-active"
        ><input
          :name="key(index, 'isActive')"
          type="checkbox"
          :checked="row.isActive"
          :aria-invalid="!!error(index, 'isActive')"
          :aria-describedby="
            error(index, 'isActive')
              ? `${key(index, 'isActive')}-error`
              : undefined
          "
          @change="
            update(
              index,
              'isActive',
              ($event.target as HTMLInputElement).checked,
            )
          "
        />
        {{ tr("ad.catalog.form.active") }}</label
      >
      <p
        v-if="error(index, 'isActive')"
        :id="`${key(index, 'isActive')}-error`"
        class="catalog-error"
      >
        {{ error(index, "isActive") }}
      </p>
      <button
        type="button"
        class="catalog-control"
        :disabled="disabled"
        data-choice-remove
        @click="remove(index)"
      >
        {{ tr("ad.catalog.form.removeRow") }}
      </button>
    </section>
    <button
      type="button"
      class="catalog-control"
      :disabled="disabled"
      data-choice-add
      @click="add"
    >
      {{ tr("ad.catalog.form.addRow") }}
    </button>
  </fieldset>
</template>
