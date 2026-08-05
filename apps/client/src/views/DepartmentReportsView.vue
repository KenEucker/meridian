<script setup lang="ts">
import { computed } from "vue";

import ReportingExportSection from "@/components/sections/ReportingExportSection.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import { departmentReportingExportAuthority } from "@/reporting/reportingExports";

/**
 * `department.reports` — the department-scoped reporting surface (M18.26;
 * REPORT-014, REPORT-015; REPORT-002 through REPORT-005, REPORT-007 through
 * REPORT-009; CLIENT-019, CLIENT-020; UI contract 12.4).
 *
 * The department lead's half of REPORT-014. Same five exports as
 * `organizer.reports` and the same two-step download; what differs is the scope,
 * and the difference is in the request rather than in a sentence: every export
 * run from here names this department, so the file that arrives is the file the
 * page said it would be. The node refuses a department outside the caller's own
 * scope rather than widening to it (REPORT-007), which is what makes the
 * narrowing safe to send from a URL a person could have typed.
 *
 * Which department that is comes from the route, the way every department
 * surface resolves it: the router records the selection and the session answers
 * what holds there. A lead of two departments reaches two of these pages and
 * each exports its own.
 *
 * The exclusions are the export's own and are not relaxed here. A department
 * lead may have emergency contacts on the staff contact list (REPORT-009) and
 * still may not have them on a shift roster (REPORT-008), so the descriptor
 * states the rule per export and this surface prints it unchanged.
 */
const authority = departmentReportingExportAuthority;

const eyebrow = computed(() => authority.value?.departmentLabel ?? "Department");

const lede = computed(() => {
  const department = authority.value;

  return department === null
    ? ""
    : `${department.eventLabel} — exported as ${department.roleLabel}.`;
});

/**
 * What this surface's scope is.
 *
 * Stated as a fact rather than as a rule, unlike the organizer surface's, and
 * that is earned: the request carries the department id, so this is what was
 * asked for and what the node re-checks before it serves anything.
 */
const scopeStatement = computed(() => {
  const department = authority.value;

  return department === null
    ? ""
    : `${department.departmentLabel} only, within ${department.eventLabel}. Every export run from this page names the department, and the node refuses one outside your own scope rather than widening to it.`;
});
</script>

<template>
  <WorkflowPageShell
    heading-id="department-reports-heading"
    title="Reports"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <!--
      Absent, not disabled (CLIENT-005). Organizer standing is deliberately not
      accepted here: it reaches every department, and a page that promised one
      would be describing a file the node was never asked for.
    -->
    <p v-if="!authority" class="department-reports__restricted" role="status">
      Department reporting requires a department role carrying an export
      capability here, for an event this device is working in. An organizer
      exports the whole event from the organizer Reports page.
    </p>

    <ReportingExportSection
      v-else
      :authority="authority"
      :scope-statement="scopeStatement"
      :department-id="authority.departmentId"
    />
  </WorkflowPageShell>
</template>

<style scoped>
.department-reports__restricted {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}
</style>
