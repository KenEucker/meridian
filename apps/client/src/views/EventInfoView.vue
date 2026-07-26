<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";

import StaffPageShell from "@/components/StaffPageShell.vue";
import {
  LOCAL_DEPARTMENT_OPS_CONTEXT,
  LOCAL_PLANNING_TABLE,
} from "@/department-ops/fixtures";
import { formatTimestamp } from "@/department-ops/labels";
import {
  renderDocumentMarkdown,
  scopeLabel,
} from "@/documents/documentAuthoringModel";
import { resolveEventInfo } from "@/event-info/eventInfoModel";

const route = useRoute();
const eventInfo = computed(() =>
  resolveEventInfo(
    typeof route.params.eventId === "string" ? route.params.eventId : null,
    typeof route.params.departmentId === "string"
      ? route.params.departmentId
      : null,
  ),
);
const eventWindow = computed(() => {
  const starts = LOCAL_PLANNING_TABLE.rows.map((row) => row.startsAt).sort();
  const ends = LOCAL_PLANNING_TABLE.rows.map((row) => row.endsAt).sort();

  return {
    startsAt: starts[0] ?? null,
    endsAt: ends.at(-1) ?? null,
  };
});
const operationsWindowLabel = computed(() => {
  const startsAt = eventWindow.value.startsAt;
  const endsAt = eventWindow.value.endsAt;

  if (!startsAt || !endsAt) {
    return "Operations window not set";
  }

  return `${formatTimestamp(
    startsAt,
    LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone,
  )} to ${formatTimestamp(endsAt, LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone)}`;
});
</script>

<template>
  <StaffPageShell
    heading-id="event-info-heading"
    eyebrow="Event info"
    :title="eventInfo.eventLabel"
    :lede="`${eventInfo.departmentLabel} / ${operationsWindowLabel}`"
    :context="`${eventInfo.documentCount} published documents visible to you`"
  >
    <template #under-title>
      <p class="event-info__source" role="note">
        Every section below is the published policy and procedure content you are
        permitted to see. Sections without a published document say so instead of
        standing in for one.
      </p>
    </template>

    <article
      v-for="section in eventInfo.sections"
      :key="section.section"
      class="event-info__section"
      :data-section="section.section"
      :data-empty="section.documents.length === 0 ? 'true' : 'false'"
    >
      <h2>{{ section.label }}</h2>

      <p v-if="section.emptyDescription" class="event-info__empty" role="status">
        {{ section.emptyDescription }}
      </p>

      <section
        v-for="document in section.documents"
        :key="document.id"
        class="event-info__document"
      >
        <h3>{{ document.title }}</h3>
        <div
          class="event-info__document-body"
          v-html="renderDocumentMarkdown(document.markdownSource)"
        />
        <p class="event-info__document-meta">
          {{ document.kind === "policy" ? "Policy" : "Procedure" }} /
          {{ scopeLabel(document.scopeType, document.scopeId) }} / version
          {{ document.version }}
        </p>
      </section>
    </article>
  </StaffPageShell>
</template>

<style scoped>
.event-info__source {
  margin: var(--m-space-2) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.event-info__section {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.event-info__section h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-base);
}

.event-info__document {
  display: grid;
  gap: var(--m-space-2);
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.event-info__document h3 {
  margin: 0;
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-transform: uppercase;
}

.event-info__document-body :deep(h1),
.event-info__document-body :deep(h2) {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-base);
}

.event-info__document-body :deep(p) {
  margin: 0 0 var(--m-space-2);
  color: var(--m-text-secondary);
}

.event-info__document-body :deep(p:last-child) {
  margin-bottom: 0;
}

.event-info__document-meta {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
}

.event-info__empty {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}
</style>
