<script setup lang="ts">
import { computed } from "vue";

import DashboardSection from "@/components/sections/DashboardSection.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import { sessionEventContext } from "@/session/sessionAccess";

/**
 * `ims.dashboard` — the IMS attention dashboard (M18.28; UI contract 12.7, 13.5;
 * dashboard widget spec 6.5).
 *
 * Open incidents, the serious ones, what is on scene, what is being monitored,
 * and Field Reports nobody has linked yet.
 *
 * Three vocabularies sit on this page at once and the contract keeps them apart:
 * incident **state** is where an incident is in its workflow, IMS **priority**
 * is how serious it is, and dashboard **attention** is how hard the interface
 * should pull. Each card carries its attention level as a word and reports
 * priority inside itself, so neither is ever read as the other.
 *
 * The gate is the node's. IC standing resolves through the event's configured
 * Incident Command department, and a reader without it gets no IC group back —
 * the same answer the incident list gives, from the same resolver. This surface
 * says so and offers no incident data of its own.
 */
const event = computed(() => sessionEventContext.value);
</script>

<template>
  <WorkflowPageShell
    heading-id="ims-dashboard-heading"
    title="Incident Command dashboard"
    :eyebrow="event?.eventLabel ?? 'No event selected'"
    lede="What is open, what is serious, and what is still unlinked."
  >
    <p v-if="!event" class="ims-dashboard__state" role="status">
      This device has not resolved an event, so there are no incidents to report
      on.
    </p>

    <DashboardSection
      v-else
      :event-id="event.eventId"
      :groups="['ic']"
      unavailable-message="This dashboard requires IC Viewer, IC Operator, or IC Lead standing for the event's configured Incident Command department. Organizer or department lead standing does not reach it."
    />
  </WorkflowPageShell>
</template>

<style scoped>
.ims-dashboard__state {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}
</style>
