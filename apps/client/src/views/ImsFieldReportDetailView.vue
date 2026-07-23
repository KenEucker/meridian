<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";

import {
  findFieldReportForSession,
  formatIncidentDateTime,
  hasIncidentCommandAccess,
  resolveIncidentSession,
  statusLabel,
} from "@/ims/incidentReadModel";

const route = useRoute();
const session = computed(() => resolveIncidentSession());
const canView = computed(() => hasIncidentCommandAccess(session.value));
const fieldReport = computed(() =>
  findFieldReportForSession(
    session.value,
    String(route.params.fieldReportId),
  ),
);
</script>

<template>
  <section class="ims-fr-detail" aria-labelledby="ims-fr-detail-heading">
    <div v-if="!canView" class="ims-fr-detail__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <div v-else-if="!fieldReport" class="ims-fr-detail__missing" role="status">
      Field Report not found for this event.
    </div>

    <template v-else>
      <nav class="ims-fr-detail__nav" aria-label="Field Report detail links">
        <RouterLink
          class="ims-fr-detail__back"
          :to="{ name: 'ims.field-reports.index' }"
        >
          Back to Field Reports
        </RouterLink>
        <RouterLink
          class="ims-fr-detail__secondary"
          :to="{ name: 'ims.incidents.index' }"
        >
          Incidents
        </RouterLink>
        <RouterLink class="ims-fr-detail__secondary" :to="{ name: 'home' }">
          Home
        </RouterLink>
      </nav>

      <header class="ims-fr-detail__header">
        <p class="ims-fr-detail__number">{{ fieldReport.displayNumber }}</p>
        <h1 id="ims-fr-detail-heading" class="ims-fr-detail__heading">
          {{ fieldReport.title }}
        </h1>
      </header>

      <section
        aria-labelledby="ims-fr-context-heading"
        class="ims-fr-detail__panel"
      >
        <h2 id="ims-fr-context-heading">Field Report</h2>
        <dl class="ims-fr-detail__definition">
          <div>
            <dt>Event</dt>
            <dd>{{ session?.eventLabel }}</dd>
          </div>
          <div>
            <dt>IC department</dt>
            <dd>{{ session?.icDepartmentLabel }}</dd>
          </div>
          <div>
            <dt>Author</dt>
            <dd>{{ fieldReport.authorName }}</dd>
          </div>
          <div>
            <dt>Submitted</dt>
            <dd>{{ formatIncidentDateTime(fieldReport.createdAt) }}</dd>
          </div>
          <div>
            <dt>Related incidents</dt>
            <dd
              v-if="fieldReport.relatedIncidents.length > 0"
              class="ims-fr-detail__incident-list"
            >
              <RouterLink
                v-for="incident in fieldReport.relatedIncidents"
                :key="incident.id"
                class="ims-fr-detail__incident-row"
                :to="{
                  name: 'ims.incidents.show',
                  params: { incidentId: incident.id },
                }"
              >
                <span>{{ incident.incidentNumber }}</span>
                <strong>{{ incident.title || "Untitled incident" }}</strong>
                <em>{{ statusLabel(incident.status) }}</em>
              </RouterLink>
            </dd>
            <dd v-else>Not linked</dd>
          </div>
        </dl>
      </section>

      <section
        aria-labelledby="ims-fr-body-heading"
        class="ims-fr-detail__panel"
      >
        <h2 id="ims-fr-body-heading">Body</h2>
        <p class="ims-fr-detail__body">{{ fieldReport.body }}</p>
      </section>
    </template>
  </section>
</template>

<style scoped>
.ims-fr-detail {
  box-sizing: border-box;
  width: min(100%, 76rem);
  margin-inline: auto;
  display: grid;
  gap: var(--m-space-4);
}

.ims-fr-detail *,
.ims-fr-detail *::before,
.ims-fr-detail *::after {
  box-sizing: border-box;
}

.ims-fr-detail__nav {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
}

.ims-fr-detail__back,
.ims-fr-detail__secondary,
.ims-fr-detail__restricted a {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}

.ims-fr-detail__restricted,
.ims-fr-detail__missing {
  color: var(--m-text-muted);
}

.ims-fr-detail__header {
  display: grid;
  gap: var(--m-space-2);
}

.ims-fr-detail__number,
.ims-fr-detail__heading,
.ims-fr-detail__body {
  margin: 0;
}

.ims-fr-detail__number {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-fr-detail__heading {
  font-family: var(--m-font-heading);
  font-size: clamp(var(--m-text-lg), 5vw, var(--m-text-xl));
  line-height: 1.15;
}

.ims-fr-detail__panel {
  padding: var(--m-space-5);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-fr-detail__panel h2 {
  margin: 0 0 var(--m-space-4);
  font-size: var(--m-text-md);
}

.ims-fr-detail__definition {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.ims-fr-detail__definition dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-fr-detail__definition dd {
  margin: var(--m-space-1) 0 0;
  overflow-wrap: anywhere;
}

.ims-fr-detail__incident-list {
  display: grid;
  gap: var(--m-space-2);
}

.ims-fr-detail__incident-row {
  display: grid;
  gap: var(--m-space-1);
  color: inherit;
  text-decoration: none;
}

.ims-fr-detail__incident-row:focus-visible {
  outline: 2px solid var(--m-focus-ring, var(--m-action-secondary-bg));
  outline-offset: 2px;
}

.ims-fr-detail__incident-row span {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-fr-detail__incident-row em {
  color: var(--m-text-muted);
  font-style: normal;
  font-size: var(--m-text-sm);
}

.ims-fr-detail__body {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  line-height: 1.5;
}
</style>
