<script setup lang="ts">
import { computed } from "vue";

import DashboardSection from "@/components/sections/DashboardSection.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import { sessionEventContext } from "@/session/sessionAccess";

/**
 * `staff.dashboard` — the staff task dashboard (M18.28; UI contract 12.3, 13.1;
 * dashboard widget spec 6.1).
 *
 * What this person has on now, next, and outstanding. Every widget is about
 * their own record, and the page's best day is the one where all of them are
 * quiet and the reassurance is the only thing on it — widget spec 14, "quiet
 * states are desirable".
 *
 * It is deliberately not the Event Horizon. The Horizon states what is
 * outstanding in preparation for the event and in what order; this offers entry
 * points into work somebody might do now. UI contract 19C is explicit that
 * surfaces must not label one as the other, and that dashboard widgets must not
 * be reused there to fill space.
 */
const event = computed(() => sessionEventContext.value);
</script>

<template>
  <WorkflowPageShell
    heading-id="staff-dashboard-heading"
    title="Dashboard"
    :eyebrow="event?.eventLabel ?? 'No event selected'"
    lede="What needs you now, and what is coming."
  >
    <!--
      Every staff widget is event-scoped, so with no event resolved there is
      nothing to compile rather than an empty dashboard to render.
    -->
    <p v-if="!event" class="staff-dashboard__state" role="status">
      This device has not resolved an event, so there is no dashboard to show.
      Choose an event and this page will fill in.
    </p>

    <DashboardSection
      v-else
      :event-id="event.eventId"
      :groups="['staff']"
      unavailable-message="You are not staff of this event's organization, so there is no staff dashboard here."
    />
  </WorkflowPageShell>
</template>

<style scoped>
.staff-dashboard__state {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}
</style>
