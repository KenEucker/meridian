<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import {
  authorFieldReportCatalog,
  fieldReportCatalogRevision,
} from "@/field-reports/fieldReportRuntime";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import {
  fieldReportSubmissionView,
  type OfflineFieldReport,
} from "@/field-reports/offlineFieldReport";

// My Field Reports — UI contract 12.3 `staff.field-reports.index` (M9.4).
// Authors see only their own submitted reports (FR-004; technical spec 17.6).
const session = computed(() => resolveFieldSession());

const reports = computed(() => {
  void fieldReportCatalogRevision.value;

  const current = session.value;
  if (!current) {
    return [] as OfflineFieldReport[];
  }

  return [
    ...authorFieldReportCatalog.listForAuthor(current.submittedByUserId),
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
  <section class="field-reports" aria-labelledby="field-reports-heading">
    <header class="field-reports__header">
      <h1 id="field-reports-heading" class="field-reports__heading">
        My Field Reports
      </h1>
      <p class="field-reports__lede">
        Submitted Field Reports you authored. Original reports are finalized and
        cannot be edited.
      </p>
      <p v-if="session" class="field-reports__context">
        Event: {{ session.eventLabel }}
      </p>
      <p v-else class="field-reports__unavailable" role="status">
        Field session is unavailable. Sign in and select an event to view your
        Field Reports.
      </p>
      <p class="field-reports__actions">
        <RouterLink
          class="field-reports__create"
          :to="{ name: 'staff.field-reports.create' }"
        >
          Submit Field Report
        </RouterLink>
      </p>
    </header>

    <p
      v-if="session && reports.length === 0"
      class="field-reports__empty"
      role="status"
    >
      You have not submitted any Field Reports yet.
    </p>

    <ul v-else-if="reports.length > 0" class="field-reports__list">
      <li
        v-for="report in reports"
        :key="report.id"
        class="field-reports__item"
      >
        <RouterLink
          class="field-reports__link"
          :to="{
            name: 'staff.field-reports.show',
            params: { fieldReportId: report.id },
          }"
        >
          <span class="field-reports__number">{{
            displayFor(report).displayNumber
          }}</span>
          <span class="field-reports__meta">
            <span class="field-reports__sync">{{ syncLabel(report) }}</span>
            <span class="field-reports__submitted">
              Submitted {{ report.deviceSubmittedAt }}
            </span>
          </span>
          <span class="field-reports__excerpt">{{ report.body }}</span>
        </RouterLink>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.field-reports {
  max-width: 40rem;
}

.field-reports__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.field-reports__lede,
.field-reports__context,
.field-reports__unavailable,
.field-reports__empty,
.field-reports__actions {
  margin: 0 0 var(--m-space-4);
}

.field-reports__lede,
.field-reports__context,
.field-reports__unavailable,
.field-reports__empty {
  color: var(--m-text-muted);
}

.field-reports__create {
  display: inline-block;
  padding: var(--m-space-2) var(--m-space-4);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  text-decoration: none;
  border-radius: var(--m-radius-sm);
  font-weight: 600;
}

.field-reports__create:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.field-reports__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-3);
}

.field-reports__link {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-1);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  text-decoration: none;
}

.field-reports__link:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.field-reports__number {
  font-weight: 700;
}

.field-reports__meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.field-reports__excerpt {
  color: var(--m-text-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
