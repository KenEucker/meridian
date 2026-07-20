<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import {
  formatIncidentDateTime,
  hasIncidentCommandAccess,
  listFieldReportsForSession,
  resolveIncidentSession,
  statusLabel,
  type ImsFieldReportListItem,
  type IncidentPriorityLabel,
} from "@/ims/incidentReadModel";

const route = useRoute();
const router = useRouter();
const session = computed(() => resolveIncidentSession());
const canView = computed(() => hasIncidentCommandAccess(session.value));
const stateFilter = computed(() =>
  typeof route.query.state === "string" ? route.query.state : "active",
);
const priorityFilter = computed(() =>
  typeof route.query.priority === "string" ? route.query.priority : "all",
);
const linkFilter = computed(() =>
  typeof route.query.link === "string" ? route.query.link : "all",
);
const sortKey = computed(() =>
  typeof route.query.sort === "string" ? route.query.sort : "submitted",
);
const sortDirection = computed(() =>
  route.query.direction === "asc" ? "asc" : "desc",
);

const reports = computed(() =>
  listFieldReportsForSession(session.value)
    .filter((report) => matchesLinkFilter(report, linkFilter.value))
    .filter((report) => matchesRelatedStateFilter(report, stateFilter.value))
    .filter((report) =>
      matchesRelatedPriorityFilter(report, priorityFilter.value),
    )
    .sort(compareReports),
);

function relatedIncidentText(report: ImsFieldReportListItem): string {
  if (report.relatedIncidents.length === 0) {
    return "Not linked";
  }

  return report.relatedIncidents
    .map((incident) => `${incident.incidentNumber}: ${incident.title}`)
    .join(", ");
}

function relatedStateText(report: ImsFieldReportListItem): string {
  const labels = new Set(
    report.relatedIncidents.map((incident) => statusLabel(incident.status)),
  );

  return labels.size > 0 ? [...labels].join(", ") : "Not linked";
}

function relatedPriorityText(report: ImsFieldReportListItem): string {
  const labels = new Set(
    report.relatedIncidents.map((incident) => incident.priorityLabel),
  );

  return labels.size > 0 ? [...labels].join(", ") : "Not linked";
}

function matchesRelatedStateFilter(
  report: ImsFieldReportListItem,
  filter: string,
): boolean {
  if (filter === "all") {
    return true;
  }

  if (report.relatedIncidents.length === 0) {
    return filter === "active";
  }

  if (filter === "active") {
    return report.relatedIncidents.some(
      (incident) => incident.status !== "closed",
    );
  }

  return report.relatedIncidents.some((incident) => incident.status === filter);
}

function matchesRelatedPriorityFilter(
  report: ImsFieldReportListItem,
  filter: string,
): boolean {
  if (filter === "all") {
    return true;
  }

  return report.relatedIncidents.some(
    (incident) => incident.priorityLabel === filter,
  );
}

function matchesLinkFilter(
  report: ImsFieldReportListItem,
  filter: string,
): boolean {
  if (filter === "linked") {
    return report.relatedIncidents.length > 0;
  }

  if (filter === "not_linked") {
    return report.relatedIncidents.length === 0;
  }

  return true;
}

function activeSortDirection(key: string): "ascending" | "descending" | "none" {
  if (sortKey.value !== key) {
    return "none";
  }

  return sortDirection.value === "asc" ? "ascending" : "descending";
}

function nextSortQuery(key: string) {
  const nextDirection =
    sortKey.value === key && sortDirection.value === "asc" ? "desc" : "asc";

  return {
    ...route.query,
    sort: key,
    direction: nextDirection,
  };
}

function onStateFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.field-reports.index",
    query: { ...route.query, state: target.value },
  });
}

function onPriorityFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.field-reports.index",
    query: { ...route.query, priority: target.value },
  });
}

function onLinkFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.field-reports.index",
    query: { ...route.query, link: target.value },
  });
}

function compareText(left: string, right: string): number {
  return left.localeCompare(right, undefined, { sensitivity: "base" });
}

function prioritySortValue(priority: IncidentPriorityLabel): number {
  return {
    Critical: 0,
    Serious: 1,
    Important: 2,
    Routine: 3,
  }[priority];
}

function firstRelatedPrioritySortValue(report: ImsFieldReportListItem): number {
  return Math.min(
    ...report.relatedIncidents.map((incident) =>
      prioritySortValue(incident.priorityLabel),
    ),
    99,
  );
}

function compareReports(
  left: ImsFieldReportListItem,
  right: ImsFieldReportListItem,
): number {
  const direction = sortDirection.value === "asc" ? 1 : -1;
  let result = 0;

  switch (sortKey.value) {
    case "report":
      result =
        compareText(left.displayNumber, right.displayNumber) ||
        compareText(left.title, right.title);
      break;
    case "author":
      result = compareText(left.authorName, right.authorName);
      break;
    case "state":
      result = compareText(relatedStateText(left), relatedStateText(right));
      break;
    case "priority":
      result =
        firstRelatedPrioritySortValue(left) -
        firstRelatedPrioritySortValue(right);
      break;
    case "incidents":
      result = compareText(relatedIncidentText(left), relatedIncidentText(right));
      break;
    case "submitted":
    default:
      result = left.createdAt.localeCompare(right.createdAt);
      break;
  }

  return result === 0
    ? right.createdAt.localeCompare(left.createdAt)
    : result * direction;
}
</script>

<template>
  <section class="ims-fr-list" aria-labelledby="ims-field-reports-heading">
    <header class="ims-fr-list__header">
      <div>
        <p class="ims-fr-list__eyebrow">Incident Management System</p>
        <h1 id="ims-field-reports-heading" class="ims-fr-list__heading">
          Field Reports
        </h1>
      </div>
      <nav class="ims-fr-list__links" aria-label="Field Report list links">
        <RouterLink class="ims-fr-list__secondary-link" :to="{ name: 'home' }">
          Home
        </RouterLink>
        <RouterLink
          class="ims-fr-list__secondary-link"
          :to="{ name: 'ims.incidents.index' }"
        >
          Incidents
        </RouterLink>
      </nav>
    </header>

    <div v-if="!canView" class="ims-fr-list__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <template v-else>
      <form class="ims-fr-list__filters" aria-label="Filter Field Reports">
        <label for="ims-fr-list-link">Link status</label>
        <select
          id="ims-fr-list-link"
          :value="linkFilter"
          @change="onLinkFilterChange"
        >
          <option value="all">All reports</option>
          <option value="linked">Linked</option>
          <option value="not_linked">Not linked</option>
        </select>

        <label for="ims-fr-list-state">Related state</label>
        <select
          id="ims-fr-list-state"
          :value="stateFilter"
          @change="onStateFilterChange"
        >
          <option value="active">Active states</option>
          <option value="open">Open</option>
          <option value="on_scene">On Scene</option>
          <option value="monitoring">Monitoring</option>
          <option value="on_hold">On Hold</option>
          <option value="closed">Closed</option>
          <option value="all">All states</option>
        </select>

        <label for="ims-fr-list-priority">Related priority</label>
        <select
          id="ims-fr-list-priority"
          :value="priorityFilter"
          @change="onPriorityFilterChange"
        >
          <option value="all">All priorities</option>
          <option value="Critical">Critical</option>
          <option value="Serious">Serious</option>
          <option value="Important">Important</option>
          <option value="Routine">Routine</option>
        </select>
      </form>

      <p v-if="reports.length === 0" class="ims-fr-list__empty" role="status">
        No Field Reports match these filters.
      </p>

      <div v-else class="ims-fr-list__table-wrap">
        <table class="ims-fr-list__table">
          <caption>
            Restricted Field Report list for the configured Incident Command
            department.
          </caption>
          <thead>
            <tr>
              <th scope="col" :aria-sort="activeSortDirection('report')">
                <RouterLink
                  :to="{
                    name: 'ims.field-reports.index',
                    query: nextSortQuery('report'),
                  }"
                >
                  Field Report
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('author')">
                <RouterLink
                  :to="{
                    name: 'ims.field-reports.index',
                    query: nextSortQuery('author'),
                  }"
                >
                  Author
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('state')">
                <RouterLink
                  :to="{
                    name: 'ims.field-reports.index',
                    query: nextSortQuery('state'),
                  }"
                >
                  Related state
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('priority')">
                <RouterLink
                  :to="{
                    name: 'ims.field-reports.index',
                    query: nextSortQuery('priority'),
                  }"
                >
                  Related priority
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('incidents')">
                <RouterLink
                  :to="{
                    name: 'ims.field-reports.index',
                    query: nextSortQuery('incidents'),
                  }"
                >
                  Incidents
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('submitted')">
                <RouterLink
                  :to="{
                    name: 'ims.field-reports.index',
                    query: nextSortQuery('submitted'),
                  }"
                >
                  Submitted
                </RouterLink>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="report in reports" :key="report.id">
              <th scope="row">
                <span class="ims-fr-list__number">{{
                  report.displayNumber
                }}</span>
                <span class="ims-fr-list__title">{{ report.title }}</span>
              </th>
              <td>{{ report.authorName }}</td>
              <td>{{ relatedStateText(report) }}</td>
              <td>{{ relatedPriorityText(report) }}</td>
              <td>
                <span v-if="report.relatedIncidents.length === 0">Not linked</span>
                <span v-else class="ims-fr-list__incident-links">
                  <RouterLink
                    v-for="incident in report.relatedIncidents"
                    :key="incident.id"
                    :to="{
                      name: 'ims.incidents.show',
                      params: { incidentId: incident.id },
                    }"
                  >
                    {{ incident.incidentNumber }}
                  </RouterLink>
                </span>
              </td>
              <td>{{ formatIncidentDateTime(report.createdAt) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.ims-fr-list {
  width: min(100%, 72rem);
  display: grid;
  gap: var(--m-space-5);
}

.ims-fr-list__header {
  display: grid;
  gap: var(--m-space-4);
}

.ims-fr-list__eyebrow,
.ims-fr-list__heading,
.ims-fr-list__empty {
  margin: 0;
}

.ims-fr-list__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
}

.ims-fr-list__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.ims-fr-list__links,
.ims-fr-list__incident-links {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.ims-fr-list__secondary-link {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-3);
  color: var(--m-action-secondary-bg);
  font-weight: 800;
  text-decoration: none;
}

.ims-fr-list__restricted,
.ims-fr-list__empty {
  color: var(--m-text-muted);
}

.ims-fr-list__restricted a,
.ims-fr-list__table a {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}

.ims-fr-list__secondary-link:focus-visible,
.ims-fr-list__filters select:focus-visible,
.ims-fr-list__table a:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-fr-list__filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: var(--m-space-2);
  align-items: end;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-fr-list__filters label {
  align-self: center;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-fr-list__filters select {
  min-width: 0;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-3);
  background: var(--m-surface-primary);
  color: var(--m-text-primary);
  font: inherit;
}

.ims-fr-list__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-fr-list__table {
  width: 100%;
  min-width: 52rem;
  border-collapse: collapse;
}

.ims-fr-list__table caption {
  padding: var(--m-space-3);
  color: var(--m-text-secondary);
  text-align: left;
}

.ims-fr-list__table th,
.ims-fr-list__table td {
  padding: var(--m-space-3);
  border-top: 1px solid var(--m-border-default);
  text-align: left;
  vertical-align: top;
}

.ims-fr-list__table thead th {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.ims-fr-list__table thead a {
  text-decoration: none;
}

.ims-fr-list__number,
.ims-fr-list__title {
  display: block;
}

.ims-fr-list__number {
  color: var(--m-action-secondary-bg);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-fr-list__title {
  margin-top: var(--m-space-1);
  font-weight: 700;
}

@media (max-width: 43.99rem) {
  .ims-fr-list__filters {
    grid-template-columns: 1fr;
  }

  .ims-fr-list__table-wrap {
    border: 0;
    background: transparent;
  }

  .ims-fr-list__table {
    min-width: 0;
    border-collapse: separate;
    border-spacing: 0 var(--m-space-3);
  }

  .ims-fr-list__table caption,
  .ims-fr-list__table thead {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }

  .ims-fr-list__table tr {
    display: grid;
    gap: var(--m-space-2);
    padding: var(--m-space-3);
    border: 1px solid var(--m-border-default);
    border-radius: var(--m-radius-sm);
    background: var(--m-surface-raised);
  }

  .ims-fr-list__table th,
  .ims-fr-list__table td {
    padding: 0;
    border-top: 0;
  }
}
</style>
