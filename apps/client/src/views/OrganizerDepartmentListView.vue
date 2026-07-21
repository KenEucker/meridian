<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import {
  archiveOrganizerDepartment,
  canManageOrganizerDepartments,
  listOrganizerDepartments,
  resolveOrganizerDepartmentSession,
  restoreOrganizerDepartment,
  type OrganizerDepartment,
} from "@/organizer-departments/departmentAdminModel";

const session = computed(() => resolveOrganizerDepartmentSession());
const canManage = computed(() => canManageOrganizerDepartments(session.value));
const route = useRoute();
const router = useRouter();

const statusFilter = computed(() => {
  const value = route.query.status;
  if (value === "active" || value === "archived") {
    return value;
  }

  return "all";
});

const departments = computed(() =>
  listOrganizerDepartments(session.value, statusFilter.value),
);
const actionError = ref<string | null>(null);
const busyId = ref<string | null>(null);

watch(statusFilter, () => {
  actionError.value = null;
});

function onStatusChange(event: Event): void {
  const target = event.target as HTMLSelectElement;
  const next = target.value;

  void router.replace({
    name: "organizer.departments.index",
    query: next === "all" ? {} : { status: next },
  });
}

async function archiveDepartment(department: OrganizerDepartment): Promise<void> {
  actionError.value = null;
  busyId.value = department.id;

  try {
    archiveOrganizerDepartment(session.value, department.id);
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to archive department.";
  } finally {
    busyId.value = null;
  }
}

async function restoreDepartment(department: OrganizerDepartment): Promise<void> {
  actionError.value = null;
  busyId.value = department.id;

  try {
    restoreOrganizerDepartment(session.value, department.id);
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to restore department.";
  } finally {
    busyId.value = null;
  }
}

function formatArchived(department: OrganizerDepartment): string {
  return department.archivedAt === null ? "Active" : "Archived";
}
</script>

<template>
  <section class="org-dept" aria-labelledby="org-dept-heading">
    <header class="org-dept__header">
      <div>
        <p class="org-dept__eyebrow">Organizer administration</p>
        <h1 id="org-dept-heading" class="org-dept__heading">Departments</h1>
        <p v-if="session" class="org-dept__lede">
          {{ session.organizationLabel }} · {{ session.roleLabel }}
        </p>
      </div>
      <RouterLink
        v-if="canManage"
        class="org-dept__primary"
        :to="{ name: 'organizer.departments.create' }"
      >
        Create department
      </RouterLink>
    </header>

    <p v-if="!canManage" class="org-dept__restricted" role="status">
      Department administration requires organizer or lead organizer authority
      for this organization.
    </p>

    <template v-else>
      <div class="org-dept__toolbar">
        <label class="org-dept__filter">
          Status
          <select
            :value="statusFilter"
            aria-label="Filter departments by status"
            @change="onStatusChange"
          >
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="archived">Archived</option>
          </select>
        </label>
      </div>

      <p v-if="actionError" class="org-dept__error" role="alert">
        {{ actionError }}
      </p>

      <div class="org-dept__table-wrap" role="region" aria-label="Departments">
        <table class="org-dept__table">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Code</th>
              <th scope="col">Status</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="departments.length === 0">
              <td colspan="4">No departments match this filter.</td>
            </tr>
            <tr v-for="department in departments" :key="department.id">
              <td>
                <RouterLink
                  :to="{
                    name: 'organizer.departments.edit',
                    params: { departmentId: department.id },
                  }"
                >
                  {{ department.name }}
                </RouterLink>
              </td>
              <td>{{ department.code }}</td>
              <td>{{ formatArchived(department) }}</td>
              <td class="org-dept__actions">
                <RouterLink
                  :to="{
                    name: 'organizer.departments.edit',
                    params: { departmentId: department.id },
                  }"
                >
                  Edit
                </RouterLink>
                <button
                  v-if="department.archivedAt === null"
                  type="button"
                  :disabled="busyId === department.id"
                  @click="archiveDepartment(department)"
                >
                  Archive
                </button>
                <button
                  v-else
                  type="button"
                  :disabled="busyId === department.id"
                  @click="restoreDepartment(department)"
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
.org-dept {
  width: min(100%, 52rem);
  display: grid;
  gap: var(--m-space-4);
}

.org-dept__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.org-dept__eyebrow {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.org-dept__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.org-dept__lede {
  margin: var(--m-space-2) 0 0;
  color: var(--m-text-muted);
}

.org-dept__primary {
  display: inline-flex;
  align-items: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border-radius: var(--m-radius-sm);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-fg);
  font-weight: 600;
  text-decoration: none;
}

.org-dept__restricted,
.org-dept__error {
  margin: 0;
  padding: var(--m-space-3);
  border-radius: var(--m-radius-sm);
  border: 1px solid var(--m-border-default);
}

.org-dept__error {
  border-color: color-mix(in srgb, var(--m-status-danger, #b42318) 40%, var(--m-border-default));
  color: var(--m-status-danger, #b42318);
}

.org-dept__toolbar {
  display: flex;
  gap: var(--m-space-3);
}

.org-dept__filter {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.org-dept__filter select {
  min-height: 2.5rem;
  padding: 0 var(--m-space-2);
}

.org-dept__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.org-dept__table {
  width: 100%;
  border-collapse: collapse;
}

.org-dept__table th,
.org-dept__table td {
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-default);
  text-align: left;
  vertical-align: top;
}

.org-dept__table th {
  background: var(--m-surface-raised);
  font-size: var(--m-text-sm);
}

.org-dept__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-2);
}

.org-dept__actions a,
.org-dept__actions button {
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

.org-dept__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
