<script setup lang="ts">
import { ChevronDown, ChevronUp } from "lucide-vue-next";
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import { formatPrice } from "@/lib/adminBooking";
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
// Moving renumbers every row, so the saved order always matches what staff see.
function move(index: number, offset: -1 | 1): void {
  const rows = [...props.rows];
  [rows[index], rows[index + offset]] = [rows[index + offset], rows[index]];
  emit(
    "update:rows",
    rows.map((row, order) => ({ ...row, displayOrder: String(order) })),
  );
}
// A published option's code can't change, so only new options show it.
function fields(row: CatalogChoiceInput): readonly string[] {
  return row.isPublished
    ? ["mode", "durationMinutes", "priceIdr"]
    : ["code", "mode", "durationMinutes", "priceIdr"];
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
        v-for="field in fields(row)"
        :key="field"
        class="catalog-field"
        :class="{ 'catalog-field--code': field === 'code' }"
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
          :min="field === 'code' ? undefined : 1"
          step="1"
          :value="row[field as keyof CatalogChoiceInput] as string"
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
          v-if="field === 'priceIdr' && Number(row.priceIdr) > 0"
          class="catalog-price-hint"
          data-price-hint
        >
          {{ formatPrice(Number(row.priceIdr)) }}
        </p>
        <p v-if="field === 'code'" class="catalog-help">
          {{ tr("ad.catalog.form.codeHelp") }}
        </p>
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
      <div class="catalog-choice-move">
        <button
          type="button"
          class="catalog-control catalog-control--icon"
          :disabled="disabled || index === 0"
          :aria-label="tr('ad.catalog.form.moveUp')"
          :title="tr('ad.catalog.form.moveUp')"
          data-choice-up
          @click="move(index, -1)"
        >
          <ChevronUp aria-hidden="true" />
        </button>
        <button
          type="button"
          class="catalog-control catalog-control--icon"
          :disabled="disabled || index === rows.length - 1"
          :aria-label="tr('ad.catalog.form.moveDown')"
          :title="tr('ad.catalog.form.moveDown')"
          data-choice-down
          @click="move(index, 1)"
        >
          <ChevronDown aria-hidden="true" />
        </button>
      </div>
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
