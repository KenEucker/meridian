<script setup lang="ts">
/**
 * One labelled control inside a ControlBar.
 *
 * Two things make control bands taller than they need to be: a label stacked
 * over every control, and every control stretched to an equal share of the
 * container. This field fixes both. Past a phone the label sits beside its
 * control, which is a row of height back per control, and the field is sized by
 * what it holds rather than by how many siblings it has — a State select does
 * not need the same width as a search box, and neither needs a seventh of a
 * 1900px screen.
 *
 * `width` is a declaration of what the control holds, not a pixel value, so a
 * page states intent and the bar decides the rest.
 */
withDefaults(
  defineProps<{
    label: string;
    /** `id` of the control in the slot, so the label points at it. */
    controlId?: string;
    /**
     * `sm` short enumerations, `md` names and dates, `lg` long values,
     * `grow` the one control that should absorb leftover width (search).
     */
    width?: "sm" | "md" | "lg" | "grow";
    /** Hide the visible label where the control is self-describing. */
    labelHidden?: boolean;
  }>(),
  {
    controlId: undefined,
    width: "md",
    labelHidden: false,
  },
);
</script>

<template>
  <div class="control-field" :data-width="width">
    <label
      :for="controlId"
      :class="labelHidden ? 'control-field__label-hidden' : 'control-field__label'"
    >
      {{ label }}
    </label>
    <slot />
  </div>
</template>

<style scoped>
.control-field {
  display: grid;
  gap: var(--m-space-1);
  min-width: 0;
}

.control-field__label {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
  white-space: nowrap;
}

.control-field__label-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

.control-field :deep(input),
.control-field :deep(select) {
  width: 100%;
  min-width: 0;
  min-height: 2.75rem;
}

@media (min-width: 48rem) {
  .control-field {
    display: flex;
    align-items: center;
    gap: var(--m-space-2);
    flex: 0 1 auto;
  }

  /*
   * Widths are content classes, not measurements: a field is as wide as the
   * values it shows, so a bar of mixed controls packs instead of dividing the
   * container into equal columns.
   */
  .control-field[data-width="sm"] :deep(input),
  .control-field[data-width="sm"] :deep(select) {
    width: 7.5rem;
  }

  .control-field[data-width="md"] :deep(input),
  .control-field[data-width="md"] :deep(select) {
    width: 10rem;
  }

  .control-field[data-width="lg"] :deep(input),
  .control-field[data-width="lg"] :deep(select) {
    width: 14rem;
  }

  .control-field[data-width="grow"] {
    flex: 1 1 14rem;
  }

  .control-field[data-width="grow"] :deep(input),
  .control-field[data-width="grow"] :deep(select) {
    width: 100%;
  }
}
</style>
