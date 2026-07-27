<script setup lang="ts">
/**
 * A hero block held in the middle of the page with its peer cards around it.
 *
 * For a page whose cards all answer the same question — Event Info's six
 * sections all answer "what do I need to know before I arrive" — a plain grid
 * puts the summary at the top and pushes the reader down through the answers.
 * Centring the summary makes the relationship visible: the cards surround the
 * thing they belong to, and the summary stays on screen while they are read.
 *
 * One column on a phone with the hero first, because a centre cell has no
 * meaning in a single column. Three columns past the wide breakpoint, hero in
 * the middle of the grid — middle column and, starting a row down, middle row —
 * so cards sit above it, beside it, and below it. A hero pinned to the top row
 * is still just a summary with a list under it; surrounding it on all four
 * sides is what makes it read as the centre of the page. Dense placement means
 * the layout does not depend on the card count: extra cards continue below the
 * hero rather than leaving holes.
 */
withDefaults(
  defineProps<{
    /** Accessible name for the surrounding region. */
    label: string;
    /**
     * Rows the hero spans at wide widths, starting from the second row. Keep it
     * small enough that the card flow still reaches a row underneath: with a
     * three-column grid, `cards >= 6` and `heroRows` of 1 leaves a row below.
     */
    heroRows?: number;
  }>(),
  {
    heroRows: 1,
  },
);
</script>

<template>
  <div
    class="hero-center"
    :style="{ '--m-hero-rows': String(heroRows) }"
    role="group"
    :aria-label="label"
  >
    <div class="hero-center__hero">
      <slot name="hero" />
    </div>
    <slot />
  </div>
</template>

<style scoped>
.hero-center {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
  grid-template-columns: minmax(0, 1fr);
  align-items: start;
}

.hero-center__hero {
  min-width: 0;
}

@media (min-width: 48rem) {
  .hero-center {
    grid-template-columns: repeat(auto-fill, minmax(var(--m-tile-min-wide), 1fr));
  }
}

@media (min-width: 90rem) {
  .hero-center {
    gap: var(--m-space-4);
  }
}

/*
 * Past ~1500px there is room for a card column either side of the hero, so the
 * hero takes the middle and the cards wrap around it.
 */
@media (min-width: 94rem) {
  .hero-center {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    grid-auto-flow: row dense;
  }

  /*
   * Row 2, not row 1: the first row of cards lands above the hero, and the
   * dense flow carries the remaining cards below it.
   */
  .hero-center__hero {
    grid-column: 2;
    grid-row: 2 / span var(--m-hero-rows, 1);
    align-self: stretch;
  }
}
</style>
