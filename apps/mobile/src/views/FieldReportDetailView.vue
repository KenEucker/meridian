<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";

import {
  authorFieldReportCatalog,
  fieldReportCatalogRevision,
} from "@/field-reports/fieldReportRuntime";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import { fieldReportSubmissionView } from "@/field-reports/offlineFieldReport";

// View submitted Field Report — UI contract 12.3 `staff.field-reports.show`
// (M9.4). Authors may view their own reports (FR-004). Original body is
// view-only; no Edit/Save/autosave (FR-007; IMS surface §11; UI contract 14.2).
// Incident attachment state is not shown to the submitter (technical spec 17.6).
const route = useRoute();
const session = computed(() => resolveFieldSession());

const fieldReportId = computed(() => String(route.params.fieldReportId ?? ""));

const report = computed(() => {
  void fieldReportCatalogRevision.value;

  const current = session.value;
  if (!current || !fieldReportId.value) {
    return undefined;
  }

  return authorFieldReportCatalog.getForAuthor(
    fieldReportId.value,
    current.submittedByUserId,
  );
});

const submission = computed(() =>
  report.value ? fieldReportSubmissionView(report.value) : null,
);
</script>

<template>
  <section class="fr-detail" aria-labelledby="fr-detail-heading">
    <p class="fr-detail__nav">
      <RouterLink :to="{ name: 'staff.field-reports.index' }"
        >Back to My Field Reports</RouterLink
      >
    </p>

    <template v-if="!session">
      <h1 id="fr-detail-heading" class="fr-detail__heading">Field Report</h1>
      <p class="fr-detail__unavailable" role="status">
        Field session is unavailable. Sign in to view your submitted Field
        Reports.
      </p>
    </template>

    <template v-else-if="!report || !submission">
      <h1 id="fr-detail-heading" class="fr-detail__heading">Field Report</h1>
      <p class="fr-detail__unavailable" role="status">
        This Field Report is not available. You can only view Field Reports you
        authored.
      </p>
    </template>

    <template v-else>
      <h1 id="fr-detail-heading" class="fr-detail__heading">
        {{ submission.displayNumber }}
      </h1>
      <p
        v-if="submission.displayNumberIsTemporary"
        class="fr-detail__temporary"
        role="status"
      >
        Temporary local number until sync assigns the FRA number.
      </p>

      <dl class="fr-detail__meta">
        <div>
          <dt>Status</dt>
          <dd>Submitted</dd>
        </div>
        <div>
          <dt>Sync</dt>
          <dd>{{ submission.pendingSync ? "Pending sync" : "Synced" }}</dd>
        </div>
        <div>
          <dt>Submitted at</dt>
          <dd>{{ report.deviceSubmittedAt }}</dd>
        </div>
        <div>
          <dt>Submitted by</dt>
          <dd>You (signed-in author)</dd>
        </div>
        <div v-if="session.eventLabel">
          <dt>Event</dt>
          <dd>{{ session.eventLabel }}</dd>
        </div>
        <div v-if="session.departmentLabel">
          <dt>Department</dt>
          <dd>{{ session.departmentLabel }}</dd>
        </div>
        <div v-if="session.teamLabel">
          <dt>Team</dt>
          <dd>{{ session.teamLabel }}</dd>
        </div>
      </dl>

      <h2 class="fr-detail__body-heading">Report text</h2>
      <pre class="fr-detail__body" tabindex="0">{{ report.body }}</pre>
      <p class="fr-detail__immutable">
        This original submission is finalized and cannot be edited.
      </p>
    </template>
  </section>
</template>

<style scoped>
.fr-detail {
  max-width: 40rem;
}

.fr-detail__nav {
  margin: 0 0 var(--m-space-4);
}

.fr-detail__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.fr-detail__temporary,
.fr-detail__unavailable,
.fr-detail__immutable {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.fr-detail__meta {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-6);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.fr-detail__meta div {
  display: grid;
  grid-template-columns: 8rem 1fr;
  gap: var(--m-space-2);
}

.fr-detail__meta dt {
  margin: 0;
  color: var(--m-text-secondary);
  font-weight: 600;
}

.fr-detail__meta dd {
  margin: 0;
}

.fr-detail__body-heading {
  margin: 0 0 var(--m-space-2);
  font-size: var(--m-text-lg);
  font-family: var(--m-font-heading);
}

.fr-detail__body {
  margin: 0 0 var(--m-space-3);
  padding: var(--m-space-3);
  white-space: pre-wrap;
  word-break: break-word;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  font: inherit;
}
</style>
