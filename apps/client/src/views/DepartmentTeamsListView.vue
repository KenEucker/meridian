<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import {
  archiveDepartmentTeam,
  canAdministerDepartment,
  getAdministeredDepartment,
  listDepartmentTeams,
  resolveDepartmentSelfAdminSession,
  restoreDepartmentTeam,
  updateDepartmentDetails,
  type DepartmentTeam,
} from "@/department-teams/teamAdminModel";

const session = computed(() => resolveDepartmentSelfAdminSession());
const canAdminister = computed(() => canAdministerDepartment(session.value));
const route = useRoute();
const router = useRouter();

const statusFilter = computed(() => {
  const value = route.query.status;
  if (value === "active" || value === "archived") {
    return value;
  }

  return "all";
});

const department = computed(() => getAdministeredDepartment(session.value));
const teams = computed(() =>
  listDepartmentTeams(session.value, statusFilter.value),
);

const detailsDraft = reactive({
  name: "",
  code: "",
  description: "",
});
const detailsError = ref<string | null>(null);
const detailsBusy = ref(false);
const actionError = ref<string | null>(null);
const busyId = ref<string | null>(null);

watch(
  department,
  (value) => {
    if (value === null) {
      detailsDraft.name = "";
      detailsDraft.code = "";
      detailsDraft.description = "";
      return;
    }

    detailsDraft.name = value.name;
    detailsDraft.code = value.code;
    detailsDraft.description = value.description ?? "";
  },
  { immediate: true },
);

watch(statusFilter, () => {
  actionError.value = null;
});

function onStatusChange(event: Event): void {
  const target = event.target as HTMLSelectElement;
  const next = target.value;

  void router.replace({
    name: "events.departments.teams.index",
    params: {
      eventId: session.value?.eventId,
      departmentId: session.value?.departmentId,
    },
    query: next === "all" ? {} : { status: next },
  });
}

async function onSaveDetails(): Promise<void> {
  if (!canAdminister.value) {
    return;
  }

  detailsError.value = null;
  detailsBusy.value = true;

  try {
    updateDepartmentDetails(session.value, { ...detailsDraft });
  } catch (error) {
    detailsError.value =
      error instanceof Error
        ? error.message
        : "Unable to save department details.";
  } finally {
    detailsBusy.value = false;
  }
}

async function archiveTeam(team: DepartmentTeam): Promise<void> {
  actionError.value = null;
  busyId.value = team.id;

  try {
    archiveDepartmentTeam(session.value, team.id);
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to archive team.";
  } finally {
    busyId.value = null;
  }
}

async function restoreTeam(team: DepartmentTeam): Promise<void> {
  actionError.value = null;
  busyId.value = team.id;

  try {
    restoreDepartmentTeam(session.value, team.id);
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to restore team.";
  } finally {
    busyId.value = null;
  }
}

function formatArchived(team: DepartmentTeam): string {
  return team.archivedAt === null ? "Active" : "Archived";
}

function formatDefault(team: DepartmentTeam): string {
  return team.isDefault ? "Default" : "Team";
}

function teamEditRoute(teamId: string) {
  return {
    name: "events.departments.teams.edit",
    params: {
      eventId: session.value?.eventId,
      departmentId: session.value?.departmentId,
      teamId,
    },
  };
}

function teamCreateRoute() {
  return {
    name: "events.departments.teams.create",
    params: {
      eventId: session.value?.eventId,
      departmentId: session.value?.departmentId,
    },
  };
}
</script>

<template>
  <section class="dept-teams" aria-labelledby="dept-teams-heading">
    <header class="dept-teams__header">
      <div>
        <p class="dept-teams__eyebrow">Department administration</p>
        <h1 id="dept-teams-heading" class="dept-teams__heading">Teams</h1>
        <p v-if="session" class="dept-teams__lede">
          {{ department?.name ?? session.departmentLabel }} ·
          {{ session.eventLabel }} · {{ session.roleLabel }}
        </p>
      </div>
      <RouterLink
        v-if="canAdminister"
        class="dept-teams__primary"
        :to="teamCreateRoute()"
      >
        Create team
      </RouterLink>
    </header>

    <p v-if="!canAdminister" class="dept-teams__restricted" role="status">
      Department team administration requires department lead or department
      administration authority for this department.
    </p>

    <template v-else>
      <section
        class="dept-teams__details"
        aria-labelledby="dept-details-heading"
      >
        <h2 id="dept-details-heading" class="dept-teams__subheading">
          Department details
        </h2>
        <p class="dept-teams__hint">
          Update permitted department identity fields. Organization create and
          archive remain with organizers.
        </p>
        <p v-if="detailsError" class="dept-teams__error" role="alert">
          {{ detailsError }}
        </p>
        <form class="dept-teams__form" @submit.prevent="onSaveDetails">
          <label class="dept-teams__field">
            Name
            <input v-model="detailsDraft.name" type="text" required />
          </label>
          <label class="dept-teams__field">
            Code
            <input v-model="detailsDraft.code" type="text" required />
          </label>
          <label class="dept-teams__field dept-teams__field--wide">
            Description
            <textarea v-model="detailsDraft.description" rows="3" />
          </label>
          <button
            class="dept-teams__save"
            type="submit"
            :disabled="detailsBusy"
          >
            Save department details
          </button>
        </form>
      </section>

      <div class="dept-teams__toolbar">
        <label class="dept-teams__filter">
          Status
          <select
            :value="statusFilter"
            aria-label="Filter teams by status"
            @change="onStatusChange"
          >
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="archived">Archived</option>
          </select>
        </label>
      </div>

      <p v-if="actionError" class="dept-teams__error" role="alert">
        {{ actionError }}
      </p>

      <div class="dept-teams__table-wrap" role="region" aria-label="Teams">
        <table class="dept-teams__table">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Code</th>
              <th scope="col">Type</th>
              <th scope="col">Status</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="teams.length === 0">
              <td colspan="5">No teams match this filter.</td>
            </tr>
            <tr v-for="team in teams" :key="team.id">
              <td>
                <RouterLink :to="teamEditRoute(team.id)">
                  {{ team.name }}
                </RouterLink>
              </td>
              <td>{{ team.code }}</td>
              <td>{{ formatDefault(team) }}</td>
              <td>{{ formatArchived(team) }}</td>
              <td class="dept-teams__actions">
                <RouterLink :to="teamEditRoute(team.id)">Edit</RouterLink>
                <button
                  v-if="!team.isDefault && team.archivedAt === null"
                  type="button"
                  :disabled="busyId === team.id"
                  @click="archiveTeam(team)"
                >
                  Archive
                </button>
                <button
                  v-else-if="!team.isDefault && team.archivedAt !== null"
                  type="button"
                  :disabled="busyId === team.id"
                  @click="restoreTeam(team)"
                >
                  Restore
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.dept-teams {
  width: min(100%, 52rem);
  display: grid;
  gap: var(--m-space-4);
}

.dept-teams__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.dept-teams__eyebrow {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.dept-teams__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.dept-teams__lede,
.dept-teams__hint {
  margin: var(--m-space-2) 0 0;
  color: var(--m-text-muted);
}

.dept-teams__subheading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.dept-teams__primary,
.dept-teams__save {
  display: inline-flex;
  align-items: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 0;
  border-radius: var(--m-radius-sm);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-fg);
  font: inherit;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}

.dept-teams__save:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.dept-teams__restricted,
.dept-teams__error {
  margin: 0;
  padding: var(--m-space-3);
  border-radius: var(--m-radius-sm);
  border: 1px solid var(--m-border-default);
}

.dept-teams__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #b42318) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #b42318);
}

.dept-teams__details {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.dept-teams__form {
  display: grid;
  gap: var(--m-space-3);
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.dept-teams__field {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.dept-teams__field--wide {
  grid-column: 1 / -1;
}

.dept-teams__field input,
.dept-teams__field textarea {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
  font-weight: 400;
}

.dept-teams__toolbar {
  display: flex;
  gap: var(--m-space-3);
}

.dept-teams__filter {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.dept-teams__filter select {
  min-height: 2.5rem;
  padding: 0 var(--m-space-2);
}

.dept-teams__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.dept-teams__table {
  width: 100%;
  border-collapse: collapse;
}

.dept-teams__table th,
.dept-teams__table td {
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-default);
  text-align: left;
  vertical-align: top;
}

.dept-teams__table th {
  background: var(--m-surface-raised);
  font-size: var(--m-text-sm);
}

.dept-teams__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-2);
}

.dept-teams__actions a,
.dept-teams__actions button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.25rem;
  padding: 0 var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  line-height: 1;
  text-decoration: none;
  cursor: pointer;
}

.dept-teams__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (max-width: 40rem) {
  .dept-teams__form {
    grid-template-columns: 1fr;
  }
}
</style>
