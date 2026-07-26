<script setup lang="ts">
/**
 * One band holding a page's search, filters, and list-level actions.
 *
 * The pattern this replaces is a page stacking several sibling forms, each with
 * its own border, padding, and background. Three of those is three bands of
 * chrome above the data, and on a wide display they are three near-empty rows.
 * A ControlBar is a single band: its children flow inline and wrap only when
 * they genuinely run out of room, so the same controls occupy the height they
 * need rather than the height their markup implies.
 *
 * Children stay separate `form` elements where they submit separately — the
 * bar is a layout container, not a merge of unrelated forms. Groups given
 * `data-control-group` flow as one unit so related controls wrap together
 * instead of splitting across rows.
 */
withDefaults(
  defineProps<{
    /** Accessible name for the band. */
    label: string;
    /**
     * `band` draws the surface, for a bar that sits above content.
     * `bare` draws nothing, for a bar already inside a card or section.
     */
    variant?: "band" | "bare";
  }>(),
  {
    variant: "band",
  },
);
</script>

<template>
  <div
    class="control-bar"
    :class="`control-bar--${variant}`"
    role="group"
    :aria-label="label"
  >
    <slot />
    <div v-if="$slots.end" class="control-bar__end">
      <slot name="end" />
    </div>
  </div>
</template>

<style scoped>
.control-bar {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3);
  min-width: 0;
}

.control-bar--band {
  padding: var(--m-pad-block) var(--m-pad-inline);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

/* Groups of related controls: one wrapping unit inside the bar. */
.control-bar :deep([data-control-group]) {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3);
  min-width: 0;
}

.control-bar__end {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  min-width: 0;
}

.control-bar :deep(button),
.control-bar__end :deep(a),
.control-bar__end :deep(button) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.75rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 800;
  line-height: 1;
  text-decoration: none;
  white-space: nowrap;
  cursor: pointer;
}

.control-bar :deep(button:focus-visible),
.control-bar :deep(a:focus-visible),
.control-bar :deep(input:focus-visible),
.control-bar :deep(select:focus-visible) {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .control-bar,
  .control-bar :deep([data-control-group]) {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--m-space-2) var(--m-space-3);
  }

  /*
   * Groups size to their contents and only take a share of leftover width when
   * they hold a growing field, so a two-control group never claims half a
   * 1900px bar.
   */
  .control-bar :deep([data-control-group]) {
    flex: 0 1 auto;
  }

  /*
   * A growing group absorbs leftover width, so it must give that width back by
   * compressing its field rather than wrapping its own button onto a second
   * line — which would make the whole bar row as tall as the group.
   */
  .control-bar :deep([data-control-group="grow"]) {
    flex: 1 1 16rem;
    flex-wrap: nowrap;
  }

  /*
   * The other labelled-control idiom in this codebase is a `label` wrapping its
   * own text and control. Supporting it here means an existing toolbar adopts
   * the bar by changing its wrapper element, without rewriting every field —
   * which is what makes this pattern something later screens will actually use.
   */
  .control-bar :deep(label:has(select)),
  .control-bar :deep(label:has(input)),
  .control-bar :deep(label:has(textarea)) {
    display: flex;
    align-items: center;
    gap: var(--m-space-2);
    min-width: 0;
    color: var(--m-text-secondary);
    font-size: var(--m-text-sm);
    font-weight: 800;
  }

  .control-bar :deep(label:has(select) > span),
  .control-bar :deep(label:has(input) > span) {
    white-space: nowrap;
  }

  .control-bar :deep(label:has(select) > select),
  .control-bar :deep(label:has(input) > input) {
    width: auto;
    min-width: 8rem;
  }

  .control-bar__end {
    margin-left: auto;
  }
}
</style>
