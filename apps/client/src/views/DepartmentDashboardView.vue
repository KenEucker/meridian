<script setup lang="ts">
import { computed } from "vue";

import DashboardSection from "@/components/sections/DashboardSection.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  selectedSessionDepartment,
  sessionEventContext,
} from "@/session/sessionAccess";

/**
 * `department.dashboard` — the department operational home (M18.28; UI contract
 * 12.4, 13.2, 13.3; dashboard widget spec 6.2, 6.3).
 *
 * Two groups, in the order the contract lists them: what is true of the
 * department, then what is true of the shift in front of the desk. They are
 * separate because they answer to separate standing — a department lead reads
 * coverage and readiness, a `department_logistics` holder reads check-in and
 * equipment — and somebody holding one and not the other sees one region rather
 * than an empty half of a page.
 *
 * The department comes from the route, the way every department surface resolves
 * it. What the reader may see in it is the node's answer, and a group they hold
 * nothing in does not come back at all.
 */
const event = computed(() => sessionEventContext.value);
const department = computed(() => selectedSessionDepartment.value);
</script>

<template>
  <WorkflowPageShell
    heading-id="department-dashboard-heading"
    title="Dashboard"
    :eyebrow="department?.departmentLabel ?? 'Department'"
    :lede="event?.eventLabel ?? ''"
  >
    <p v-if="!event || !department" class="department-dashboard__state" role="status">
      This device has not resolved an event and a department, so there is no
      department dashboard to show.
    </p>

    <DashboardSection
      v-else
      :event-id="event.eventId"
      :department-id="department.departmentId"
      :groups="['department_lead', 'department_operations']"
      unavailable-message="You hold no department standing here, so there is no department dashboard for you in this department."
    />
  </WorkflowPageShell>
</template>

<style scoped>
.department-dashboard__state {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}
</style>
