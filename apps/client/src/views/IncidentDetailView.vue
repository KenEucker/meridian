<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import {
  appendIncidentNoteForSession,
  canAppendIncidentNote,
  canEditIncident,
  findIncidentForSession,
  formatIncidentDateTime,
  hasIncidentCommandAccess,
  resolveIncidentSession,
  statusLabel,
  type IncidentPriorityLabel,
  type IncidentTagChip,
  type IncidentTimelineEntry,
  type NameReferenceChip,
} from "@/ims/incidentReadModel";

const route = useRoute();
const session = computed(() => resolveIncidentSession());
const canView = computed(() => hasIncidentCommandAccess(session.value));
const canAppendNote = computed(() => canAppendIncidentNote(session.value));
const canEdit = computed(() => canEditIncident(session.value));
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

function priorityClass(priorityLabel: IncidentPriorityLabel): string {
  return `ims-detail__priority-pill--${priorityLabel.toLowerCase()}`;
}

function responderText(): string {
  return incident.value && incident.value.responders.length > 0
    ? incident.value.responders
        .map((responder) => responder.displayName)
        .join(", ")
    : "Responders not set";
}

function timelineEntryBody(entry: IncidentTimelineEntry): string | null {
  if (entry.body) {
    return entry.body;
  }

  if (entry.entryType === "incident_opened") {
    return "Incident opened.";
  }

  if (entry.entryType !== "incident_field_updated") {
    return null;
  }

  const newValue = entry.newValue ?? {};

  return Object.keys(newValue)
    .map((field) => {
      const label = fieldLabel(field).toLowerCase();
      const value = formatTimelineChangedValue(field, newValue[field] ?? null);

      return `Changed ${label}: ${value}`;
    })
    .join("\n");
}

function fieldLabel(field: string): string {
  const labels: Record<string, string> = {
    title: "Title",
    status: "State",
    priorityLabel: "Priority",
    incidentTypeNames: "Incident types",
    responders: "Responders",
    startedAt: "Started",
    locationName: "Location name",
    locationAddress: "Location address",
    locationDetails: "Location details",
  };

  return labels[field] ?? field;
}

function formatTimelineChangedValue(
  field: string,
  value: string | null,
): string {
  if (value === null || value === "") {
    return "not set";
  }

  return field === "status" ? statusLabel(value as Parameters<typeof statusLabel>[0]) : value;
}

function nameReferenceSearchTarget(chip: NameReferenceChip) {
  return {
    name: "ims.incidents.index",
    query: { search: chip.token },
  };
}

function tagSearchTarget(chip: IncidentTagChip) {
  return {
    name: "ims.incidents.index",
    query: { search: `#${chip.tag}` },
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
        <RouterLink
          v-if="canEdit"
          class="ims-detail__edit"
          :to="{ name: 'ims.incidents.edit', params: { incidentId: incident.id } }"
        >
          Edit incident
        </RouterLink>
        <dl class="ims-detail__status-row">
          <div>
            <dt>State</dt>
            <dd>
              <span class="ims-detail__state-pill">
                {{ statusLabel(incident.status) }}
              </span>
            </dd>
          </div>
          <div>
            <dt>Priority</dt>
            <dd>
              <span
                class="ims-detail__priority-pill"
                :class="priorityClass(incident.priorityLabel)"
              >
                {{ priorityText(incident.priorityLabel) }}
              </span>
            </dd>
          </div>
          <div>
            <dt>Started</dt>
            <dd>{{ formatIncidentDateTime(incident.startedAt) }}</dd>
          </div>
          <div>
            <dt>Last update</dt>
            <dd>{{ formatIncidentDateTime(incident.updatedAt) }}</dd>
          </div>
        </dl>
        <nav
          v-if="
            incident.tagChips.length > 0 ||
            incident.nameReferenceChips.length > 0
          "
          class="ims-detail__chips"
          aria-label="Incident metadata chips"
        >
          <RouterLink
            v-for="chip in incident.tagChips"
            :key="`tag-${chip.normalizedTag}`"
            class="ims-detail__chip ims-detail__chip--tag"
            :to="tagSearchTarget(chip)"
            :aria-label="`Search incidents for tag ${chip.tag}`"
          >
            #{{ chip.tag }}
          </RouterLink>
          <RouterLink
            v-for="chip in incident.nameReferenceChips"
            :key="`name-${chip.normalizedToken}`"
            class="ims-detail__chip ims-detail__chip--name-reference"
            :to="nameReferenceSearchTarget(chip)"
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
              <dt>Incident types</dt>
              <dd
                v-if="incident.incidentTypeNames.length > 0"
                class="ims-detail__type-list"
              >
                <span
                  v-for="typeName in incident.incidentTypeNames"
                  :key="typeName"
                  class="ims-detail__type-chip"
                >
                  {{ typeName }}
                </span>
              </dd>
              <dd v-else>Types not set</dd>
            </div>
            <div>
              <dt>Rangers/responders</dt>
              <dd>{{ responderText() }}</dd>
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
              <div class="ims-detail__timeline-meta">
                <time :datetime="entry.createdAt">
                  {{ formatIncidentDateTime(entry.createdAt) }}
                </time>
                <strong v-if="entry.actorName">{{ entry.actorName }}</strong>
              </div>
              <p v-if="timelineEntryBody(entry)">
                {{ timelineEntryBody(entry) }}
              </p>
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
  box-sizing: border-box;
  width: min(calc(100% - var(--m-space-8)), 72rem);
  margin-inline: auto;
  display: grid;
  gap: var(--m-space-4);
}

.ims-detail *,
.ims-detail *::before,
.ims-detail *::after {
  box-sizing: border-box;
}

.ims-detail__back,
.ims-detail__restricted a,
.ims-detail__edit {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}

.ims-detail__edit {
  justify-self: start;
  border: 1px solid var(--m-action-secondary-bg);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  text-decoration: none;
}

.ims-detail__restricted,
.ims-detail__missing {
  color: var(--m-text-muted);
}

.ims-detail__header {
  display: grid;
  gap: var(--m-space-4);
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
  font-size: clamp(var(--m-text-lg), 5vw, var(--m-text-xl));
  line-height: 1.15;
}

.ims-detail__status-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr));
  gap: var(--m-space-3);
  margin: 0;
}

.ims-detail__status-row div,
.ims-detail__panel {
  padding: var(--m-space-5);
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
  overflow-wrap: anywhere;
}

.ims-detail__state-pill,
.ims-detail__priority-pill,
.ims-detail__type-chip {
  display: inline-flex;
  min-height: 1.75rem;
  align-items: center;
  border-radius: var(--m-radius-sm);
  padding: 0 var(--m-space-2);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-detail__state-pill {
  border: 1px solid var(--m-border-default);
  color: var(--m-text-primary);
}

.ims-detail__priority-pill {
  border: 1px solid var(--m-border-default);
}

.ims-detail__priority-pill--routine {
  background: color-mix(in srgb, var(--m-border-default) 22%, transparent);
  color: var(--m-text-secondary);
}

.ims-detail__priority-pill--important {
  border-color: #facc15;
  background: #fef08a;
  color: #3f3000;
}

.ims-detail__priority-pill--serious {
  border-color: #c05621;
  background: #fed7aa;
  color: #7c2d12;
}

.ims-detail__priority-pill--critical {
  border-color: #b42318;
  background: #f04438;
  color: #ffffff;
}

.ims-detail__type-list {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.ims-detail__type-chip {
  border: 1px solid var(--m-action-secondary-bg);
  background: var(--m-surface-primary);
  color: var(--m-action-secondary-bg);
}

.ims-detail__chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
}

.ims-detail__chip {
  display: inline-flex;
  min-height: 2rem;
  align-items: center;
  border-radius: var(--m-radius-sm);
  padding: 0 var(--m-space-2);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-decoration: none;
}

.ims-detail__chip--tag {
  border: 1px solid var(--m-border-strong);
  background: var(--m-status-neutral-bg, var(--m-surface-primary));
  color: var(--m-text-secondary);
}

.ims-detail__chip--name-reference {
  border: 1px solid var(--m-action-secondary-bg);
  background: var(--m-surface-primary);
  color: var(--m-action-secondary-bg);
}

.ims-detail__chip:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-detail__edit:focus-visible {
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
  position: relative;
  display: grid;
  gap: var(--m-space-4);
  margin: 0;
  padding: 0 0 0 var(--m-space-5);
  list-style: none;
}

.ims-detail__timeline::before {
  position: absolute;
  inset: 0 auto 0 var(--m-space-2);
  width: 2px;
  background: var(--m-border-default);
  content: "";
}

.ims-detail__timeline li {
  position: relative;
  display: grid;
  gap: var(--m-space-2);
  min-width: 0;
  padding: 0 0 var(--m-space-1) var(--m-space-2);
}

.ims-detail__timeline-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.ims-detail__timeline-meta strong {
  color: var(--m-text-primary);
  font-weight: 800;
}

.ims-detail__timeline p {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.ims-detail__note-form {
  display: grid;
  gap: var(--m-space-3);
  margin-top: var(--m-space-5);
  padding-top: var(--m-space-5);
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

@media (max-width: 48rem) {
  .ims-detail {
    width: min(calc(100% - var(--m-space-4)), 72rem);
    gap: var(--m-space-4);
  }

  .ims-detail__header {
    gap: var(--m-space-3);
  }

  .ims-detail__status-row {
    grid-template-columns: 1fr;
  }

  .ims-detail__status-row div,
  .ims-detail__panel {
    padding: var(--m-space-4);
  }

  .ims-detail__timeline {
    padding-left: var(--m-space-4);
  }

  .ims-detail__timeline::before {
    left: var(--m-space-1);
  }
}
</style>
