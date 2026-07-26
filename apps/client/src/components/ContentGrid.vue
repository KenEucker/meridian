<script setup lang="ts">
/**
 * Tiling grid for a set of peer blocks — record cards, sections, summaries.
 *
 * One column on a phone, then a column per `min` of available width, so the
 * height a list needs falls as the display grows. This is the primitive that
 * makes a wide screen show more at once rather than the same single column with
 * empty space beside it.
 *
 * `auto-fill`, not `auto-fit`: a two-item list should render two normal tiles
 * with space beside them, not two tiles stretched across a television. Tile
 * width stays predictable regardless of how many records came back.
 */
withDefaults(
  defineProps<{
    /** Rendered element. Use `ul` when the children are `li` records. */
    as?: "div" | "ul";
    /** Narrowest a tile may get before the grid drops a column. */
    min?: "tile" | "wide" | "compact";
    /** Accessible name, required when `as` is `ul`. */
    label?: string;
    /**
     * Stretch tiles in a row to equal height. Off for blocks whose natural
     * height carries meaning, such as collapsed document sections.
     */
    stretch?: boolean;
  }>(),
  {
    as: "div",
    min: "tile",
    label: undefined,
    stretch: true,
  },
);
</script>

<template>
  <component
    :is="as"
    class="content-grid"
    :class="[`content-grid--${min}`, stretch ? '' : 'content-grid--start']"
    :aria-label="label"
  >
    <slot />
  </component>
</template>

<style scoped>
.content-grid {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: 0;
  min-width: 0;
  list-style: none;
  grid-template-columns: minmax(0, 1fr);
}

.content-grid--start {
  align-items: start;
}

@media (min-width: 48rem) {
  .content-grid--compact {
    grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
  }

  .content-grid--tile {
    grid-template-columns: repeat(auto-fill, minmax(var(--m-tile-min), 1fr));
  }

  .content-grid--wide {
    grid-template-columns: repeat(
      auto-fill,
      minmax(var(--m-tile-min-wide), 1fr)
    );
  }
}

/* Gutters grow with the display so dense grids do not read as one slab. */
@media (min-width: 90rem) {
  .content-grid {
    gap: var(--m-space-4);
  }
}
</style>
