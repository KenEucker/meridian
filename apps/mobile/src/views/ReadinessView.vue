<script setup lang="ts">
import { computed } from "vue";

import ReadinessChecklist from "@/components/ReadinessChecklist.vue";
import {
  resolveReadinessChecklist,
  summarizeReadiness,
} from "@/readiness/checklist";

// Technical spec section 14: readiness is tracked per user/device/event, is
// advisory only, is visible to the user, and is never visible to organizers. It
// does not expire automatically and the app must avoid nagging. The checklist
// is probed from the current client scope once when the surface renders.
const items = resolveReadinessChecklist();
const summary = computed(() => summarizeReadiness(items));
</script>

<template>
  <section class="readiness" aria-labelledby="readiness-heading">
    <h1 id="readiness-heading" class="readiness__heading">Device readiness</h1>
    <p class="readiness__lede">
      This checklist is only for you. It is advisory, is not shared with
      organizers, and does not expire. Preparing your device before an event is
      encouraged but not required.
    </p>
    <p class="readiness__summary" role="status">
      {{ summary.ready }} of {{ summary.total }} checks ready.
    </p>
    <ReadinessChecklist :items="items" />
  </section>
</template>

<style scoped>
.readiness {
  max-width: 40rem;
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
</style>
