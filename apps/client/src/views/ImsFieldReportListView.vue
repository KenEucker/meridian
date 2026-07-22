<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import WorkflowHeadingCard from "@/components/WorkflowHeadingCard.vue";
import WorkflowHeadingCardGrid from "@/components/WorkflowHeadingCardGrid.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import { LOCAL_PLANNING_TABLE } from "@/department-ops/fixtures";
import { selectedFixtureDepartment } from "@/department-teams/fixtureDepartmentAccess";
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
const canView = computed(
  () =>
    selectedFixtureDepartment.value.capabilities.hasIncidentCommand &&
    hasIncidentCommandAccess(session.value),
);
const stateFilter = computed(() =>
  typeof route.query.state === "string" ? route.query.state : "active",
);
const priorityFilter = computed(() =>
  typeof route.query.priority === "string" ? route.query.priority : "all",
);
const linkFilter = computed(() =>
  typeof route.query.link === "string" ? route.query.link : "all",
);
const shiftFilter = computed(() =>
  route.query.shift === "current" ? "current" : "all",
);
const sortKey = computed(() =>
  typeof route.query.sort === "string" ? route.query.sort : "submitted",
);
const sortDirection = computed(() =>
  route.query.direction === "asc" ? "asc" : "desc",
);

const allReports = computed(() => listFieldReportsForSession(session.value));
const reports = computed(() =>
  allReports.value
    .filter((report) => matchesLinkFilter(report, linkFilter.value))
    .filter((report) => matchesShiftFilter(report, shiftFilter.value))
    .filter((report) => matchesRelatedStateFilter(report, stateFilter.value))
    .filter((report) =>
      matchesRelatedPriorityFilter(report, priorityFilter.value),
    )
    .sort(compareReports),
);
const reportCards = computed(() => [
  {
    label: "Event total",
    value: allReports.value.length,
    detail: "Field Reports submitted for this event",
  },
  {
    label: "This shift",
    value: allReports.value.filter((report) =>
      matchesShiftFilter(report, "current"),
    ).length,
    detail: "Submitted during the active shift",
  },
  {
    label: "Linked",
    value: allReports.value.filter((report) => report.relatedIncidents.length > 0)
      .length,
    detail: "Connected to incidents",
  },
  {
    label: "Unlinked",
    value: allReports.value.filter(
      (report) => report.relatedIncidents.length === 0,
    ).length,
    detail: "Awaiting incident linkage",
  },
]);

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

function activeShiftWindow():
  | { readonly startsAt: number; readonly endsAt: number }
  | null {
  const activeShift =
    LOCAL_PLANNING_TABLE.rows.find((row) => row.lifecycle === "active") ?? null;

  if (!activeShift) {
    return null;
  }

  const startsAt = Date.parse(activeShift.startsAt);
  const endsAt = Date.parse(activeShift.endsAt);

  if (Number.isNaN(startsAt) || Number.isNaN(endsAt)) {
    return null;
  }

  return { startsAt, endsAt };
}

function matchesShiftFilter(
  report: ImsFieldReportListItem,
  filter: string,
): boolean {
  if (filter !== "current") {
    return true;
  }

  const shiftWindow = activeShiftWindow();
  const createdAt = Date.parse(report.createdAt);

  if (!shiftWindow || Number.isNaN(createdAt)) {
    return false;
  }

  return createdAt >= shiftWindow.startsAt && createdAt <= shiftWindow.endsAt;
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

function onShiftFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.field-reports.index",
    query: {
      ...route.query,
      shift: target.value === "current" ? "current" : undefined,
    },
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
  <WorkflowPageShell
    class="ims-fr-list"
    heading-id="ims-field-reports-heading"
    title="Field Reports"
    :eyebrow="session?.icDepartmentLabel ?? 'Incident Command'"
    lede="Restricted Field Reports available to Incident Command."
  >
    <template #navigation>
      <RouterLink
        v-if="canView"
        class="ims-fr-list__secondary-link"
        :to="{ name: 'ims.incidents.index' }"
      >
        Incidents
      </RouterLink>
    </template>

    <template #heading-cards>
      <WorkflowHeadingCardGrid v-if="canView">
        <WorkflowHeadingCard
          v-for="card in reportCards"
          :key="card.label"
          :label="card.label"
          :value="card.value"
          :detail="card.detail"
        />
      </WorkflowHeadingCardGrid>
    </template>

    <div v-if="!canView" class="ims-fr-list__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <template v-else>
      <form class="ims-fr-list__filters" aria-label="Filter Field Reports">
        <label for="ims-fr-list-link">
          <span>Link status</span>
          <select
            id="ims-fr-list-link"
            :value="linkFilter"
            @change="onLinkFilterChange"
          >
            <option value="all">All reports</option>
            <option value="linked">Linked</option>
            <option value="not_linked">Not linked</option>
          </select>
        </label>

        <label for="ims-fr-list-shift">
          <span>Shift</span>
          <select
            id="ims-fr-list-shift"
            :value="shiftFilter"
            @change="onShiftFilterChange"
          >
            <option value="all">All shifts</option>
            <option value="current">Current shift</option>
          </select>
        </label>

        <label for="ims-fr-list-state">
          <span>Related state</span>
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
        </label>

        <label for="ims-fr-list-priority">
          <span>Related priority</span>
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
        </label>
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
                <RouterLink
                  class="ims-fr-list__report-link"
                  :to="{
                    name: 'ims.field-reports.show',
                    params: { fieldReportId: report.id },
                  }"
                  :aria-label="`Open Field Report ${report.displayNumber}`"
                >
                  <span class="ims-fr-list__number">{{
                    report.displayNumber
                  }}</span>
                  <span class="ims-fr-list__title">{{ report.title }}</span>
                </RouterLink>
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
  </WorkflowPageShell>
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

.ims-fr-list__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-fr-list__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
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
.ims-fr-list__nav a:focus-visible,
.ims-fr-list__filters select:focus-visible,
.ims-fr-list__table a:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-fr-list__filters {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.ims-fr-list__filters label {
  display: grid;
  gap: var(--m-space-2);
  min-width: 0;
}

.ims-fr-list__filters label span {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.ims-fr-list__filters select {
  width: 100%;
  min-width: 0;
  min-height: 2.75rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-3);
  background: var(--m-surface-base);
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

.ims-fr-list__report-link {
  display: grid;
  gap: var(--m-space-1);
  color: inherit;
  text-decoration: none;
}

.ims-fr-list__report-link:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
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

@media (min-width: 52rem) {
  .ims-fr-list__filters {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 43.99rem) {
  .ims-fr-list__filters {
    padding: var(--m-space-3);
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
