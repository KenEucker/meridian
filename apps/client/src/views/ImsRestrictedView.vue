<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import { incidentSessionContext } from "@/ims/incidentReadModel";

const context = computed(() => incidentSessionContext.value);
</script>

<template>
  <section class="ims-restricted" aria-labelledby="ims-restricted-heading">
    <p class="ims-restricted__eyebrow">Restricted access</p>
    <h1 id="ims-restricted-heading" class="ims-restricted__heading">
      Incident Command access required
    </h1>
    <p class="ims-restricted__message">
      This page requires IC Viewer, IC Operator, or IC Lead access for the
      event's configured Incident Command department.
    </p>
    <dl v-if="context" class="ims-restricted__context">
      <div>
        <dt>Event</dt>
        <dd>{{ context.eventLabel }}</dd>
      </div>
      <div>
        <dt>Configured IC department</dt>
        <dd>{{ context.icDepartmentLabel }}</dd>
      </div>
      <div>
        <dt>Current role</dt>
        <dd>{{ context.roleLabel }}</dd>
      </div>
    </dl>
    <RouterLink class="ims-restricted__return" :to="{ name: 'home' }">
      Return home
    </RouterLink>
  </section>
</template>

<style scoped>
.ims-restricted {
  width: var(--m-content-narrow);
  display: grid;
  gap: var(--m-space-4);
}

.ims-restricted__eyebrow,
.ims-restricted__heading,
.ims-restricted__message {
  margin: 0;
}

.ims-restricted__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
}

.ims-restricted__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.ims-restricted__message {
  color: var(--m-text-muted);
}

.ims-restricted__context {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-restricted__context div {
  display: grid;
  gap: var(--m-space-1);
}

.ims-restricted__context dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-restricted__context dd {
  margin: 0;
}

.ims-restricted__return {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}
</style>
