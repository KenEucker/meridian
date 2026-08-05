<script setup lang="ts">
import { computed } from "vue";

import DashboardSection from "@/components/sections/DashboardSection.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import { sessionEventContext } from "@/session/sessionAccess";

/**
 * `organizer.dashboard` — the organization and event readiness dashboard
 * (M18.28; UI contract 12.6, 13.4; dashboard widget spec 6.4).
 *
 * Readiness gaps, coverage across departments, applications waiting on a
 * decision, outstanding organization documents, and where the event stands
 * against its own operations window.
 *
 * What is not here is the point of the page as much as what is. UI contract 13.4
 * and 12.6 both say organizer screens must not surface IMS incidents, restricted
 * Field Reports, incident counts, or incident priority alerts unless the same
 * person also holds IC team-granted authority — and this surface asks the node
 * for the organizer group alone. An organizer who does hold IC standing reads
 * incident data on `ims.dashboard`, as an IC user, which is what the exclusion's
 * "unless" means: the authority arrives through the Incident Command department,
 * never through organizing.
 */
const event = computed(() => sessionEventContext.value);
</script>

<template>
  <WorkflowPageShell
    heading-id="organizer-dashboard-heading"
    title="Organizer dashboard"
    :eyebrow="event?.eventLabel ?? 'No event selected'"
    lede="Readiness, coverage, and what is waiting on a decision."
  >
    <p v-if="!event" class="organizer-dashboard__state" role="status">
      This device has not resolved an event, so there is no readiness to report.
    </p>

    <DashboardSection
      v-else
      :event-id="event.eventId"
      :groups="['organizer']"
      unavailable-message="Organizer standing in this event's organization is what opens this dashboard, and this session holds none."
    />
  </WorkflowPageShell>
</template>

<style scoped>
.organizer-dashboard__state {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}
</style>
