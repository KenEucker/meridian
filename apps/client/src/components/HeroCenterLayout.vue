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
 * the middle spanning rows, cards flowing densely around it. Dense placement
 * means the layout does not depend on the card count: extra cards continue
 * below the hero rather than leaving holes.
 */
withDefaults(
  defineProps<{
    /** Accessible name for the surrounding region. */
    label: string;
    /** Rows the hero spans at wide widths. Match it to half the card count. */
    heroRows?: number;
  }>(),
  {
    heroRows: 3,
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

  .hero-center__hero {
    grid-column: 2;
    grid-row: 1 / span var(--m-hero-rows, 3);
    align-self: stretch;
  }
}
</style>
