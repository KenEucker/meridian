<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import AutosaveStatus, {
  type AutosaveStatusState,
} from "@/components/AutosaveStatus.vue";
import {
  appendIncidentNoteForSession,
  availableFieldReportOptionsForSession,
  availableLinkedIncidentOptionsForSession,
  blankIncidentAutosaveForm,
  canEditIncident,
  createIncidentFromAutosaveForm,
  findIncidentForSession,
  formatIncidentDateTime,
  hasIncidentCommandAccess,
  INCIDENT_PRIORITY_LABELS,
  INCIDENT_TYPE_OPTIONS,
  incidentToAutosaveForm,
  linkIncidentForSession,
  linkFieldReportForSession,
  RESPONDER_OPTIONS,
  resolveIncidentSession,
  statusLabel,
  unlinkFieldReportForSession,
  unlinkIncidentForSession,
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
const lastSavedForm = ref<IncidentAutosaveForm | null>(null);
const autosaveQueued = ref(false);
const incidentTypeAddQuery = ref("");
const responderAddQuery = ref("");
const incidentTypeAddOpen = ref(false);
const responderAddOpen = ref(false);
const linkedIncidentAddQuery = ref("");
const linkedIncidentAddOpen = ref(false);
const fieldReportAddQuery = ref("");
const fieldReportAddOpen = ref(false);
const incidentTypePicker = ref<HTMLElement | null>(null);
const responderPicker = ref<HTMLElement | null>(null);
const linkedIncidentPicker = ref<HTMLElement | null>(null);
const fieldReportPicker = ref<HTMLElement | null>(null);

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
const selectedIncidentTypes = computed(() => form.incidentTypeNames);
const availableIncidentTypeOptions = computed(() =>
  filteredAddOptions(
    INCIDENT_TYPE_OPTIONS.filter(
      (typeName) => !form.incidentTypeNames.includes(typeName),
    ),
    incidentTypeAddQuery.value,
  ),
);
const selectedResponders = computed(() =>
  RESPONDER_OPTIONS.filter((responder) =>
    form.responderStaffIds.includes(responder.staffId),
  ),
);
const selectedLinkedIncidents = computed(() => incident.value?.linkedIncidents ?? []);
const selectedAttachedFieldReports = computed(
  () => incident.value?.attachedFieldReports ?? [],
);
const availableResponderOptions = computed(() =>
  filteredAddOptions(
    RESPONDER_OPTIONS.filter(
      (responder) => !form.responderStaffIds.includes(responder.staffId),
    ),
    responderAddQuery.value,
    (responder) => responder.displayName,
  ),
);
const availableLinkedIncidentOptions = computed(() =>
  savedIncidentId.value
    ? availableLinkedIncidentOptionsForSession(
        session.value,
        savedIncidentId.value,
        linkedIncidentAddQuery.value,
      )
    : [],
);
const availableFieldReportOptions = computed(() =>
  savedIncidentId.value
    ? availableFieldReportOptionsForSession(
        session.value,
        savedIncidentId.value,
        fieldReportAddQuery.value,
      )
    : [],
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
    lastSavedSignature.value = formSignature(form);
    lastSavedForm.value = cloneAutosaveForm(form);
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

onMounted(() => {
  document.addEventListener("pointerdown", onDocumentPointerDown);
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", onDocumentPointerDown);
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

  if (formSignature(form) === lastSavedSignature.value) {
    autosaveState.value = "saved";
    autosaveMessage.value = null;
    return;
  }

  if (autosaveState.value === "saving") {
    autosaveQueued.value = true;
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
  autosaveQueued.value = false;

  try {
    const previousForm = lastSavedForm.value;
    const saved = savedIncidentId.value
      ? updateIncidentFromAutosaveForm(
          session.value,
          savedIncidentId.value,
          form,
        )
      : createIncidentFromAutosaveForm(session.value, form, previousForm);

    savedIncidentId.value = saved.id;
    lastSavedAt.value = saved.updatedAt;
    lastSavedSignature.value = signature;
    lastSavedForm.value = cloneAutosaveForm(form);
    autosaveState.value = "saved";
    autosaveMessage.value = null;
    revision.value += 1;

    if (route.name === "ims.incidents.create") {
      await router.replace({
        name: "ims.incidents.edit",
        params: { incidentId: saved.id },
      });
    }

    if (formSignature(form) !== signature || autosaveQueued.value) {
      commitAutosave();
    }
  } catch (error) {
    autosaveState.value = "failed";
    autosaveMessage.value =
      error instanceof Error ? error.message : "Unable to autosave incident.";
  }
}

function filteredAddOptions<T>(
  options: readonly T[],
  query: string,
  labelForOption: (option: T) => string = (option) => String(option),
): T[] {
  const normalizedQuery = query.trim().toLowerCase();
  const filtered =
    normalizedQuery.length === 0
      ? options
      : options.filter((option) =>
          labelForOption(option).toLowerCase().includes(normalizedQuery),
        );

  return filtered.slice(0, 6);
}

function addIncidentType(typeName: string): void {
  if (form.incidentTypeNames.includes(typeName)) {
    return;
  }

  form.incidentTypeNames = [...form.incidentTypeNames, typeName];
  incidentTypeAddQuery.value = "";
  incidentTypeAddOpen.value = false;
  commitAutosave();
}

function openIncidentTypeAdd(): void {
  incidentTypeAddOpen.value = true;
  responderAddOpen.value = false;
  linkedIncidentAddOpen.value = false;
  fieldReportAddOpen.value = false;
}

function addFirstIncidentTypeOption(): void {
  const [typeName] = availableIncidentTypeOptions.value;

  if (typeName) {
    addIncidentType(typeName);
  }
}

function removeIncidentType(typeName: string): void {
  form.incidentTypeNames = form.incidentTypeNames.filter(
    (selectedTypeName) => selectedTypeName !== typeName,
  );
  commitAutosave();
}

function addResponder(staffId: string): void {
  if (form.responderStaffIds.includes(staffId)) {
    return;
  }

  form.responderStaffIds = [...form.responderStaffIds, staffId];
  responderAddQuery.value = "";
  responderAddOpen.value = false;
  commitAutosave();
}

function openResponderAdd(): void {
  responderAddOpen.value = true;
  incidentTypeAddOpen.value = false;
  linkedIncidentAddOpen.value = false;
  fieldReportAddOpen.value = false;
}

function addFirstResponderOption(): void {
  const [responder] = availableResponderOptions.value;

  if (responder) {
    addResponder(responder.staffId);
  }
}

function removeResponder(staffId: string): void {
  form.responderStaffIds = form.responderStaffIds.filter(
    (selectedStaffId) => selectedStaffId !== staffId,
  );
  commitAutosave();
}

function openLinkedIncidentAdd(): void {
  linkedIncidentAddOpen.value = true;
  incidentTypeAddOpen.value = false;
  responderAddOpen.value = false;
  fieldReportAddOpen.value = false;
}

function addFirstLinkedIncidentOption(): void {
  const [linkedIncident] = availableLinkedIncidentOptions.value;

  if (linkedIncident) {
    addLinkedIncident(linkedIncident.id);
  }
}

function addLinkedIncident(targetIncidentId: string): void {
  if (!savedIncidentId.value) {
    return;
  }

  linkIncidentForSession(session.value, savedIncidentId.value, targetIncidentId);
  linkedIncidentAddQuery.value = "";
  linkedIncidentAddOpen.value = false;
  revision.value += 1;
}

function removeLinkedIncident(targetIncidentId: string): void {
  if (!savedIncidentId.value) {
    return;
  }

  unlinkIncidentForSession(session.value, savedIncidentId.value, targetIncidentId);
  revision.value += 1;
}

function openFieldReportAdd(): void {
  fieldReportAddOpen.value = true;
  incidentTypeAddOpen.value = false;
  responderAddOpen.value = false;
  linkedIncidentAddOpen.value = false;
}

function addFirstFieldReportOption(): void {
  const [fieldReport] = availableFieldReportOptions.value;

  if (fieldReport) {
    addFieldReport(fieldReport.id);
  }
}

function addFieldReport(fieldReportId: string): void {
  if (!savedIncidentId.value) {
    return;
  }

  linkFieldReportForSession(session.value, savedIncidentId.value, fieldReportId);
  fieldReportAddQuery.value = "";
  fieldReportAddOpen.value = false;
  revision.value += 1;
}

function removeFieldReport(fieldReportId: string): void {
  if (!savedIncidentId.value) {
    return;
  }

  unlinkFieldReportForSession(session.value, savedIncidentId.value, fieldReportId);
  revision.value += 1;
}

function closeAddPopups(): void {
  incidentTypeAddOpen.value = false;
  responderAddOpen.value = false;
  linkedIncidentAddOpen.value = false;
  fieldReportAddOpen.value = false;
}

function onDocumentPointerDown(event: PointerEvent): void {
  const target = event.target;

  if (!(target instanceof Node)) {
    closeAddPopups();
    return;
  }

  if (
    incidentTypePicker.value?.contains(target) ||
    responderPicker.value?.contains(target) ||
    linkedIncidentPicker.value?.contains(target) ||
    fieldReportPicker.value?.contains(target)
  ) {
    return;
  }

  closeAddPopups();
}

function formSignature(value: IncidentAutosaveForm): string {
  return JSON.stringify({
    title: value.title.trim(),
    status: value.status,
    priorityLabel: value.priorityLabel,
    incidentTypeNames: [...value.incidentTypeNames].sort(),
    responderStaffIds: [...value.responderStaffIds].sort(),
    startedAt: value.startedAt,
    locationName: value.locationName.trim(),
    locationAddress: value.locationAddress.trim(),
    locationDetails: value.locationDetails.trim(),
  });
}

function cloneAutosaveForm(value: IncidentAutosaveForm): IncidentAutosaveForm {
  return {
    title: value.title,
    status: value.status,
    priorityLabel: value.priorityLabel,
    incidentTypeNames: [...value.incidentTypeNames],
    responderStaffIds: [...value.responderStaffIds],
    startedAt: value.startedAt,
    locationName: value.locationName,
    locationAddress: value.locationAddress,
    locationDetails: value.locationDetails,
  };
}

function fieldLabel(field: string): string {
  const labels: Record<string, string> = {
    title: "Title",
    status: "State",
    priorityLabel: "Priority",
    incidentTypeNames: "Incident types",
    responders: "Responders",
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
        <section class="ims-edit__panel" aria-label="Incident details">
          <div class="ims-edit__detail-grid">
            <div class="ims-edit__field ims-edit__field--readonly">
              <span>IMS #</span>
              <output>{{ incident?.incidentNumber ?? "Not assigned yet" }}</output>
            </div>

            <label class="ims-edit__field">
              <span>State</span>
              <select
                id="ims-edit-status"
                v-model="form.status"
                :disabled="isOfflineBlocked"
                @change="commitAutosave"
              >
                <option value="open">{{ statusLabel("open") }}</option>
                <option value="on_scene">{{ statusLabel("on_scene") }}</option>
                <option value="monitoring">
                  {{ statusLabel("monitoring") }}
                </option>
                <option value="on_hold">{{ statusLabel("on_hold") }}</option>
                <option value="closed">{{ statusLabel("closed") }}</option>
              </select>
            </label>

            <label class="ims-edit__field">
              <span>Priority</span>
              <select
                id="ims-edit-priority"
                v-model="form.priorityLabel"
                class="ims-edit__priority-select"
                :disabled="isOfflineBlocked"
                @change="commitAutosave"
              >
                <option
                  v-for="priority in INCIDENT_PRIORITY_LABELS"
                  :key="priority"
                  :value="priority"
                >
                  {{ priority }}
                </option>
              </select>
            </label>

            <label class="ims-edit__field ims-edit__field--started">
              <span>Started</span>
              <input
                id="ims-edit-started"
                v-model="form.startedAt"
                type="datetime-local"
                :disabled="isOfflineBlocked"
                @blur="commitAutosave"
              />
            </label>
          </div>

          <label class="ims-edit__field ims-edit__field--summary">
            <span>Summary</span>
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
        </section>

        <div class="ims-edit__panel-grid">
          <section
            ref="responderPicker"
            class="ims-edit__panel"
            :class="{ 'ims-edit__panel--popup-open': responderAddOpen }"
            role="group"
            aria-labelledby="ims-edit-responders-heading"
          >
            <div class="ims-edit__panel-heading">
              <h2 id="ims-edit-responders-heading">Responders</h2>
            </div>
            <div id="ims-edit-responders" class="ims-edit__selected-list">
              <div
                v-for="responder in selectedResponders"
                :key="responder.staffId"
                class="ims-edit__selected-row"
              >
                <span>{{ responder.displayName }}</span>
                <button
                  type="button"
                  class="ims-edit__remove-button"
                  :disabled="isOfflineBlocked"
                  :aria-label="`Remove responder ${responder.displayName}`"
                  @click="removeResponder(responder.staffId)"
                >
                  X
                </button>
              </div>
              <p
                v-if="selectedResponders.length === 0"
                class="ims-edit__empty-row"
              >
                No responders selected.
              </p>
            </div>
            <label class="ims-edit__add-row">
              <span>Add</span>
              <input
                id="ims-edit-responder-add"
                v-model="responderAddQuery"
                type="search"
                autocomplete="off"
                :disabled="isOfflineBlocked"
                @focus="openResponderAdd"
                @click="openResponderAdd"
                @input="openResponderAdd"
                @keydown.enter.prevent="addFirstResponderOption"
                @keydown.escape.prevent="closeAddPopups"
              />
            </label>
            <div
              v-if="responderAddOpen && availableResponderOptions.length > 0"
              class="ims-edit__add-results"
              aria-label="Responder matches"
            >
              <button
                v-for="responder in availableResponderOptions"
                :key="responder.staffId"
                type="button"
                :disabled="isOfflineBlocked"
                @click="addResponder(responder.staffId)"
              >
                {{ responder.displayName }}
              </button>
            </div>
          </section>

          <section
            ref="incidentTypePicker"
            class="ims-edit__panel"
            :class="{ 'ims-edit__panel--popup-open': incidentTypeAddOpen }"
            role="group"
            aria-labelledby="ims-edit-types-heading"
          >
            <div class="ims-edit__panel-heading">
              <h2 id="ims-edit-types-heading">Incident types</h2>
            </div>
            <div id="ims-edit-types" class="ims-edit__selected-list">
              <div
                v-for="typeName in selectedIncidentTypes"
                :key="typeName"
                class="ims-edit__selected-row"
              >
                <span>{{ typeName }}</span>
                <button
                  type="button"
                  class="ims-edit__remove-button"
                  :disabled="isOfflineBlocked"
                  :aria-label="`Remove incident type ${typeName}`"
                  @click="removeIncidentType(typeName)"
                >
                  X
                </button>
              </div>
              <p
                v-if="selectedIncidentTypes.length === 0"
                class="ims-edit__empty-row"
              >
                No incident types selected.
              </p>
            </div>
            <label class="ims-edit__add-row">
              <span>Add</span>
              <input
                id="ims-edit-type-add"
                v-model="incidentTypeAddQuery"
                type="search"
                autocomplete="off"
                :disabled="isOfflineBlocked"
                @focus="openIncidentTypeAdd"
                @click="openIncidentTypeAdd"
                @input="openIncidentTypeAdd"
                @keydown.enter.prevent="addFirstIncidentTypeOption"
                @keydown.escape.prevent="closeAddPopups"
              />
            </label>
            <div
              v-if="incidentTypeAddOpen && availableIncidentTypeOptions.length > 0"
              class="ims-edit__add-results"
              aria-label="Incident type matches"
            >
              <button
                v-for="typeName in availableIncidentTypeOptions"
                :key="typeName"
                type="button"
                :disabled="isOfflineBlocked"
                @click="addIncidentType(typeName)"
              >
                {{ typeName }}
              </button>
            </div>
          </section>
        </div>

        <section
          v-if="incident"
          ref="linkedIncidentPicker"
          class="ims-edit__panel"
          :class="{ 'ims-edit__panel--popup-open': linkedIncidentAddOpen }"
          role="group"
          aria-labelledby="ims-edit-linked-heading"
        >
          <div class="ims-edit__panel-heading">
            <h2 id="ims-edit-linked-heading">Linked incidents</h2>
          </div>
          <div id="ims-edit-linked" class="ims-edit__selected-list">
            <div
              v-for="linkedIncident in selectedLinkedIncidents"
              :key="linkedIncident.id"
              class="ims-edit__selected-row ims-edit__selected-row--linked"
            >
              <RouterLink
                :to="{
                  name: 'ims.incidents.show',
                  params: { incidentId: linkedIncident.id },
                }"
              >
                <span>{{ linkedIncident.incidentNumber }}</span>
                <strong>{{ linkedIncident.title || "Untitled incident" }}</strong>
                <em>{{ statusLabel(linkedIncident.status) }}</em>
              </RouterLink>
              <button
                type="button"
                class="ims-edit__remove-button"
                :disabled="isOfflineBlocked"
                :aria-label="`Unlink incident ${linkedIncident.incidentNumber}`"
                @click="removeLinkedIncident(linkedIncident.id)"
              >
                X
              </button>
            </div>
            <p
              v-if="selectedLinkedIncidents.length === 0"
              class="ims-edit__empty-row"
            >
              No linked incidents.
            </p>
          </div>
          <label class="ims-edit__add-row">
            <span>Add</span>
            <input
              id="ims-edit-linked-add"
              v-model="linkedIncidentAddQuery"
              type="search"
              autocomplete="off"
              :disabled="isOfflineBlocked"
              @focus="openLinkedIncidentAdd"
              @click="openLinkedIncidentAdd"
              @input="openLinkedIncidentAdd"
              @keydown.enter.prevent="addFirstLinkedIncidentOption"
              @keydown.escape.prevent="closeAddPopups"
            />
          </label>
          <div
            v-if="
              linkedIncidentAddOpen && availableLinkedIncidentOptions.length > 0
            "
            class="ims-edit__add-results"
            aria-label="Linked incident matches"
          >
            <button
              v-for="linkedIncident in availableLinkedIncidentOptions"
              :key="linkedIncident.id"
              type="button"
              :disabled="isOfflineBlocked"
              @click="addLinkedIncident(linkedIncident.id)"
            >
              {{ linkedIncident.incidentNumber }} -
              {{ linkedIncident.title || "Untitled incident" }}
            </button>
          </div>
        </section>

        <section
          v-if="incident"
          ref="fieldReportPicker"
          class="ims-edit__panel"
          :class="{ 'ims-edit__panel--popup-open': fieldReportAddOpen }"
          role="group"
          aria-labelledby="ims-edit-field-reports-heading"
        >
          <div class="ims-edit__panel-heading">
            <h2 id="ims-edit-field-reports-heading">Attached Field Reports</h2>
          </div>
          <div id="ims-edit-field-reports" class="ims-edit__selected-list">
            <div
              v-for="fieldReport in selectedAttachedFieldReports"
              :key="fieldReport.id"
              class="ims-edit__selected-row ims-edit__selected-row--field-report"
            >
              <span>{{ fieldReport.displayNumber }}</span>
              <strong>{{ fieldReport.title }}</strong>
              <button
                type="button"
                class="ims-edit__remove-button"
                :disabled="isOfflineBlocked"
                :aria-label="`Unlink Field Report ${fieldReport.displayNumber}`"
                @click="removeFieldReport(fieldReport.id)"
              >
                X
              </button>
            </div>
            <p
              v-if="selectedAttachedFieldReports.length === 0"
              class="ims-edit__empty-row"
            >
              No attached Field Reports.
            </p>
          </div>
          <label class="ims-edit__add-row">
            <span>Add</span>
            <input
              id="ims-edit-field-report-add"
              v-model="fieldReportAddQuery"
              type="search"
              autocomplete="off"
              :disabled="isOfflineBlocked"
              @focus="openFieldReportAdd"
              @click="openFieldReportAdd"
              @input="openFieldReportAdd"
              @keydown.enter.prevent="addFirstFieldReportOption"
              @keydown.escape.prevent="closeAddPopups"
            />
          </label>
          <div
            v-if="fieldReportAddOpen && availableFieldReportOptions.length > 0"
            class="ims-edit__add-results"
            aria-label="Field Report matches"
          >
            <button
              v-for="fieldReport in availableFieldReportOptions"
              :key="fieldReport.id"
              type="button"
              :disabled="isOfflineBlocked"
              @click="addFieldReport(fieldReport.id)"
            >
              {{ fieldReport.displayNumber }} -
              {{ fieldReport.title }}
            </button>
          </div>
        </section>

        <section class="ims-edit__panel" aria-labelledby="ims-edit-location-heading">
          <div class="ims-edit__panel-heading">
            <h2 id="ims-edit-location-heading">Location</h2>
          </div>
          <div class="ims-edit__location-grid">
            <label class="ims-edit__field">
              <span>Name</span>
              <input
                id="ims-edit-location-name"
                v-model="form.locationName"
                type="text"
                :disabled="isOfflineBlocked"
                @blur="commitAutosave"
              />
            </label>

            <label class="ims-edit__field">
              <span>Address</span>
              <input
                id="ims-edit-location-address"
                v-model="form.locationAddress"
                type="text"
                :disabled="isOfflineBlocked"
                @blur="commitAutosave"
              />
            </label>

            <label class="ims-edit__field ims-edit__field--details">
              <span>Details</span>
              <input
                id="ims-edit-location-details"
                v-model="form.locationDetails"
                type="text"
                :disabled="isOfflineBlocked"
                @blur="commitAutosave"
              />
            </label>
          </div>
        </section>
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
            <p
              v-if="timelineEntryBody(entry)"
              :class="{
                'ims-edit__timeline-body--stricken': entry.strickenAt,
              }"
            >
              {{ timelineEntryBody(entry) }}
            </p>
            <p
              v-if="entry.strickenAt"
              class="ims-edit__timeline-stricken-reason"
            >
              Stricken: {{ entry.strickenReason ?? "Removed from incident." }}
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

.ims-edit__form {
  display: grid;
  gap: var(--m-space-3);
}

.ims-edit__panel,
.ims-edit__timeline {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-edit__timeline {
  overflow: hidden;
}

.ims-edit__panel {
  display: grid;
  position: relative;
  overflow: visible;
}

.ims-edit__panel--popup-open {
  z-index: 20;
}

.ims-edit__panel-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 2.25rem;
  border-bottom: 1px solid var(--m-border-default);
  padding: var(--m-space-1) var(--m-space-3);
  background: color-mix(
    in srgb,
    var(--m-surface-raised) 84%,
    var(--m-border-default)
  );
}

.ims-edit__panel-heading h2,
.ims-edit__timeline h2 {
  margin: 0;
  font-size: var(--m-text-md);
  font-weight: 900;
}

.ims-edit__detail-grid {
  display: grid;
  grid-template-columns:
    minmax(6rem, 0.75fr) minmax(7.5rem, 1fr)
    minmax(7.5rem, 1fr) minmax(11rem, 1.35fr);
  gap: var(--m-space-2);
  padding: var(--m-space-2);
}

.ims-edit__panel-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--m-space-3);
}

.ims-edit__location-grid {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-2);
}

.ims-edit__field {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  min-width: 0;
  min-height: 2.75rem;
  overflow: hidden;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-primary);
}

.ims-edit__field--summary {
  margin: 0 var(--m-space-2) var(--m-space-2);
}

.ims-edit__field--details {
  min-height: 4.5rem;
}

.ims-edit__field span,
.ims-edit__note-form label {
  display: flex;
  align-items: center;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-edit__field span {
  border-right: 1px solid var(--m-border-default);
  padding: var(--m-space-2) var(--m-space-3);
  background: color-mix(
    in srgb,
    var(--m-surface-raised) 88%,
    var(--m-border-default)
  );
  white-space: nowrap;
}

.ims-edit__form input,
.ims-edit__form select,
.ims-edit__form textarea,
.ims-edit__note-form textarea {
  width: 100%;
  min-width: 0;
  border: 0;
  border-radius: 0;
  padding: var(--m-space-2) var(--m-space-3);
  background: transparent;
  color: var(--m-text-primary);
  font: inherit;
}

.ims-edit__field output {
  min-width: 0;
  padding: var(--m-space-2) var(--m-space-3);
  overflow: hidden;
  color: var(--m-text-primary);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ims-edit__priority-select {
  font-weight: 800;
}

.ims-edit__selected-list {
  display: grid;
  gap: 0;
  padding: 0 var(--m-space-3);
}

.ims-edit__selected-row,
.ims-edit__empty-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--m-space-2);
  align-items: center;
  min-height: 2.625rem;
  margin: 0;
  border-bottom: 1px solid var(--m-border-default);
  padding: var(--m-space-1) var(--m-space-1);
  background: transparent;
  color: var(--m-text-primary);
}

.ims-edit__selected-row:last-child,
.ims-edit__empty-row:last-child {
  border-bottom: 0;
}

.ims-edit__selected-row span,
.ims-edit__empty-row {
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
  font-weight: 800;
  overflow-wrap: anywhere;
}

.ims-edit__selected-row--linked > a {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr) max-content;
  gap: var(--m-space-2);
  align-items: center;
  min-width: 0;
  color: var(--m-text-primary);
  text-decoration: none;
}

.ims-edit__selected-row--linked > a span,
.ims-edit__selected-row--linked > a strong,
.ims-edit__selected-row--linked > a em {
  min-width: 0;
  overflow-wrap: anywhere;
}

.ims-edit__selected-row--linked > a span,
.ims-edit__selected-row--linked > a em {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
}

.ims-edit__selected-row--linked > a em {
  font-style: normal;
}

.ims-edit__selected-row--linked > a:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-edit__selected-row--field-report {
  grid-template-columns: max-content minmax(0, 1fr) auto;
}

.ims-edit__selected-row--field-report strong {
  min-width: 0;
  overflow-wrap: anywhere;
}

.ims-edit__empty-row {
  grid-template-columns: 1fr;
  color: var(--m-text-muted);
}

.ims-edit__remove-button {
  display: inline-grid;
  width: 1.75rem;
  min-width: 1.75rem;
  height: 1.75rem;
  place-items: center;
  border: 0;
  border-radius: var(--m-radius-sm);
  background: var(--m-status-danger);
  color: var(--m-action-primary-text);
  font-size: var(--m-text-sm);
  font-weight: 900;
  line-height: 1;
}

.ims-edit__add-row {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  align-items: center;
  min-height: 3rem;
  margin: 0 var(--m-space-3) var(--m-space-2);
  overflow: hidden;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-primary);
}

.ims-edit__add-row span {
  display: flex;
  align-items: center;
  height: 100%;
  border-right: 1px solid var(--m-border-default);
  padding: var(--m-space-2) var(--m-space-3);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
  white-space: nowrap;
}

.ims-edit__add-results {
  display: grid;
  position: absolute;
  inset-inline: var(--m-space-3);
  top: calc(100% - var(--m-space-1));
  z-index: 30;
  max-height: 12rem;
  overflow-y: auto;
  margin: 0;
  border: 1px solid var(--m-border-strong);
  border-radius: var(--m-radius-sm);
  background: color-mix(
    in srgb,
    var(--m-surface-overlay) 78%,
    var(--m-action-secondary-bg)
  );
  box-shadow: var(--m-shadow-overlay);
}

.ims-edit__add-results button {
  min-height: 2.5rem;
  border: 0;
  border-bottom: 1px solid var(--m-border-default);
  padding: var(--m-space-2) var(--m-space-3);
  background: color-mix(
    in srgb,
    var(--m-surface-overlay) 78%,
    var(--m-action-secondary-bg)
  );
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-align: left;
}

.ims-edit__add-results button:last-child {
  border-bottom: 0;
}

.ims-edit__form textarea,
.ims-edit__note-form textarea {
  resize: vertical;
}

.ims-edit__form input:focus-visible,
.ims-edit__form select:focus-visible,
.ims-edit__form textarea:focus-visible,
.ims-edit__remove-button:focus-visible,
.ims-edit__add-results button:focus-visible,
.ims-edit__note-form textarea:focus-visible,
.ims-edit__note-form button:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-edit__timeline {
  display: grid;
  gap: var(--m-space-3);
  padding-bottom: var(--m-space-3);
}

.ims-edit__timeline > h2 {
  min-height: 2.25rem;
  border-bottom: 1px solid var(--m-border-default);
  padding: var(--m-space-1) var(--m-space-3);
  background: color-mix(
    in srgb,
    var(--m-surface-raised) 84%,
    var(--m-border-default)
  );
}

.ims-edit__timeline ol {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: 0 var(--m-space-3) 0 var(--m-space-6);
  list-style: none;
}

.ims-edit__timeline li {
  position: relative;
  display: grid;
  gap: var(--m-space-2);
  width: 100%;
  min-width: 0;
  padding: var(--m-space-1) 0 var(--m-space-2) var(--m-space-4);
}

.ims-edit__timeline li::before {
  position: absolute;
  inset: var(--m-space-1) auto var(--m-space-1) 0;
  width: 2px;
  border-radius: 999px;
  background: var(--m-border-strong);
  content: "";
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

.ims-edit__timeline-body--stricken {
  color: var(--m-text-muted);
  text-decoration: line-through;
}

.ims-edit__timeline-stricken-reason {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-edit__note-form {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 var(--m-space-3);
  padding-top: var(--m-space-3);
  border-top: 1px solid var(--m-border-default);
}

.ims-edit__note-form textarea {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-primary);
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

  .ims-edit__timeline ol {
    padding-left: var(--m-space-5);
  }
}

@media (max-width: 42rem) {
  .ims-edit__detail-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 34rem) {
  .ims-edit__panel-grid {
    grid-template-columns: 1fr;
  }

  .ims-edit__selected-row--linked > a {
    grid-template-columns: 1fr;
  }

  .ims-edit__selected-row--field-report {
    grid-template-columns: max-content minmax(0, 1fr) auto;
  }
}
</style>
