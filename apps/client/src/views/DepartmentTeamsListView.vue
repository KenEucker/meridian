<script setup lang="ts">
import ContentGrid from "@/components/ContentGrid.vue";
import ControlBar from "@/components/ControlBar.vue";
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import DocumentLibrarySection from "@/components/sections/DocumentLibrarySection.vue";
import {
  archiveDepartmentTeam,
  assignStaffToTeam,
  canAccessDepartmentAdmin,
  canAdministerDepartment,
  canLeadDepartmentTeam,
  getCurrentDepartment,
  getAdministeredDepartment,
  listAssignableStaff,
  listAssignableTeams,
  listDepartmentTeams,
  listManagedTeamStaff,
  listTeamLeadTeams,
  removeStaffFromTeam,
  removeTeamLead,
  resolveDepartmentSelfAdminSession,
  restoreDepartmentTeam,
  selectTeamLead,
  updateDepartmentDetails,
  type DepartmentTeam,
  type DepartmentTeamStaffMember,
} from "@/department-teams/teamAdminModel";

const session = computed(() => resolveDepartmentSelfAdminSession());
const canAdminister = computed(() => canAdministerDepartment(session.value));
const canLeadTeam = computed(() => canLeadDepartmentTeam(session.value));
const canAccessAdmin = computed(() => canAccessDepartmentAdmin(session.value));
const route = useRoute();
const router = useRouter();

const statusFilter = computed(() => {
  const value = route.query.status;
  if (value === "active" || value === "archived") {
    return value;
  }

  return "all";
});

const department = computed(() => getCurrentDepartment(session.value));
const adminLede = computed(() => {
  const current = session.value;

  if (!current) {
    return "";
  }

  if (!canAccessAdmin.value) {
    return "Admin access is not available for your current department role.";
  }

  return canAdminister.value
    ? "Department details and team administration for your current department."
    : "Team details and staff lists scoped to teams you lead.";
});
const administeredDepartment = computed(() =>
  getAdministeredDepartment(session.value),
);
const teams = computed(() =>
  listDepartmentTeams(session.value, statusFilter.value),
);
const leadTeams = computed(() => listTeamLeadTeams(session.value));
const managedStaff = computed<DepartmentTeamStaffMember[]>(() =>
  listManagedTeamStaff(session.value),
);
const assignableTeams = computed(() => listAssignableTeams(session.value));
const assignableStaff = computed(() => listAssignableStaff(session.value));

const detailsDraft = reactive({
  name: "",
  code: "",
  description: "",
});
const detailsError = ref<string | null>(null);
const detailsBusy = ref(false);
/**
 * Department details open read-only, like the team details panel beside them.
 * Editing organization-visible identity fields is a deliberate act, not
 * something to land in mid-form by opening the Admin page.
 */
const detailsEditing = ref(false);
const actionError = ref<string | null>(null);
const busyId = ref<string | null>(null);

watch(
  administeredDepartment,
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
    detailsEditing.value = false;
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
    detailsEditing.value = false;
  } catch (error) {
    detailsError.value =
      error instanceof Error
        ? error.message
        : "Unable to save department details.";
  } finally {
    detailsBusy.value = false;
  }
}

function onEditDetails(): void {
  detailsError.value = null;
  detailsEditing.value = true;
}

function onCancelDetailsEdit(): void {
  const current = administeredDepartment.value;

  detailsError.value = null;
  detailsEditing.value = false;

  if (current === null) {
    return;
  }

  detailsDraft.name = current.name;
  detailsDraft.code = current.code;
  detailsDraft.description = current.description ?? "";
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

const staffError = ref<string | null>(null);
const assignDraft = reactive({
  staffId: "",
  teamId: "",
});

function runStaffAction(action: () => void, fallback: string): void {
  staffError.value = null;

  try {
    action();
  } catch (error) {
    staffError.value = error instanceof Error ? error.message : fallback;
  }
}

function onAssignStaff(): void {
  runStaffAction(() => {
    assignStaffToTeam(session.value, assignDraft.teamId, assignDraft.staffId);
    assignDraft.staffId = "";
  }, "Unable to assign staff to the team.");
}

function onRemoveStaff(member: DepartmentTeamStaffMember): void {
  runStaffAction(
    () => removeStaffFromTeam(session.value, member.teamId, member.staffId),
    "Unable to remove staff from the team.",
  );
}

function onSelectLead(member: DepartmentTeamStaffMember): void {
  runStaffAction(
    () => selectTeamLead(session.value, member.teamId, member.staffId),
    "Unable to designate the team lead.",
  );
}

function onRemoveLead(member: DepartmentTeamStaffMember): void {
  runStaffAction(
    () => removeTeamLead(session.value, member.teamId, member.staffId),
    "Unable to remove the team lead designation.",
  );
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
  <DeptOpsShell
    class="dept-teams"
    heading-id="dept-teams-heading"
    title="Admin"
    :eyebrow="department?.name ?? session?.departmentLabel ?? 'Department'"
    :lede="adminLede"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <template #actions>
      <div class="dept-teams__header-actions">
        <WorkflowActionButton
          v-if="canAdminister"
          :to="teamCreateRoute()"
        >
          Create team
        </WorkflowActionButton>
      </div>
    </template>

    <p v-if="!canAccessAdmin" class="dept-teams__restricted" role="status">
      Admin access requires department lead or team lead authority for the
      selected department.
    </p>

    <template v-else>
      <!--
        Department details, lead selection, staff assignment, and team
        management are peer setup panels rather than a sequence, and each is
        narrower than a wide screen. They pair up instead of running four deep.
      -->
      <ContentGrid min="region" :stretch="false">
        <section
          v-if="canAdminister"
          class="dept-teams__details"
          aria-labelledby="dept-details-heading"
        >
          <div class="dept-teams__panel-heading">
            <div>
              <h2 id="dept-details-heading" class="dept-teams__subheading">
                Department details
              </h2>
              <p class="dept-teams__hint">
                {{
                  detailsEditing
                    ? "Update permitted department identity fields. Organization create and archive remain with organizers."
                    : "Permitted department identity fields. Organization create and archive remain with organizers."
                }}
              </p>
            </div>
            <button
              v-if="!detailsEditing"
              class="dept-teams__edit"
              type="button"
              @click="onEditDetails"
            >
              Edit details
            </button>
          </div>
          <p v-if="detailsError" class="dept-teams__error" role="alert">
            {{ detailsError }}
          </p>

          <dl v-if="!detailsEditing" class="dept-teams__readout">
            <div>
              <dt>Name</dt>
              <dd>{{ administeredDepartment?.name ?? "Not set" }}</dd>
            </div>
            <div>
              <dt>Code</dt>
              <dd>{{ administeredDepartment?.code ?? "Not set" }}</dd>
            </div>
            <div>
              <dt>Description</dt>
              <dd>
                {{ administeredDepartment?.description ?? "No description set." }}
              </dd>
            </div>
          </dl>

          <form v-else class="dept-teams__form" @submit.prevent="onSaveDetails">
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
            <div class="dept-teams__form-actions">
              <button
                class="dept-teams__save"
                type="submit"
                :disabled="detailsBusy"
              >
                Save department details
              </button>
              <button type="button" @click="onCancelDetailsEdit">Cancel</button>
            </div>
          </form>
        </section>

        <section
          v-if="canLeadTeam"
          class="dept-teams__lead"
          aria-labelledby="team-lead-heading"
        >
          <div>
            <h2 id="team-lead-heading" class="dept-teams__subheading">
              Team details
            </h2>
            <p class="dept-teams__hint">
              Team lead view is limited to teams you lead in this department.
            </p>
          </div>
          <ul class="dept-teams__lead-teams" aria-label="Teams you lead">
            <li v-for="team in leadTeams" :key="team.id">
              <strong>{{ team.name }}</strong>
              <span>{{ team.description ?? "No description set." }}</span>
              <small>{{ team.code }} - {{ formatArchived(team) }}</small>
            </li>
          </ul>
        </section>

        <section
          class="dept-teams__staffmgmt"
          aria-labelledby="team-staff-heading"
        >
          <div>
            <h2 id="team-staff-heading" class="dept-teams__subheading">
              Team staff
            </h2>
            <p class="dept-teams__hint">
              {{
                canAdminister
                  ? "Assign permitted department staff to teams and designate individual team leads."
                  : "Assign permitted department staff to teams you lead."
              }}
            </p>
          </div>

          <p v-if="staffError" class="dept-teams__error" role="alert">
            {{ staffError }}
          </p>

          <form class="dept-teams__assign" @submit.prevent="onAssignStaff">
            <label class="dept-teams__field">
              Staff
              <select v-model="assignDraft.staffId" required>
                <option value="" disabled>Select staff</option>
                <option
                  v-for="member in assignableStaff"
                  :key="member.staffId"
                  :value="member.staffId"
                >
                  {{ member.displayName }}
                </option>
              </select>
            </label>
            <label class="dept-teams__field">
              Team
              <select v-model="assignDraft.teamId" required>
                <option value="" disabled>Select team</option>
                <option
                  v-for="team in assignableTeams"
                  :key="team.id"
                  :value="team.id"
                >
                  {{ team.name }}
                </option>
              </select>
            </label>
            <button class="dept-teams__save" type="submit">Assign to team</button>
          </form>

          <div
            class="dept-teams__table-wrap"
            role="region"
            aria-label="Team staff"
          >
            <table class="dept-teams__table">
              <thead>
                <tr>
                  <th scope="col">Staff</th>
                  <th scope="col">Team</th>
                  <th scope="col">Role</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="managedStaff.length === 0">
                  <td colspan="4">No staff are listed for your teams.</td>
                </tr>
                <tr
                  v-for="member in managedStaff"
                  :key="`${member.teamId}:${member.staffId}`"
                >
                  <td>
                    <strong>{{ member.displayName }}</strong>
                    <span v-if="member.handle">@{{ member.handle }}</span>
                  </td>
                  <td>{{ member.teamLabel }}</td>
                  <td>{{ member.roleLabel }}</td>
                  <td class="dept-teams__actions">
                    <button
                      v-if="canAdminister && member.roleLabel !== 'Team lead'"
                      type="button"
                      @click="onSelectLead(member)"
                    >
                      Make team lead
                    </button>
                    <button
                      v-if="canAdminister && member.roleLabel === 'Team lead'"
                      type="button"
                      @click="onRemoveLead(member)"
                    >
                      Remove lead
                    </button>
                    <button
                      type="button"
                      class="dept-teams__archive"
                      @click="onRemoveStaff(member)"
                    >
                      Remove
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section
          v-if="canAdminister"
          class="dept-teams__management"
          aria-labelledby="team-management-heading"
        >
          <h2 id="team-management-heading" class="dept-teams__subheading">
            Teams
          </h2>

          <ControlBar label="Team filters">
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
          </ControlBar>

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
                    class="dept-teams__archive"
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
        </section>
      </ContentGrid>

      <!--
        Admin is the department-setup hub: department details, teams and their
        staff above, and the document library those teams publish and follow.
      -->
      <DocumentLibrarySection variant="section" surface="department" />
    </template>
  </DeptOpsShell>
</template>

<style scoped>
.dept-teams {
  width: var(--m-content-workflow);
  min-width: 0;
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

.dept-teams__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.dept-teams__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.dept-teams__header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
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

.dept-teams__panel-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: start;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.dept-teams__edit,
.dept-teams__form-actions button[type="button"] {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 800;
  line-height: 1;
  white-space: nowrap;
  cursor: pointer;
}

.dept-teams__edit:focus-visible,
.dept-teams__form-actions button:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.dept-teams__readout {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.dept-teams__readout dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.dept-teams__readout dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
  overflow-wrap: anywhere;
}

.dept-teams__form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.dept-teams__save {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 0;
  border-radius: var(--m-radius-sm);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font: inherit;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}

.dept-teams__secondary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.75rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  color: var(--m-action-secondary-bg);
  font-weight: 800;
  line-height: 1;
  text-decoration: none;
}

.dept-teams__save:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.dept-teams__nav a:focus-visible,
.dept-teams__secondary:focus-visible,
.dept-teams__save:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
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
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.dept-teams__assign {
  display: grid;
  gap: var(--m-space-3);
  grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
  align-items: end;
}

@media (max-width: 40rem) {
  .dept-teams__assign {
    grid-template-columns: 1fr;
  }
}

.dept-teams__field select {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
  font-weight: 400;
}

.dept-teams__details,
.dept-teams__lead,
.dept-teams__staffmgmt,
.dept-teams__management {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.dept-teams__lead-teams {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.dept-teams__lead-teams li {
  display: grid;
  gap: var(--m-space-1);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.dept-teams__lead-teams strong {
  color: var(--m-text-primary);
}

.dept-teams__lead-teams span,
.dept-teams__lead-teams small,
.dept-teams__table td span {
  display: block;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
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

.dept-teams__actions .dept-teams__archive {
  border-color: var(--m-action-destructive-bg);
  background: var(--m-action-destructive-bg);
  color: var(--m-action-destructive-text);
}

@media (max-width: 40rem) {
  .dept-teams__form {
    grid-template-columns: 1fr;
  }
}
</style>
