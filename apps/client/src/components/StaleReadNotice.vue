<script setup lang="ts">
import { computed } from "vue";

import { formatTimestamp } from "@/department-ops/labels";
import type { ReadFreshness } from "@/offline/readFreshness";

/**
 * What a surface says when it is showing a stored copy (M18.9; technical spec
 * 9.3; UI implementation contract 16.2).
 *
 * Stale authorized data beats no data, which is why the page renders at all. It
 * does not follow that the reader should have to work out which they are looking
 * at. The moment the copy was taken is the only thing separating a stored answer
 * from a live one on screen, and somebody who has not been watching the clock
 * cannot tell those apart — so the surface states it.
 *
 * Silent on a live read. A banner on every page saying "this is current" is a
 * banner people stop reading, and the one time it matters is the time it changed
 * to something else.
 */
const props = defineProps<{
  readonly freshness: ReadFreshness;
  /** The event's clock, so the timestamp reads as it does on site. */
  readonly timeZone?: string;
  /** What is stale, for a page showing more than one read. */
  readonly label?: string;
}>();

const stale = computed(() => props.freshness.source === "cache");

/**
 * Built in one place rather than assembled in the template.
 *
 * Vue collapses whitespace around interpolations differently depending on where
 * the tags fall, and the first version of this rendered "storedAug 2" — a
 * disclosure with a typo in it reads as a bug rather than as a warning, which is
 * the opposite of what it is for.
 */
const sentence = computed(() => {
  const subject = props.label ? `${props.label} is` : "This is";
  const takenAt =
    props.freshness.cachedAt === null
      ? null
      : formatTimestamp(props.freshness.cachedAt, props.timeZone ?? "UTC");
  const taken = takenAt === null ? "" : ` ${takenAt}`;

  return `This node could not be reached. ${subject} the copy this device stored${taken}, and it may have moved on since.`;
});
</script>

<template>
  <p v-if="stale" class="stale-read" role="status">
    <span class="stale-read__mark" aria-hidden="true">•</span>
    <span>{{ sentence }}</span>
  </p>
</template>

<style scoped>
/*
 * The warning colour and a rule beside it, with the sentence carrying the
 * meaning. Colour is never the only signal: a reader who cannot see it still
 * reads what happened and when the copy was taken.
 */
.stale-read {
  display: flex;
  align-items: baseline;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-4);
  padding: var(--m-space-3);
  border-inline-start: 3px solid var(--m-status-warning);
  border-radius: 4px;
  background: color-mix(
    in srgb,
    var(--m-status-warning) 8%,
    var(--m-surface-raised)
  );
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.stale-read__mark {
  color: var(--m-status-warning);
  font-size: var(--m-text-md);
  line-height: 1;
}
</style>
