<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";

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
  <section class="event-info" aria-labelledby="event-info-heading">
    <p class="event-info__nav">
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </p>

    <header class="event-info__header">
      <p class="event-info__eyebrow">Event info</p>
      <h1 id="event-info-heading">{{ eventInfo.eventLabel }}</h1>
      <p>{{ eventInfo.departmentLabel }}</p>
      <dl class="event-info__context" aria-label="Event information">
        <div>
          <dt>Operations</dt>
          <dd>{{ operationsWindowLabel }}</dd>
        </div>
        <div>
          <dt>Organization</dt>
          <dd>{{ eventInfo.organizationLabel }}</dd>
        </div>
        <div>
          <dt>Published documents</dt>
          <dd>
            {{ eventInfo.documentCount }} visible to you
          </dd>
        </div>
      </dl>
      <p class="event-info__source" role="note">
        Every section below is the published policy and procedure content you are
        permitted to see. Sections without a published document say so instead of
        standing in for one.
      </p>
    </header>

    <div class="event-info__grid">
      <article
        v-for="section in eventInfo.sections"
        :key="section.section"
        :data-section="section.section"
        :data-empty="section.documents.length === 0 ? 'true' : 'false'"
      >
        <h2>{{ section.label }}</h2>

        <p
          v-if="section.emptyDescription"
          class="event-info__empty"
          role="status"
        >
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
    </div>
  </section>
</template>

<style scoped>
.event-info {
  display: grid;
  gap: var(--m-space-4);
  width: min(100%, 72rem);
}

.event-info__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.event-info__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.event-info__header,
.event-info__grid article {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.event-info__eyebrow,
.event-info__header h1,
.event-info__header p,
.event-info__grid h2,
.event-info__grid p {
  margin: 0;
}

.event-info__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-transform: uppercase;
}

.event-info__header h1 {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.event-info__header p,
.event-info__grid p {
  color: var(--m-text-muted);
}

.event-info__source {
  font-size: var(--m-text-sm);
}

.event-info__context {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.event-info__context div {
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.event-info__context dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.event-info__context dd {
  margin: var(--m-space-1) 0 0;
}

.event-info__grid {
  display: grid;
  gap: var(--m-space-3);
  align-items: start;
}

.event-info__grid h2 {
  font-size: var(--m-text-base);
}

.event-info__document {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
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
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
}

.event-info__empty {
  font-size: var(--m-text-sm);
}

.event-info__nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .event-info__context,
  .event-info__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
