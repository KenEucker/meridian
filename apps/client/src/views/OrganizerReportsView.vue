<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import ControlBar from "@/components/ControlBar.vue";
import ControlField from "@/components/ControlField.vue";
import ReportingExportSection from "@/components/sections/ReportingExportSection.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  listOrganizerDepartments,
  type OrganizerDepartment,
} from "@/organizer-departments/departmentAdminModel";
import { organizerReportingExportAuthority } from "@/reporting/reportingExports";
import { organizerDepartmentAdminSession } from "@/session/organizerAdminSession";

/**
 * `organizer.reports` — the organization/event-scoped reporting surface
 * (M18.26; REPORT-014, REPORT-015; REPORT-001 through REPORT-006, REPORT-010;
 * CLIENT-019, CLIENT-020; UI contract 12.6).
 *
 * REPORT-014 asks for two reporting surfaces and this is the organizer's: every
 * Alpha 1 export the caller may run across the whole event, each stating its
 * scope and its excluded fields before anything is generated. The department
 * lead's is `department.reports`, which runs the same exports narrowed to one
 * department.
 *
 * Which of the two a person lands on follows the role that carries the export,
 * not the capability — organizers and department leads hold all five codes
 * alike — so a department lead is offered nothing here rather than a page
 * promising an event-wide file the node would narrow anyway. An organizer who
 * is also a lead somewhere else holds both, and each surface exports at the
 * scope it names.
 *
 * The department picker narrows a scope the caller already holds and can never
 * widen one: the node refuses a department outside that scope rather than
 * serving it (REPORT-006). Narrowing changes which rows are exported and not
 * the authority the caller came by, which is why an organizer's staff contact
 * file still omits emergency contacts when narrowed to one department
 * (REPORT-010) — the export descriptor states that where the columns are.
 */
const authority = organizerReportingExportAuthority;

/**
 * The organization whose departments the picker lists.
 *
 * Gated on the same capability the endpoint behind it is: an organizer holds
 * `organization.departments.manage`, and a caller who does not gets the exports
 * at their own full scope with no picker rather than a control that could only
 * fail. It is a narrowing convenience, not a condition of exporting.
 */
const departmentAdmin = organizerDepartmentAdminSession;

const departments = ref<readonly OrganizerDepartment[]>([]);
const departmentsError = ref<string | null>(null);
/** Empty string is "every department", which is what no narrowing means. */
const selectedDepartmentId = ref("");

const eyebrow = "Organizer administration";

const lede = computed(() => {
  const event = authority.value;

  return event === null
    ? (departmentAdmin.value?.organizationLabel ?? "")
    : `${event.eventLabel} — exported as ${event.roleLabel}.`;
});

const selectedDepartment = computed(() =>
  departments.value.find(
    (department) => department.id === selectedDepartmentId.value,
  ),
);

/**
 * The narrowing sent with every request, or null for the caller's own scope.
 *
 * Resolved against the loaded list rather than trusted from the control, so a
 * stale selection cannot outlive the departments it was picked from.
 */
const narrowedDepartmentId = computed(
  () => selectedDepartment.value?.id ?? null,
);

/** What this surface's scope is, stated as the rule the node applies. */
const scopeStatement = computed(() => {
  const event = authority.value;
  const department = selectedDepartment.value;

  if (event === null) {
    return "";
  }

  return department === undefined
    ? `${event.eventLabel} — every department working the event, resolved from your own authority when the link is issued and again when the file is served.`
    : `${event.eventLabel}, narrowed to ${department.name}. Narrowing changes which rows are exported and not the authority you came by.`;
});

onMounted(async () => {
  const session = departmentAdmin.value;

  if (session === null || authority.value === null) {
    return;
  }

  try {
    departments.value = await listOrganizerDepartments(
      session.organizationId,
      "active",
    );
  } catch (caught) {
    departmentsError.value = meridianErrorMessage(
      caught,
      "Unable to load the department list. Exports still run across the whole event.",
    );
  }
});
</script>

<template>
  <WorkflowPageShell
    heading-id="organizer-reports-heading"
    title="Reports"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <!--
      Absent, not disabled: a client whose session carries no event-wide export
      grant, or no event to apply one to, has nothing this page can offer
      (CLIENT-005). The server refuses the request either way (CLIENT-006).
    -->
    <p v-if="!authority" class="organizer-reports__restricted" role="status">
      Event-wide reporting requires organizer authority for an event this device
      is working in. A department lead exports their own department from the
      department's Reports page.
    </p>

    <template v-else>
      <ControlBar
        v-if="departments.length > 0"
        label="Export scope"
        variant="band"
      >
        <ControlField
          label="Departments"
          control-id="reporting-department"
          width="lg"
        >
          <select id="reporting-department" v-model="selectedDepartmentId">
            <option value="">Every department in the event</option>
            <option
              v-for="department in departments"
              :key="department.id"
              :value="department.id"
            >
              {{ department.name }}
            </option>
          </select>
        </ControlField>
      </ControlBar>

      <p
        v-if="departmentsError"
        class="organizer-reports__notice"
        role="status"
      >
        {{ departmentsError }}
      </p>

      <ReportingExportSection
        :authority="authority"
        :scope-statement="scopeStatement"
        :department-id="narrowedDepartmentId"
      />
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.organizer-reports__restricted,
.organizer-reports__notice {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}
</style>
