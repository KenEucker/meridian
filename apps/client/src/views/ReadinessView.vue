<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import NodeConnectionPanel from "@/components/NodeConnectionPanel.vue";
import ReadinessChecklist from "@/components/ReadinessChecklist.vue";
import {
  resolveReadinessChecklist,
  summarizeReadiness,
  type ReadinessItemStatus,
} from "@/readiness/checklist";

// Technical spec section 14: readiness is tracked per user/device/event, is
// advisory only, is visible to the user, and is never visible to organizers. It
// does not expire automatically and the app must avoid nagging.
//
// Resolved reactively rather than once, because the node connection panel below
// changes one of the signals — pointing the device at a node is a readiness
// step the user takes on this screen, and the checklist has to reflect it
// immediately or the screen contradicts itself.
const items = computed(() => resolveReadinessChecklist());
const readinessStatusOrder: Record<ReadinessItemStatus, number> = {
  ready: 0,
  "not-ready": 1,
  pending: 2,
};
const sortedItems = computed(() =>
  [...items.value].sort(
    (left, right) =>
      readinessStatusOrder[left.status] - readinessStatusOrder[right.status],
  ),
);
const summary = computed(() => summarizeReadiness(items.value));
</script>

<template>
  <section class="readiness" aria-labelledby="readiness-heading">
    <p class="readiness__nav">
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </p>
    <h1 id="readiness-heading" class="readiness__heading">Device readiness</h1>
    <p class="readiness__lede">
      This checklist is only for you. It is advisory, is not shared with
      organizers, and does not expire. Preparing your device before an event is
      encouraged but not required.
    </p>
    <p class="readiness__summary" role="status">
      {{ summary.ready }} of {{ summary.total }} checks ready.
    </p>
    <NodeConnectionPanel />
    <ReadinessChecklist :items="sortedItems" />
  </section>
</template>

<style scoped>
.readiness {
  width: var(--m-content-narrow);
}

.readiness__nav {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.readiness__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.readiness__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.readiness__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.readiness__summary {
  margin: 0 0 var(--m-space-4);
  font-weight: 600;
  color: var(--m-text-secondary);
}

.readiness__nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
