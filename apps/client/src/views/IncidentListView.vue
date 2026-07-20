<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import {
  hasIncidentCommandAccess,
  listIncidentsForSession,
  resolveIncidentSession,
  statusLabel,
} from "@/ims/incidentReadModel";

const session = computed(() => resolveIncidentSession());
const canView = computed(() => hasIncidentCommandAccess(session.value));
const incidents = computed(() => listIncidentsForSession(session.value));

function priorityText(priorityLabel: string | null): string {
  return priorityLabel ?? "Priority not set";
}
</script>

<template>
  <section class="ims-list" aria-labelledby="ims-incidents-heading">
    <header class="ims-list__header">
      <div>
        <p class="ims-list__eyebrow">Incident Management System</p>
        <h1 id="ims-incidents-heading" class="ims-list__heading">
          Incidents
        </h1>
      </div>
      <dl v-if="session" class="ims-list__context">
        <div>
          <dt>Organization</dt>
          <dd>{{ session.organizationLabel }}</dd>
        </div>
        <div>
          <dt>Event</dt>
          <dd>{{ session.eventLabel }}</dd>
        </div>
        <div>
          <dt>IC department</dt>
          <dd>{{ session.icDepartmentLabel }}</dd>
        </div>
        <div>
          <dt>Role</dt>
          <dd>{{ session.roleLabel }}</dd>
        </div>
      </dl>
    </header>

    <div v-if="!canView" class="ims-list__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <p
      v-else-if="incidents.length === 0"
      class="ims-list__empty"
      role="status"
    >
      No incidents are recorded for this event.
    </p>

    <div v-else class="ims-list__table-wrap">
      <table class="ims-list__table">
        <caption>
          Restricted incident list for the configured Incident Command
          department.
        </caption>
        <thead>
          <tr>
            <th scope="col">Incident</th>
            <th scope="col">State</th>
            <th scope="col">Priority</th>
            <th scope="col">Location</th>
            <th scope="col">Last update</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="incident in incidents" :key="incident.id">
            <th scope="row">
              <RouterLink
                class="ims-list__incident-link"
                :to="{
                  name: 'ims.incidents.show',
                  params: { incidentId: incident.id },
                }"
              >
                <span>{{ incident.incidentNumber }}</span>
                <span>{{ incident.title }}</span>
              </RouterLink>
            </th>
            <td>
              <span class="ims-list__status">{{
                statusLabel(incident.status)
              }}</span>
            </td>
            <td>{{ priorityText(incident.priorityLabel) }}</td>
            <td>{{ incident.locationName ?? "Location not set" }}</td>
            <td>{{ incident.updatedAt }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.ims-list {
  width: min(100%, 72rem);
  display: grid;
  gap: var(--m-space-5);
}

.ims-list__header {
  display: grid;
  gap: var(--m-space-4);
}

.ims-list__eyebrow,
.ims-list__heading,
.ims-list__empty {
  margin: 0;
}

.ims-list__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
}

.ims-list__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.ims-list__context {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: var(--m-space-3);
  margin: 0;
}

.ims-list__context div {
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-list__context dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__context dd {
  margin: var(--m-space-1) 0 0;
}

.ims-list__restricted,
.ims-list__empty {
  color: var(--m-text-muted);
}

.ims-list__restricted a,
.ims-list__incident-link {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}

.ims-list__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-list__table {
  width: 100%;
  min-width: 48rem;
  border-collapse: collapse;
}

.ims-list__table caption {
  padding: var(--m-space-3);
  color: var(--m-text-secondary);
  text-align: left;
}

.ims-list__table th,
.ims-list__table td {
  padding: var(--m-space-3);
  border-top: 1px solid var(--m-border-default);
  text-align: left;
  vertical-align: top;
}

.ims-list__table thead th {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.ims-list__incident-link {
  display: grid;
  gap: var(--m-space-1);
  text-decoration: none;
}

.ims-list__incident-link span:first-child,
.ims-list__status {
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__status {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  color: var(--m-text-primary);
}

@media (max-width: 43.99rem) {
  .ims-list__table-wrap {
    border: 0;
    background: transparent;
  }

  .ims-list__table {
    min-width: 0;
    border-collapse: separate;
    border-spacing: 0 var(--m-space-3);
  }

  .ims-list__table caption,
  .ims-list__table thead {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }

  .ims-list__table tr {
    display: grid;
    gap: var(--m-space-2);
    padding: var(--m-space-3);
    border: 1px solid var(--m-border-default);
    border-radius: var(--m-radius-sm);
    background: var(--m-surface-raised);
  }

  .ims-list__table th,
  .ims-list__table td {
    padding: 0;
    border-top: 0;
  }
}
</style>
