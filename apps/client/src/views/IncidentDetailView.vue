<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";

import {
  findIncidentForSession,
  hasIncidentCommandAccess,
  resolveIncidentSession,
  statusLabel,
} from "@/ims/incidentReadModel";

const route = useRoute();
const session = computed(() => resolveIncidentSession());
const canView = computed(() => hasIncidentCommandAccess(session.value));
const incident = computed(() =>
  findIncidentForSession(session.value, String(route.params.incidentId)),
);

function priorityText(priorityLabel: string | null): string {
  return priorityLabel ?? "Priority not set";
}
</script>

<template>
  <section class="ims-detail" aria-labelledby="ims-detail-heading">
    <div v-if="!canView" class="ims-detail__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <div v-else-if="!incident" class="ims-detail__missing" role="status">
      Incident not found for this event.
    </div>

    <template v-else>
      <RouterLink class="ims-detail__back" :to="{ name: 'ims.incidents.index' }">
        Back to incidents
      </RouterLink>

      <header class="ims-detail__header">
        <p class="ims-detail__number">{{ incident.incidentNumber }}</p>
        <h1 id="ims-detail-heading" class="ims-detail__heading">
          {{ incident.title }}
        </h1>
        <dl class="ims-detail__status-row">
          <div>
            <dt>State</dt>
            <dd>{{ statusLabel(incident.status) }}</dd>
          </div>
          <div>
            <dt>Priority</dt>
            <dd>{{ priorityText(incident.priorityLabel) }}</dd>
          </div>
          <div>
            <dt>Started</dt>
            <dd>{{ incident.startedAt }}</dd>
          </div>
          <div>
            <dt>Last update</dt>
            <dd>{{ incident.updatedAt }}</dd>
          </div>
        </dl>
      </header>

      <div class="ims-detail__layout">
        <section aria-labelledby="ims-context-heading" class="ims-detail__panel">
          <h2 id="ims-context-heading">Current state</h2>
          <dl class="ims-detail__definition">
            <div>
              <dt>Event</dt>
              <dd>{{ session?.eventLabel }}</dd>
            </div>
            <div>
              <dt>IC department</dt>
              <dd>{{ session?.icDepartmentLabel }}</dd>
            </div>
            <div>
              <dt>Location</dt>
              <dd>{{ incident.locationName ?? "Location not set" }}</dd>
            </div>
            <div v-if="incident.locationAddress">
              <dt>Address</dt>
              <dd>{{ incident.locationAddress }}</dd>
            </div>
            <div v-if="incident.locationDetails">
              <dt>Location details</dt>
              <dd>{{ incident.locationDetails }}</dd>
            </div>
            <div>
              <dt>Created by</dt>
              <dd>{{ incident.createdByName ?? "Creator unavailable" }}</dd>
            </div>
          </dl>
        </section>

        <section
          aria-labelledby="ims-timeline-heading"
          class="ims-detail__panel"
        >
          <h2 id="ims-timeline-heading">Timeline</h2>
          <ol class="ims-detail__timeline">
            <li>
              <span>Incident opened</span>
              <time :datetime="incident.createdAt">{{ incident.createdAt }}</time>
            </li>
          </ol>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.ims-detail {
  width: min(100%, 72rem);
  display: grid;
  gap: var(--m-space-5);
}

.ims-detail__back,
.ims-detail__restricted a {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}

.ims-detail__restricted,
.ims-detail__missing {
  color: var(--m-text-muted);
}

.ims-detail__header {
  display: grid;
  gap: var(--m-space-3);
}

.ims-detail__number,
.ims-detail__heading {
  margin: 0;
}

.ims-detail__number {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-detail__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.ims-detail__status-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  gap: var(--m-space-3);
  margin: 0;
}

.ims-detail__status-row div,
.ims-detail__panel {
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-detail__status-row dt,
.ims-detail__definition dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-detail__status-row dd,
.ims-detail__definition dd {
  margin: var(--m-space-1) 0 0;
}

.ims-detail__layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--m-space-4);
}

.ims-detail__panel h2 {
  margin: 0 0 var(--m-space-3);
  font-size: var(--m-text-lg);
}

.ims-detail__definition {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.ims-detail__timeline {
  margin: 0;
  padding-left: var(--m-space-5);
}

.ims-detail__timeline li {
  margin-bottom: var(--m-space-2);
}

.ims-detail__timeline span {
  display: block;
  font-weight: 700;
}

.ims-detail__timeline time {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

@media (min-width: 56rem) {
  .ims-detail__layout {
    grid-template-columns: minmax(0, 1fr) minmax(20rem, 0.8fr);
  }
}
</style>
