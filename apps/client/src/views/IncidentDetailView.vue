<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import {
  appendIncidentNoteForSession,
  canAppendIncidentNote,
  findIncidentForSession,
  hasIncidentCommandAccess,
  resolveIncidentSession,
  statusLabel,
  type IncidentTimelineEntry,
  type NameReferenceChip,
} from "@/ims/incidentReadModel";

const route = useRoute();
const session = computed(() => resolveIncidentSession());
const canView = computed(() => hasIncidentCommandAccess(session.value));
const canAppendNote = computed(() => canAppendIncidentNote(session.value));
const timelineRevision = ref(0);
const noteBody = ref("");
const noteError = ref<string | null>(null);
const incident = computed(() => {
  // Recompute after local append-note writes in the development IMS surface.
  // Real server reads will replace this session fixture in the auth/API slice.
  timelineRevision.value;

  return findIncidentForSession(session.value, String(route.params.incidentId));
});
const timelineEntries = computed(() => incident.value?.timelineEntries ?? []);

function priorityText(priorityLabel: string | null): string {
  return priorityLabel ?? "Priority not set";
}

function timelineEntryLabel(entry: IncidentTimelineEntry): string {
  return entry.entryType === "incident_opened"
    ? "Incident opened"
    : "Operational note";
}

function chipSearchTarget(chip: NameReferenceChip) {
  return {
    name: "ims.incidents.index",
    query: { search: chip.token },
  };
}

function onAppendNote(): void {
  noteError.value = null;

  if (!incident.value) {
    noteError.value = "Incident not found for this event.";
    return;
  }

  try {
    appendIncidentNoteForSession(
      session.value,
      incident.value.id,
      noteBody.value,
    );
    noteBody.value = "";
    timelineRevision.value += 1;
  } catch (error) {
    noteError.value =
      error instanceof Error ? error.message : "Unable to add incident note.";
  }
}
</script>

<template>
  <section class="ims-detail" aria-labelledby="ims-detail-heading">
    <div v-if="!canView" class="ims-detail__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <div v-else-if="!incident" class="ims-detail__missing" role="status">
      Incident not found for this event.
    </div>

    <template v-else>
      <RouterLink class="ims-detail__back" :to="{ name: 'ims.incidents.index' }">
        Back to incidents
      </RouterLink>

      <header class="ims-detail__header">
        <p class="ims-detail__number">{{ incident.incidentNumber }}</p>
        <h1 id="ims-detail-heading" class="ims-detail__heading">
          {{ incident.title }}
        </h1>
        <dl class="ims-detail__status-row">
          <div>
            <dt>State</dt>
            <dd>{{ statusLabel(incident.status) }}</dd>
          </div>
          <div>
            <dt>Priority</dt>
            <dd>{{ priorityText(incident.priorityLabel) }}</dd>
          </div>
          <div>
            <dt>Started</dt>
            <dd>{{ incident.startedAt }}</dd>
          </div>
          <div>
            <dt>Last update</dt>
            <dd>{{ incident.updatedAt }}</dd>
          </div>
        </dl>
        <nav
          v-if="incident.nameReferenceChips.length > 0"
          class="ims-detail__name-references"
          aria-label="Incident Name References"
        >
          <RouterLink
            v-for="chip in incident.nameReferenceChips"
            :key="chip.normalizedToken"
            class="ims-detail__name-reference"
            :to="chipSearchTarget(chip)"
            :aria-label="`Search incidents for Name Reference ${chip.token}`"
          >
            @{{ chip.token }}
          </RouterLink>
        </nav>
      </header>

      <div class="ims-detail__layout">
        <section aria-labelledby="ims-context-heading" class="ims-detail__panel">
          <h2 id="ims-context-heading">Current state</h2>
          <dl class="ims-detail__definition">
            <div>
              <dt>Event</dt>
              <dd>{{ session?.eventLabel }}</dd>
            </div>
            <div>
              <dt>IC department</dt>
              <dd>{{ session?.icDepartmentLabel }}</dd>
            </div>
            <div>
              <dt>Location</dt>
              <dd>{{ incident.locationName ?? "Location not set" }}</dd>
            </div>
            <div v-if="incident.locationAddress">
              <dt>Address</dt>
              <dd>{{ incident.locationAddress }}</dd>
            </div>
            <div v-if="incident.locationDetails">
              <dt>Location details</dt>
              <dd>{{ incident.locationDetails }}</dd>
            </div>
            <div>
              <dt>Created by</dt>
              <dd>{{ incident.createdByName ?? "Creator unavailable" }}</dd>
            </div>
          </dl>
        </section>

        <section
          aria-labelledby="ims-timeline-heading"
          class="ims-detail__panel"
        >
          <h2 id="ims-timeline-heading">Timeline</h2>
          <ol class="ims-detail__timeline">
            <li v-for="entry in timelineEntries" :key="entry.id">
              <span>{{ timelineEntryLabel(entry) }}</span>
              <p v-if="entry.body">{{ entry.body }}</p>
              <time :datetime="entry.createdAt">{{ entry.createdAt }}</time>
              <small v-if="entry.actorName">{{ entry.actorName }}</small>
            </li>
          </ol>

          <form
            v-if="canAppendNote"
            class="ims-detail__note-form"
            aria-labelledby="ims-note-heading"
            @submit.prevent="onAppendNote"
          >
            <h3 id="ims-note-heading">Add note</h3>
            <label class="ims-detail__note-label" for="ims-note-body">
              Operational note
            </label>
            <textarea
              id="ims-note-body"
              v-model="noteBody"
              class="ims-detail__note-body"
              name="body"
              rows="4"
              required
            />
            <p v-if="noteError" class="ims-detail__note-error" role="alert">
              {{ noteError }}
            </p>
            <button class="ims-detail__note-submit" type="submit">
              Add note
            </button>
          </form>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.ims-detail {
  width: min(100%, 72rem);
  display: grid;
  gap: var(--m-space-5);
}

.ims-detail__back,
.ims-detail__restricted a {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}

.ims-detail__restricted,
.ims-detail__missing {
  color: var(--m-text-muted);
}

.ims-detail__header {
  display: grid;
  gap: var(--m-space-3);
}

.ims-detail__number,
.ims-detail__heading {
  margin: 0;
}

.ims-detail__number {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-detail__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.ims-detail__status-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  gap: var(--m-space-3);
  margin: 0;
}

.ims-detail__status-row div,
.ims-detail__panel {
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-detail__status-row dt,
.ims-detail__definition dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-detail__status-row dd,
.ims-detail__definition dd {
  margin: var(--m-space-1) 0 0;
}

.ims-detail__name-references {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
}

.ims-detail__name-reference {
  display: inline-flex;
  min-height: 2rem;
  align-items: center;
  border: 1px solid var(--m-action-secondary-bg);
  border-radius: var(--m-radius-sm);
  padding: 0 var(--m-space-2);
  background: var(--m-surface-primary);
  color: var(--m-action-secondary-bg);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-decoration: none;
}

.ims-detail__name-reference:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-detail__layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--m-space-4);
}

.ims-detail__panel h2 {
  margin: 0 0 var(--m-space-3);
  font-size: var(--m-text-lg);
}

.ims-detail__definition {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.ims-detail__timeline {
  margin: 0;
  padding-left: var(--m-space-5);
}

.ims-detail__timeline li {
  margin-bottom: var(--m-space-2);
}

.ims-detail__timeline span {
  display: block;
  font-weight: 700;
}

.ims-detail__timeline time {
  display: block;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.ims-detail__timeline p {
  margin: var(--m-space-1) 0;
  white-space: pre-wrap;
}

.ims-detail__timeline small {
  display: block;
  color: var(--m-text-muted);
}

.ims-detail__note-form {
  display: grid;
  gap: var(--m-space-2);
  margin-top: var(--m-space-4);
  padding-top: var(--m-space-4);
  border-top: 1px solid var(--m-border-default);
}

.ims-detail__note-form h3 {
  margin: 0;
  font-size: var(--m-text-base);
}

.ims-detail__note-label {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-detail__note-body {
  width: 100%;
  min-height: 7rem;
  resize: vertical;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-3);
  background: var(--m-surface-primary);
  color: var(--m-text-primary);
  font: inherit;
}

.ims-detail__note-error {
  margin: 0;
  color: var(--m-status-danger);
  font-size: var(--m-text-sm);
}

.ims-detail__note-submit {
  justify-self: start;
  border: 0;
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font-weight: 800;
}

@media (min-width: 56rem) {
  .ims-detail__layout {
    grid-template-columns: minmax(0, 1fr) minmax(20rem, 0.8fr);
  }
}
</style>
