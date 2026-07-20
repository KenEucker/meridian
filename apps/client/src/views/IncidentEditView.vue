<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import AutosaveStatus, {
  type AutosaveStatusState,
} from "@/components/AutosaveStatus.vue";
import {
  appendIncidentNoteForSession,
  blankIncidentAutosaveForm,
  canEditIncident,
  createIncidentFromAutosaveForm,
  findIncidentForSession,
  formatIncidentDateTime,
  hasIncidentCommandAccess,
  incidentToAutosaveForm,
  resolveIncidentSession,
  statusLabel,
  updateIncidentFromAutosaveForm,
  type IncidentAutosaveForm,
  type IncidentTagChip,
  type IncidentTimelineEntry,
  type NameReferenceChip,
} from "@/ims/incidentReadModel";
import { useConnectivity } from "@/offline/useConnectivity";

const route = useRoute();
const router = useRouter();
const connectivity = useConnectivity();
const session = computed(() => resolveIncidentSession());
const canView = computed(() => hasIncidentCommandAccess(session.value));
const canEdit = computed(() => canEditIncident(session.value));
const routeIncidentId = computed(() =>
  typeof route.params.incidentId === "string" ? route.params.incidentId : null,
);
const savedIncidentId = ref<string | null>(routeIncidentId.value);
const form = reactive<IncidentAutosaveForm>(blankIncidentAutosaveForm());
const autosaveState = ref<AutosaveStatusState>("saved");
const autosaveMessage = ref<string | null>(null);
const lastSavedAt = ref<string | null>(null);
const noteBody = ref("");
const noteError = ref<string | null>(null);
const revision = ref(0);
const lastSavedSignature = ref<string | null>(null);

const incident = computed(() => {
  revision.value;

  return savedIncidentId.value
    ? findIncidentForSession(session.value, savedIncidentId.value)
    : null;
});
const isOfflineBlocked = computed(() => connectivity.value !== "online");
const timelineEntries = computed(() => incident.value?.timelineEntries ?? []);
const showAutosaveStatus = computed(
  () => autosaveState.value !== "saved" || autosaveMessage.value !== null,
);

watch(
  () => routeIncidentId.value,
  (incidentId) => {
    savedIncidentId.value = incidentId;
    const existing = incidentId
      ? findIncidentForSession(session.value, incidentId)
      : null;
    Object.assign(
      form,
      existing ? incidentToAutosaveForm(existing) : blankIncidentAutosaveForm(),
    );
    autosaveState.value = isOfflineBlocked.value ? "blocked_offline" : "saved";
    autosaveMessage.value = isOfflineBlocked.value
      ? "Incident create/edit requires server connection. Your typed form remains on this screen."
      : null;
    lastSavedAt.value = existing?.updatedAt ?? null;
    lastSavedSignature.value = existing ? formSignature(form) : null;
  },
  { immediate: true },
);

watch(isOfflineBlocked, (blocked) => {
  if (blocked) {
    autosaveState.value = "blocked_offline";
    autosaveMessage.value =
      "Incident create/edit requires server connection. Your typed form remains on this screen.";
  } else if (autosaveState.value === "blocked_offline") {
    autosaveState.value = "saved";
    autosaveMessage.value = null;
  }
});

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

function commitAutosave(): void {
  if (!canEdit.value) {
    return;
  }

  if (!savedIncidentId.value && form.title.trim() === "") {
    autosaveState.value = "saved";
    autosaveMessage.value = null;
    return;
  }

  if (formSignature(form) === lastSavedSignature.value) {
    autosaveState.value = "saved";
    autosaveMessage.value = null;
    return;
  }

  if (isOfflineBlocked.value) {
    autosaveState.value = "blocked_offline";
    autosaveMessage.value =
      "Incident create/edit requires server connection. Your typed form remains on this screen.";
    return;
  }

  autosaveState.value = "saving";
  autosaveMessage.value = null;
  void autosave();
}

async function autosave(): Promise<void> {
  const signature = formSignature(form);

  try {
    const saved = savedIncidentId.value
      ? updateIncidentFromAutosaveForm(
          session.value,
          savedIncidentId.value,
          form,
        )
      : createIncidentFromAutosaveForm(session.value, form);

    savedIncidentId.value = saved.id;
    lastSavedAt.value = saved.updatedAt;
    lastSavedSignature.value = signature;
    autosaveState.value = "saved";
    autosaveMessage.value = null;
    revision.value += 1;

    if (route.name === "ims.incidents.create") {
      await router.replace({
        name: "ims.incidents.edit",
        params: { incidentId: saved.id },
      });
    }
  } catch (error) {
    autosaveState.value = "failed";
    autosaveMessage.value =
      error instanceof Error ? error.message : "Unable to autosave incident.";
  }
}

function formSignature(value: IncidentAutosaveForm): string {
  return JSON.stringify({
    title: value.title.trim(),
    status: value.status,
    startedAt: value.startedAt,
    locationName: value.locationName.trim(),
    locationAddress: value.locationAddress.trim(),
    locationDetails: value.locationDetails.trim(),
  });
}

function fieldLabel(field: string): string {
  const labels: Record<string, string> = {
    title: "Title",
    status: "State",
    startedAt: "Started",
    started_at: "Started",
    locationName: "Location name",
    location_name: "Location name",
    locationAddress: "Location address",
    location_address: "Location address",
    locationDetails: "Location details",
    location_details: "Location details",
    campId: "Camp",
    camp_id: "Camp",
    mapLocationId: "Map location",
    map_location_id: "Map location",
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

  return field === "status" ? statusLabel(value as IncidentAutosaveForm["status"]) : value;
}

function onAppendNote(): void {
  noteError.value = null;

  if (!savedIncidentId.value) {
    noteError.value = "Create the incident before adding notes.";
    return;
  }

  try {
    appendIncidentNoteForSession(
      session.value,
      savedIncidentId.value,
      noteBody.value,
    );
    noteBody.value = "";
    revision.value += 1;
  } catch (error) {
    noteError.value =
      error instanceof Error ? error.message : "Unable to add incident note.";
  }
}
</script>

<template>
  <section class="ims-edit" aria-labelledby="ims-edit-heading">
    <div v-if="!canView || !canEdit" class="ims-edit__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        IC operator or lead access required
      </RouterLink>
    </div>

    <div
      v-else-if="routeIncidentId && !incident"
      class="ims-edit__restricted"
      role="status"
    >
      Incident not found for this event.
    </div>

    <template v-else>
      <RouterLink class="ims-edit__back" :to="{ name: 'ims.incidents.index' }">
        Back to incidents
      </RouterLink>

      <header class="ims-edit__header">
        <div>
          <p class="ims-edit__eyebrow">Incident Management System</p>
          <h1 id="ims-edit-heading" class="ims-edit__heading">
            {{ incident ? "Edit incident" : "Create incident" }}
          </h1>
        </div>
        <p class="ims-edit__incident-number">
          {{ incident?.incidentNumber ?? "Not assigned yet" }}
        </p>
        <nav
          v-if="
            incident &&
            (incident.tagChips.length > 0 ||
              incident.nameReferenceChips.length > 0)
          "
          class="ims-edit__chips"
          aria-label="Incident metadata chips"
        >
          <RouterLink
            v-for="chip in incident.tagChips"
            :key="`tag-${chip.normalizedTag}`"
            class="ims-edit__chip ims-edit__chip--tag"
            :to="tagSearchTarget(chip)"
            :aria-label="`Search incidents for tag ${chip.tag}`"
          >
            #{{ chip.tag }}
          </RouterLink>
          <RouterLink
            v-for="chip in incident.nameReferenceChips"
            :key="`name-${chip.normalizedToken}`"
            class="ims-edit__chip ims-edit__chip--name-reference"
            :to="nameReferenceSearchTarget(chip)"
            :aria-label="`Search incidents for Name Reference ${chip.token}`"
          >
            @{{ chip.token }}
          </RouterLink>
        </nav>
      </header>

      <AutosaveStatus
        v-if="showAutosaveStatus"
        :state="autosaveState"
        :last-saved-at="lastSavedAt"
        :message="autosaveMessage"
        repair-href="/readiness"
      />

      <form class="ims-edit__form" aria-label="Incident autosave form">
        <label>
          <span>Title</span>
          <input
            id="ims-edit-title"
            v-model="form.title"
            type="text"
            maxlength="200"
            required
            :disabled="isOfflineBlocked"
            @blur="commitAutosave"
          />
        </label>

        <label>
          <span>State</span>
          <select
            id="ims-edit-status"
            v-model="form.status"
            :disabled="isOfflineBlocked"
            @change="commitAutosave"
          >
            <option value="open">{{ statusLabel("open") }}</option>
            <option value="on_scene">{{ statusLabel("on_scene") }}</option>
            <option value="monitoring">{{ statusLabel("monitoring") }}</option>
            <option value="on_hold">{{ statusLabel("on_hold") }}</option>
            <option value="closed">{{ statusLabel("closed") }}</option>
          </select>
        </label>

        <label>
          <span>Started</span>
          <input
            id="ims-edit-started"
            v-model="form.startedAt"
            type="datetime-local"
            :disabled="isOfflineBlocked"
            @blur="commitAutosave"
          />
        </label>

        <label>
          <span>Location name</span>
          <input
            id="ims-edit-location-name"
            v-model="form.locationName"
            type="text"
            :disabled="isOfflineBlocked"
            @blur="commitAutosave"
          />
        </label>

        <label>
          <span>Location address</span>
          <input
            id="ims-edit-location-address"
            v-model="form.locationAddress"
            type="text"
            :disabled="isOfflineBlocked"
            @blur="commitAutosave"
          />
        </label>

        <label class="ims-edit__wide">
          <span>Location details</span>
          <textarea
            id="ims-edit-location-details"
            v-model="form.locationDetails"
            rows="4"
            :disabled="isOfflineBlocked"
            @blur="commitAutosave"
          />
        </label>
      </form>

      <section
        v-if="incident"
        class="ims-edit__timeline"
        aria-labelledby="ims-edit-timeline-heading"
      >
        <h2 id="ims-edit-timeline-heading">Timeline</h2>
        <ol>
          <li v-for="entry in timelineEntries" :key="entry.id">
            <div class="ims-edit__timeline-meta">
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

        <form class="ims-edit__note-form" @submit.prevent="onAppendNote">
          <label for="ims-edit-note">Operational note</label>
          <textarea id="ims-edit-note" v-model="noteBody" rows="4" />
          <p v-if="noteError" class="ims-edit__error" role="alert">
            {{ noteError }}
          </p>
          <button type="submit">Add note</button>
        </form>
      </section>
    </template>
  </section>
</template>

<style scoped>
.ims-edit {
  box-sizing: border-box;
  width: min(calc(100% - var(--m-space-8)), 72rem);
  margin-inline: auto;
  display: grid;
  gap: var(--m-space-4);
}

.ims-edit *,
.ims-edit *::before,
.ims-edit *::after {
  box-sizing: border-box;
}

.ims-edit__back,
.ims-edit__restricted a {
  color: var(--m-action-secondary-bg);
  font-weight: 800;
}

.ims-edit__restricted {
  color: var(--m-text-muted);
}

.ims-edit__header {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: start;
  gap: var(--m-space-3) var(--m-space-4);
}

.ims-edit__header > div {
  min-width: 0;
}

.ims-edit__eyebrow,
.ims-edit__heading,
.ims-edit__incident-number {
  margin: 0;
}

.ims-edit__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-transform: uppercase;
}

.ims-edit__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.ims-edit__incident-number {
  align-self: center;
  color: var(--m-text-secondary);
  font-weight: 800;
  white-space: nowrap;
}

.ims-edit__chips {
  display: flex;
  grid-column: 1 / -1;
  flex: 1 1 100%;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
}

.ims-edit__chip {
  display: inline-flex;
  min-height: 2rem;
  align-items: center;
  border-radius: var(--m-radius-sm);
  padding: 0 var(--m-space-2);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-decoration: none;
}

.ims-edit__chip--tag {
  border: 1px solid var(--m-border-strong);
  background: var(--m-status-neutral-bg, var(--m-surface-primary));
  color: var(--m-text-secondary);
}

.ims-edit__chip--name-reference {
  border: 1px solid var(--m-action-secondary-bg);
  background: var(--m-surface-primary);
  color: var(--m-action-secondary-bg);
}

.ims-edit__chip:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-edit__form,
.ims-edit__timeline {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-edit__form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr));
  gap: var(--m-space-4);
  padding: var(--m-space-5);
}

.ims-edit__form label,
.ims-edit__note-form {
  display: grid;
  gap: var(--m-space-2);
}

.ims-edit__wide {
  grid-column: 1 / -1;
}

.ims-edit__form span,
.ims-edit__note-form label {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-edit__form input,
.ims-edit__form select,
.ims-edit__form textarea,
.ims-edit__note-form textarea {
  width: 100%;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-3);
  background: var(--m-surface-primary);
  color: var(--m-text-primary);
  font: inherit;
}

.ims-edit__form textarea,
.ims-edit__note-form textarea {
  resize: vertical;
}

.ims-edit__form input:focus-visible,
.ims-edit__form select:focus-visible,
.ims-edit__form textarea:focus-visible,
.ims-edit__note-form textarea:focus-visible,
.ims-edit__note-form button:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-edit__timeline {
  display: grid;
  gap: var(--m-space-4);
  padding: var(--m-space-5);
}

.ims-edit__timeline h2 {
  margin: 0;
  font-size: var(--m-text-lg);
}

.ims-edit__timeline ol {
  position: relative;
  display: grid;
  gap: var(--m-space-4);
  margin: 0;
  padding: 0 0 0 var(--m-space-5);
  list-style: none;
}

.ims-edit__timeline ol::before {
  position: absolute;
  inset: 0 auto 0 var(--m-space-2);
  width: 2px;
  background: var(--m-border-default);
  content: "";
}

.ims-edit__timeline li {
  position: relative;
  display: grid;
  gap: var(--m-space-2);
  width: 100%;
  min-width: 0;
  padding: 0 0 var(--m-space-1) var(--m-space-2);
}

.ims-edit__timeline-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.ims-edit__timeline-meta strong {
  color: var(--m-text-primary);
  font-weight: 800;
}

.ims-edit__timeline p {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.ims-edit__note-form {
  padding-top: var(--m-space-5);
  border-top: 1px solid var(--m-border-default);
}

.ims-edit__note-form button {
  justify-self: start;
  border: 0;
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font-weight: 800;
}

.ims-edit__error {
  margin: 0;
  color: var(--m-status-danger);
  font-size: var(--m-text-sm);
}

@media (max-width: 48rem) {
  .ims-edit {
    width: min(calc(100% - var(--m-space-4)), 72rem);
    gap: var(--m-space-4);
  }

  .ims-edit__header {
    grid-template-columns: 1fr;
    gap: var(--m-space-2);
  }

  .ims-edit__incident-number {
    justify-self: start;
  }

  .ims-edit__form,
  .ims-edit__timeline {
    padding: var(--m-space-4);
  }

  .ims-edit__timeline ol {
    padding-left: var(--m-space-4);
  }

  .ims-edit__timeline ol::before {
    left: var(--m-space-1);
  }
}
</style>
