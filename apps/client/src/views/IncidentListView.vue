<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import {
  canEditIncident,
  formatIncidentDateTime,
  hasIncidentCommandAccess,
  listIncidentsForSession,
  resolveIncidentSession,
  statusLabel,
  type IncidentPriorityLabel,
} from "@/ims/incidentReadModel";

const session = computed(() => resolveIncidentSession());
const canView = computed(() => hasIncidentCommandAccess(session.value));
const canEdit = computed(() => canEditIncident(session.value));
const route = useRoute();
const router = useRouter();
const searchQuery = computed(() =>
  typeof route.query.search === "string" ? route.query.search : "",
);
const searchDraft = ref(searchQuery.value);
const incidents = computed(() =>
  listIncidentsForSession(session.value, searchQuery.value),
);

watch(searchQuery, (value) => {
  searchDraft.value = value;
});

function priorityText(priorityLabel: string | null): string {
  return priorityLabel ?? "Priority not set";
}

function priorityClass(priorityLabel: IncidentPriorityLabel): string {
  return `ims-list__priority--${priorityLabel.toLowerCase()}`;
}

function typeText(typeNames: readonly string[]): string {
  return typeNames.length > 0 ? typeNames.join(", ") : "Types not set";
}

async function onSearchSubmit(): Promise<void> {
  const search = searchDraft.value.trim();

  await router.push({
    name: "ims.incidents.index",
    query: search ? { search } : {},
  });
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
      <RouterLink
        v-if="canEdit"
        class="ims-list__create"
        :to="{ name: 'ims.incidents.create' }"
      >
        Create incident
      </RouterLink>
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

    <template v-else>
      <form
        class="ims-list__search-form"
        aria-label="Search incidents"
        @submit.prevent="onSearchSubmit"
      >
        <label for="ims-list-search">Search</label>
        <input
          id="ims-list-search"
          v-model="searchDraft"
          type="search"
          autocomplete="off"
        />
        <button type="submit">Search</button>
        <RouterLink
          v-if="searchQuery"
          class="ims-list__clear-search"
          :to="{ name: 'ims.incidents.index' }"
        >
          Clear
        </RouterLink>
      </form>

      <p
        v-if="incidents.length === 0"
        class="ims-list__empty"
        role="status"
      >
        {{
          searchQuery
            ? "No incidents match this search."
            : "No incidents are recorded for this event."
        }}
      </p>

      <div v-else class="ims-list__results">
        <div
          v-if="searchQuery"
          class="ims-list__search-context"
          role="status"
        >
          <span>Search: {{ searchQuery }}</span>
          <RouterLink :to="{ name: 'ims.incidents.index' }">Clear</RouterLink>
        </div>

        <div class="ims-list__table-wrap">
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
              <th scope="col">Types</th>
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
              <td>
                <span
                  class="ims-list__priority"
                  :class="priorityClass(incident.priorityLabel)"
                >
                  {{ priorityText(incident.priorityLabel) }}
                </span>
              </td>
              <td>{{ typeText(incident.incidentTypeNames) }}</td>
              <td>{{ incident.locationName ?? "Location not set" }}</td>
              <td>{{ formatIncidentDateTime(incident.updatedAt) }}</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </template>
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

.ims-list__create {
  justify-self: start;
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font-weight: 800;
  text-decoration: none;
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
.ims-list__incident-link,
.ims-list__search-context a,
.ims-list__clear-search {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}

.ims-list__create:focus-visible,
.ims-list__search-form input:focus-visible,
.ims-list__search-form button:focus-visible,
.ims-list__clear-search:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-list__search-form {
  display: grid;
  grid-template-columns: minmax(12rem, 1fr) auto auto;
  gap: var(--m-space-2);
  align-items: end;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-list__search-form label {
  grid-column: 1 / -1;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-list__search-form input {
  min-width: 0;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-3);
  background: var(--m-surface-primary);
  color: var(--m-text-primary);
  font: inherit;
}

.ims-list__search-form button {
  border: 0;
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font-weight: 800;
}

.ims-list__clear-search {
  align-self: center;
}

.ims-list__results {
  display: grid;
  gap: var(--m-space-3);
}

.ims-list__search-context {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
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

.ims-list__priority {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-list__priority--routine {
  background: color-mix(in srgb, var(--m-border-default) 22%, transparent);
  color: var(--m-text-secondary);
}

.ims-list__priority--important {
  border-color: #facc15;
  background: #fef08a;
  color: #3f3000;
}

.ims-list__priority--serious {
  border-color: #c05621;
  background: #fed7aa;
  color: #7c2d12;
}

.ims-list__priority--critical {
  border-color: #b42318;
  background: #f04438;
  color: #ffffff;
}

.ims-list__status {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  color: var(--m-text-primary);
}

@media (max-width: 43.99rem) {
  .ims-list__search-form {
    grid-template-columns: 1fr;
  }

  .ims-list__clear-search {
    justify-self: start;
  }

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
