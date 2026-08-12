<script setup lang="ts">
/*
 * Where an address owned by an inactive module lands (M19.16; MOD-013,
 * MOD-015, MOD-022; technical spec 15A.5, 15A.8).
 *
 * Not a permission-denied surface, and the difference is the point. A denied
 * surface names the role that would open it, because there is one and somebody
 * holds it. There is no role here: the organization does not run the module, so
 * the capability is absent for every member including whoever the reader would
 * have asked. Saying "you do not have access" would send them looking for a
 * grant nobody can give.
 *
 * Not a not-found page either. The address is a real Meridian address and the
 * reader may well have arrived from a link somebody sent them, so the honest
 * answer is what their organization runs rather than a page that pretends the
 * surface never existed. The node's own refusal takes the same position: it
 * answers `404` and names the module, because module state is an organization's
 * own configuration and not a secret from its members.
 *
 * The module is read from the route rather than from anything held in memory,
 * so the sentence survives a reload of this address on a device with no signal.
 */
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";

import { moduleAbsenceCopy } from "@/session/sessionModules";

const route = useRoute();

const copy = computed(() =>
  moduleAbsenceCopy(
    typeof route.params.moduleKey === "string" ? route.params.moduleKey : "",
  ),
);
</script>

<template>
  <section class="module-unavailable" aria-labelledby="module-unavailable-heading">
    <p class="module-unavailable__eyebrow">Not part of this organization</p>
    <h1 id="module-unavailable-heading" class="module-unavailable__heading">
      {{ copy.heading }}
    </h1>
    <p class="module-unavailable__message">{{ copy.message }}</p>
    <p class="module-unavailable__resolution">{{ copy.resolution }}</p>
    <RouterLink class="module-unavailable__return" :to="{ name: 'home' }">
      Return home
    </RouterLink>
  </section>
</template>

<style scoped>
.module-unavailable {
  width: var(--m-content-narrow);
  display: grid;
  gap: var(--m-space-4);
}

.module-unavailable__eyebrow,
.module-unavailable__heading,
.module-unavailable__message,
.module-unavailable__resolution {
  margin: 0;
}

.module-unavailable__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
}

.module-unavailable__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.module-unavailable__message,
.module-unavailable__resolution {
  color: var(--m-text-muted);
}

.module-unavailable__return {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}
</style>
