<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import {
  listOrganizerDepartments,
  type OrganizerDepartment,
} from "@/organizer-departments/departmentAdminModel";
import {
  addOrganizerStaff,
  listOrganizerStaff,
  removeOrganizerDepartmentLead,
  selectOrganizerDepartmentLead,
  type OrganizerStaffMember,
} from "@/organizer-staff/staffAdminModel";
import { organizerStaffAdminSession } from "@/session/organizerAdminSession";

const session = organizerStaffAdminSession;
const canManage = computed(() => session.value !== null);

const staff = ref<readonly OrganizerStaffMember[]>([]);
/**
 * The departments intake and lead selection may name.
 *
 * The organization's active departments, read from the same endpoint the
 * Departments screen reads. The previous list was the bundled fixture set minus
 * whichever one carried the code `ORG`, an exclusion the server has no
 * counterpart for: it would have hidden a real organization's department that
 * happened to be coded that way, and it never hid the archived ones that
 * actually cannot receive intake.
 */
const departments = ref<readonly OrganizerDepartment[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);

const draft = reactive({
  legalName: "",
  preferredName: "",
  handle: "",
  email: "",
  departmentId: "",
  invite: true,
});
const leadDraft = reactive({
  staffId: "",
  departmentId: "",
});
const actionError = ref<string | null>(null);
const successMessage = ref<string | null>(null);

async function loadStaff(): Promise<void> {
  const current = session.value;

  if (current === null) {
    staff.value = [];
    departments.value = [];

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    const [members, activeDepartments] = await Promise.all([
      listOrganizerStaff(current.organizationId),
      listOrganizerDepartments(current.organizationId, "active"),
    ]);

    staff.value = members;
    departments.value = activeDepartments;
  } catch (error) {
    staff.value = [];
    departments.value = [];
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load staff. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

// Keyed on the organization, not the session object: see the departments list.
watch(
  () => session.value?.organizationId ?? null,
  () => {
    void loadStaff();
  },
);

void loadStaff();

async function submitStaff(): Promise<void> {
  const current = session.value;

  if (current === null) {
    return;
  }

  actionError.value = null;
  successMessage.value = null;

  try {
    const created = await addOrganizerStaff(current.organizationId, {
      ...draft,
    });

    leadDraft.staffId = created.id;
    draft.legalName = "";
    draft.preferredName = "";
    draft.handle = "";
    draft.email = "";
    draft.departmentId = "";
    draft.invite = true;
    successMessage.value = `${created.displayName} added to staff.`;
    await loadStaff();
  } catch (error) {
    actionError.value = meridianErrorMessage(error, "Unable to add staff.");
  }
}

async function submitLead(): Promise<void> {
  actionError.value = null;
  successMessage.value = null;

  try {
    const updated = await selectOrganizerDepartmentLead(
      leadDraft.staffId,
      leadDraft.departmentId,
    );
    const department = departments.value.find(
      (candidate) => candidate.id === leadDraft.departmentId,
    );

    successMessage.value = `${updated.displayName} selected as ${department?.name ?? "department"} lead.`;
    await loadStaff();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to select department lead.",
    );
  }
}

async function removeLead(
  member: OrganizerStaffMember,
  departmentId: string,
): Promise<void> {
  actionError.value = null;
  successMessage.value = null;

  try {
    await removeOrganizerDepartmentLead(member.id, departmentId);
    successMessage.value = `${member.displayName} removed from lead selection.`;
    await loadStaff();
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to remove department lead.",
    );
  }
}

/**
 * The departments a staff member belongs to, and where they lead.
 *
 * Named from the membership rather than looked up in `departments`, because a
 * member may hold a membership in a department that has since been archived and
 * the selectable list deliberately excludes those.
 */
function departmentLabels(member: OrganizerStaffMember): string {
  if (member.departments.length === 0) {
    return "No department assignment";
  }

  return member.departments
    .map((department) => {
      const name = department.departmentName ?? "Unnamed department";

      return department.isLead ? `${name} lead` : name;
    })
    .join(", ");
}
</script>

<template>
  <section class="org-staff" aria-labelledby="org-staff-heading">
    <header class="org-staff__header">
      <div>
        <p class="org-staff__eyebrow">Organizer administration</p>
        <h1 id="org-staff-heading" class="org-staff__heading">Staff</h1>
        <p v-if="session" class="org-staff__lede">
          {{ session.organizationLabel }} / {{ session.roleLabel }}
        </p>
      </div>
    </header>

    <p v-if="!canManage" class="org-staff__restricted" role="status">
      Staff administration requires organizer or lead organizer authority for
      this organization.
    </p>

    <template v-else>
      <p v-if="loadError" class="org-staff__error" role="alert">
        {{ loadError }}
      </p>
      <p v-if="actionError" class="org-staff__error" role="alert">
        {{ actionError }}
      </p>
      <p v-if="successMessage" class="org-staff__success" role="status">
        {{ successMessage }}
      </p>

      <section class="org-staff__panel" aria-labelledby="add-staff-heading">
        <h2 id="add-staff-heading">Add or invite staff</h2>
        <form class="org-staff__form" @submit.prevent="submitStaff">
          <label>
            Legal name
            <input v-model="draft.legalName" type="text" required maxlength="255" />
          </label>
          <label>
            Preferred name
            <input v-model="draft.preferredName" type="text" maxlength="255" />
          </label>
          <label>
            Handle
            <input v-model="draft.handle" type="text" maxlength="255" />
          </label>
          <label>
            Email
            <input v-model="draft.email" type="email" required maxlength="255" />
          </label>
          <label>
            Initial department
            <select v-model="draft.departmentId">
              <option value="">No department yet</option>
              <option
                v-for="department in departments"
                :key="department.id"
                :value="department.id"
              >
                {{ department.name }}
              </option>
            </select>
          </label>
          <label class="org-staff__check">
            <input v-model="draft.invite" type="checkbox" />
            Send invite
          </label>
          <button type="submit">Add staff</button>
        </form>
      </section>

      <section class="org-staff__panel" aria-labelledby="lead-heading">
        <h2 id="lead-heading">Department lead selection</h2>
        <form class="org-staff__lead-form" @submit.prevent="submitLead">
          <label>
            Staff
            <select v-model="leadDraft.staffId" required>
              <option value="" disabled>Select staff</option>
              <option v-for="member in staff" :key="member.id" :value="member.id">
                {{ member.displayName }}
              </option>
            </select>
          </label>
          <label>
            Department
            <select v-model="leadDraft.departmentId" required>
              <option value="" disabled>Select department</option>
              <option
                v-for="department in departments"
                :key="department.id"
                :value="department.id"
              >
                {{ department.name }}
              </option>
            </select>
          </label>
          <button type="submit">Select lead</button>
        </form>
      </section>

      <div class="org-staff__table-wrap" role="region" aria-label="Staff">
        <table class="org-staff__table">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Email</th>
              <th scope="col">Status</th>
              <th scope="col">Departments</th>
              <th scope="col">Lead actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading && staff.length === 0">
              <td colspan="5">Loading staff…</td>
            </tr>
            <tr v-else-if="staff.length === 0 && loadError === null">
              <td colspan="5">No staff in this organization yet.</td>
            </tr>
            <tr v-for="member in staff" :key="member.id">
              <td>
                <strong>{{ member.displayName }}</strong>
                <span v-if="member.handle"> @{{ member.handle }}</span>
              </td>
              <td>{{ member.email }}</td>
              <td>
                {{ member.organizationStatus }}
                <span v-if="member.invited"> / invited</span>
              </td>
              <td>{{ departmentLabels(member) }}</td>
              <td>
                <button
                  v-for="departmentId in member.leadDepartmentIds"
                  :key="departmentId"
                  type="button"
                  @click="removeLead(member, departmentId)"
                >
                  Remove lead
                </button>
                <span v-if="member.leadDepartmentIds.length === 0">None</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.org-staff {
  width: min(100%, 68rem);
  display: grid;
  gap: var(--m-space-4);
}

.org-staff__header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.org-staff__eyebrow {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.org-staff__heading,
.org-staff__panel h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  letter-spacing: 0;
}

.org-staff__heading {
  font-size: var(--m-text-xl);
}

.org-staff__panel h2 {
  font-size: var(--m-text-md);
}

.org-staff__lede {
  margin: var(--m-space-2) 0 0;
  color: var(--m-text-muted);
}

.org-staff__restricted,
.org-staff__error,
.org-staff__success,
.org-staff__panel {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.org-staff__error {
  border-color: color-mix(in srgb, var(--m-status-danger, #cc792f) 40%, var(--m-border-default));
  color: var(--m-status-danger, #cc792f);
}

.org-staff__success {
  border-color: color-mix(in srgb, var(--m-status-success, #6b7562) 45%, var(--m-border-default));
}

.org-staff__form,
.org-staff__lead-form {
  display: grid;
  gap: var(--m-space-3);
  margin-top: var(--m-space-3);
}

.org-staff__form label,
.org-staff__lead-form label {
  display: grid;
  gap: var(--m-space-1);
  font-weight: 600;
}

.org-staff__check {
  display: flex !important;
  grid-template-columns: auto 1fr;
  align-items: center;
}

.org-staff input,
.org-staff select {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
}

.org-staff button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.5rem;
  padding: 0 var(--m-space-3);
  border: 1px solid transparent;
  border-radius: var(--m-radius-sm);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.org-staff__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.org-staff__table {
  width: 100%;
  border-collapse: collapse;
}

.org-staff__table th,
.org-staff__table td {
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-default);
  text-align: left;
  vertical-align: top;
}

.org-staff__table th {
  background: var(--m-surface-raised);
  font-size: var(--m-text-sm);
}

@media (min-width: 44rem) {
  .org-staff__form {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .org-staff__lead-form {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
    align-items: end;
  }
}
</style>
