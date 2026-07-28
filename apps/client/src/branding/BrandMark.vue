<script setup lang="ts">
import { computed } from "vue";

import { lettermarkFor } from "@/branding/lettermark";

/**
 * A department's or a team's mark on its own (BRAND-005, BRAND-010,
 * BRAND-025).
 *
 * {@link ../components/DepartmentBadge.vue DepartmentBadge} is the labelled
 * pill; this is the glyph without the pill, for the places where the name is
 * already on screen next to it — the shell's department context, the
 * department switcher in the header, a team heading. Rendering the badge in
 * those places would print the name twice.
 *
 * The fallback chain is the badge's: logo, then a lettermark generated from the
 * name. There is always something to render, so an empty slot is never mistaken
 * for a broken image.
 *
 * Decorative by default. The name is beside it, and a mark that repeated it
 * would be read twice by a screen reader. Pass `label` only where this is the
 * *only* thing identifying the entity — the header switcher buttons, where the
 * mark is the whole control.
 */

const props = withDefaults(
  defineProps<{
    readonly name: string;
    readonly logoUrl?: string | null;
    /** Server-generated lettermark; computed from the name when absent. */
    readonly lettermark?: string | null;
    readonly accentColor?: string | null;
    readonly size?: "sm" | "md" | "lg" | "xl" | "xxl";
    /** Accessible name. Omit to render the mark as decorative. */
    readonly label?: string | null;
  }>(),
  {
    logoUrl: null,
    lettermark: null,
    accentColor: null,
    size: "md",
    label: null,
  },
);

const letters = computed(() => props.lettermark ?? lettermarkFor(props.name));

const accentStyle = computed(() =>
  props.accentColor ? { "--m-brand-mark-accent": props.accentColor } : {},
);
</script>

<template>
  <span
    class="brand-mark"
    :class="`brand-mark--${size}`"
    :data-mark="logoUrl ? 'logo' : 'lettermark'"
    :style="accentStyle"
    :role="label ? 'img' : undefined"
    :aria-label="label ?? undefined"
    :aria-hidden="label ? undefined : 'true'"
  >
    <img v-if="logoUrl" class="brand-mark__image" :src="logoUrl" alt="" />
    <span v-else class="brand-mark__lettermark">{{ letters }}</span>
  </span>
</template>

<style scoped>
.brand-mark {
  --m-brand-mark-accent: var(--m-department-accent);

  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  overflow: hidden;
  color: var(--m-text-primary);
  font-family: var(--m-font-heading);
  font-weight: 800;
  letter-spacing: 0.02em;
  line-height: 1;
}

/*
 * The chip is the lettermark's, not the mark's.
 *
 * Two letters floating on the page do not read as an identity; they need a
 * shape to sit in. An uploaded logo already is one, and putting it in a tinted
 * rounded box adds a second, competing edge around artwork whose own edges
 * someone chose deliberately. So the chip appears only when there is no logo,
 * which also means the fallback and the real thing occupy the same box.
 *
 * The tint is a fraction of the accent rather than the accent itself: the
 * lettermark's contrast was validated against the organization surface, not
 * against an arbitrary department color.
 */
.brand-mark[data-mark="lettermark"] {
  border-radius: var(--m-radius-sm);
  background: color-mix(in srgb, var(--m-brand-mark-accent) 16%, transparent);
}

.brand-mark[data-mark="logo"] {
  background: transparent;
}

.brand-mark__image {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.brand-mark--sm {
  width: 1.5rem;
  height: 1.5rem;
  font-size: 0.6rem;
}

.brand-mark--md {
  width: 2rem;
  height: 2rem;
  font-size: 0.7rem;
}

.brand-mark--lg {
  width: 2.5rem;
  height: 2.5rem;
  font-size: 0.85rem;
}

/*
 * Header scale. `xxl` is what the header's own identity marks fill — it is
 * sized to the masthead's content row, not chosen for looks, so the mark reads
 * as the bar's subject rather than as decoration inside it. `xl` is for a mark
 * that shares that row without setting its height.
 */
.brand-mark--xl {
  width: 4rem;
  height: 4rem;
  font-size: 1.4rem;
}

.brand-mark--xxl {
  width: 5rem;
  height: 5rem;
  font-size: 1.7rem;
}
</style>
