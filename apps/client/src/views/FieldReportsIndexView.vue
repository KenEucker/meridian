<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import StaffCardList from "@/components/StaffCardList.vue";
import StaffListCard from "@/components/StaffListCard.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import {
  authorFieldReportCatalog,
  fieldReportCatalogRevision,
} from "@/field-reports/fieldReportRuntime";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import {
  fieldReportSubmissionView,
  type OfflineFieldReport,
} from "@/field-reports/offlineFieldReport";

// My Field Reports — UI contract 12.3 `staff.field-reports.index` (M9.4 / M9.7A).
// Authors see only their own submitted reports (FR-004; technical spec 17.6).
// List shows the immutable title (UI contract 14.2).
const session = computed(() => resolveFieldSession());

const reports = computed(() => {
  void fieldReportCatalogRevision.value;

  const current = session.value;
  if (!current) {
    return [] as OfflineFieldReport[];
  }

  // Scoped by staff id as well as user id so a Field Report an operator typed
  // for this staff member appears in their own list (FR-004).
  return [
    ...authorFieldReportCatalog.listForAuthor(
      current.submittedByUserId,
      undefined,
      current.staffId,
    ),
  ].reverse();
});

function displayFor(report: OfflineFieldReport) {
  return fieldReportSubmissionView(report);
}

function syncLabel(report: OfflineFieldReport): string {
  const view = displayFor(report);
  return view.pendingSync ? "Pending sync" : "Synced";
}
</script>

<template>
  <StaffPageShell
    heading-id="field-reports-heading"
    title="My Field Reports"
    lede="Submitted Field Reports you authored. Original reports are finalized and cannot be edited."
    :context="session ? `Event: ${session.eventLabel}` : ''"
  >
    <template #actions>
      <RouterLink
        data-variant="primary"
        :to="{ name: 'staff.field-reports.create' }"
      >
        Submit Field Report
      </RouterLink>
    </template>

    <p v-if="!session" class="field-reports__unavailable" role="status">
      Field session is unavailable. Sign in and select an event to view your
      Field Reports.
    </p>

    <!--
      What this list is, said plainly (M18.53).

      The catalog behind it is this device's own: a Field Report is written
      where there is no signal and lives here until the node takes it, and this
      surface has never had a node read to widen it (M9.4). Left unsaid, an
      empty list reads as "you have filed nothing" — which is a claim about the
      author rather than about the device, and is wrong for anybody who filed
      from a phone and is now standing at a desk.
    -->
    <p v-else-if="reports.length === 0" class="field-reports__scope" role="status">
      No Field Reports have been submitted from this device. Field Reports
      submitted from another device are held on that one until the node has
      them, and are read from Incident Command's own list rather than from here.
    </p>

    <StaffCardList
      v-else
      label="Field Reports"
      :empty="false"
      empty-message="You have not submitted any Field Reports yet."
    >
      <StaffListCard
        v-for="report in reports"
        :key="report.id"
        :to="{
          name: 'staff.field-reports.show',
          params: { fieldReportId: report.id },
        }"
        :eyebrow="displayFor(report).displayNumber"
        :title="report.title"
        :status="syncLabel(report)"
        :meta="[{ label: 'Submitted', value: report.deviceSubmittedAt }]"
      >
        <p class="field-reports__excerpt">{{ report.body }}</p>
      </StaffListCard>
    </StaffCardList>
  </StaffPageShell>
</template>

<style scoped>
.field-reports__unavailable,
.field-reports__scope {
  margin: 0;
  color: var(--m-text-muted);
}

.field-reports__excerpt {
  margin: 0;
  color: var(--m-text-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
