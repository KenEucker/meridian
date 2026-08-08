<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import AutosaveStatus, {
  type AutosaveStatusState,
} from "@/components/AutosaveStatus.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { downloadIncidentPdf } from "@/ims/downloadIncidentPdf";
import {
  appendIncidentNote,
  blankIncidentAutosaveForm,
  createIncident,
  formatIncidentDateTime,
  getEventFieldReports,
  getEventIncident,
  getEventIncidents,
  incidentAccess,
  incidentSessionContext,
  incidentToAutosaveForm,
  linkFieldReport,
  linkIncident,
  orderedFieldReportCandidates,
  orderedLinkCandidates,
  statusLabel,
  strikeIncidentAttachment,
  strikeIncidentNote,
  unlinkFieldReport,
  unlinkIncident,
  updateIncident,
  visibleIncidentTimelineEntries,
  type FieldReportLinkCandidate,
  type ImsIncident,
  type IncidentAssignableOptions,
  type IncidentAttachment,
  type IncidentAutosaveForm,
  type IncidentTagChip,
  type IncidentTimelineEntry,
  type NameReferenceChip,
} from "@/ims/incidentReadModel";
import { useConnectivity } from "@/offline/useConnectivity";

/**
 * `ims.incidents.create`, `ims.incidents.edit`, and `ims.incidents.show`
 * (M11.4, M11.6 through M11.10; bound to the node in M16.20; INC-001 through
 * INC-015; IMS surface specification 8, 9).
 *
 * One screen, three routes, and now one record: the incident on this page is
 * the node's, read from `GET /api/events/{event}/incidents/{incident}`. Every
 * change is a command followed by a re-read.
 *
 * The re-read is the part worth naming. A command answers with the record it
 * changed, but not with the timeline entry the change produced, the Name
 * Reference chips it moved, or the other incident a link touched — those are
 * decided by `IncidentTimelineService` and `IncidentLinkService`, and the client
 * used to write its own versions of them into a local store. Asking again is one
 * request and removes the whole class of disagreement between what this screen
 * shows and what the node recorded.
 *
 * Autosave still autosaves, and is still refused offline: technical spec 19.2
 * requires an active connection for incident mutations, so the command catalog
 * refuses every one of these where it stands rather than queueing it, and the
 * typed form stays on screen (UI contract 16.3).
 */
const route = useRoute();
const connectivity = useConnectivity();
const context = computed(() => incidentSessionContext.value);
const access = computed(() => incidentAccess.value);
const canView = computed(() => access.value.canView);
const canEdit = computed(() => access.value.canUpdate);
const canPrintPdf = computed(() => access.value.canPrint);
const eventId = computed(() => context.value?.eventId ?? null);

const routeName = computed(() =>
  typeof route.name === "string" ? route.name : "",
);
const isIncidentShowRoute = computed(
  () => routeName.value === "ims.incidents.show",
);
const isCreateRoute = computed(
  () => routeName.value === "ims.incidents.create",
);
const requiresEditAccess = computed(
  () => isCreateRoute.value || routeName.value === "ims.incidents.edit",
);
/**
 * Whether the caller holds the capability the route they asked for needs.
 *
 * Creating and editing are separate grants in the permission catalog, so the
 * two routes ask separate questions rather than sharing one "may write" flag.
 */
const hasRequiredEditAccess = computed(() =>
  isCreateRoute.value ? access.value.canCreate : access.value.canUpdate,
);
const routeIncidentId = computed(() =>
  typeof route.params.incidentId === "string" ? route.params.incidentId : null,
);

const savedIncidentId = ref<string | null>(routeIncidentId.value);
const incident = ref<ImsIncident | null>(null);
const loadError = ref<string | null>(null);
const loadingIncident = ref(false);
const assignable = ref<IncidentAssignableOptions | null>(null);
const linkCandidates = ref<readonly ImsIncident[]>([]);
const fieldReportCandidates = ref<readonly FieldReportLinkCandidate[]>([]);

const form = reactive<IncidentAutosaveForm>(blankIncidentAutosaveForm());
const autosaveState = ref<AutosaveStatusState>("saved");
const autosaveMessage = ref<string | null>(null);
const lastSavedAt = ref<string | null>(null);
const noteBody = ref("");
const noteError = ref<string | null>(null);
const linkError = ref<string | null>(null);
const attachmentError = ref<string | null>(null);
const showFullHistory = ref(false);
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
const displayMode = ref<"edit" | "view">(
  isIncidentShowRoute.value ? "view" : "edit",
);
const incidentTypePicker = ref<HTMLElement | null>(null);
const responderPicker = ref<HTMLElement | null>(null);
const linkedIncidentPicker = ref<HTMLElement | null>(null);
const fieldReportPicker = ref<HTMLElement | null>(null);
const printError = ref<string | null>(null);
const printBusy = ref(false);

/** What the autosave line says when the node cannot be reached. */
const offlineAutosaveMessage =
  "Incident create/edit requires server connection. Your typed form remains on this screen.";

const isOfflineBlocked = computed(() => connectivity.value !== "online");
const timelineEntries = computed(() =>
  visibleIncidentTimelineEntries(
    incident.value?.timelineEntries ?? [],
    showFullHistory.value,
  ),
);
/**
 * The autosave line is part of the form's layout, not something that appears
 * over it.
 *
 * It used to render only when it had something to report, which moved every
 * control below it down the moment a save started and back up when the save
 * finished — on the create screen that is the whole form jumping under the
 * cursor at the exact moment somebody is typing into it. So the row is always
 * on the page and only its wording changes, and the reserved height in the
 * stylesheet keeps a two-word "Saving" and a full offline sentence occupying
 * the same space.
 *
 * View mode has no form to displace and nothing to autosave, so it does not
 * carry the row at all.
 */
const autosaveIdleMessage = computed(() =>
  savedIncidentId.value === null
    ? "This incident saves as you fill it in. Nothing has reached the node yet."
    : null,
);
const autosaveLineMessage = computed(
  () => autosaveMessage.value ?? autosaveIdleMessage.value,
);
const attachments = computed(() => incident.value?.attachments ?? []);
/**
 * The vocabularies these two selects offer.
 *
 * The node's, and until it has answered, the value the form already holds and
 * nothing else. A local fallback list would be the second copy of
 * `Incident::statuses()` this task removed, and it would let the form propose a
 * state the node might no longer accept.
 */
const statusOptions = computed(() =>
  assignable.value?.statuses.length ? assignable.value.statuses : [form.status],
);
const priorityOptions = computed(() =>
  assignable.value?.priorities.length
    ? assignable.value.priorities
    : [form.priorityLabel],
);
const selectedIncidentTypes = computed(() => form.incidentTypeNames);

/**
 * The responders on the form, named.
 *
 * The event's IC department roster answers for most of them, and the incident's
 * own responders cover anyone assigned before they left it — a name the form
 * could otherwise show as an id.
 */
const responderDirectory = computed(() => {
  const byStaffId = new Map<string, { staffId: string; displayName: string }>();

  for (const responder of assignable.value?.responders ?? []) {
    byStaffId.set(responder.staffId, responder);
  }

  for (const responder of incident.value?.responders ?? []) {
    if (!byStaffId.has(responder.staffId)) {
      byStaffId.set(responder.staffId, responder);
    }
  }

  return byStaffId;
});
const selectedResponders = computed(() =>
  form.responderStaffIds.map(
    (staffId) =>
      responderDirectory.value.get(staffId) ?? {
        staffId,
        displayName: "Unknown responder",
      },
  ),
);
const availableIncidentTypeOptions = computed(() =>
  filteredAddOptions(
    (assignable.value?.types ?? []).filter(
      (typeName) => !form.incidentTypeNames.includes(typeName),
    ),
    incidentTypeAddQuery.value,
  ),
);
/**
 * What the picker says when it offers nothing.
 *
 * The two causes are different problems for the person reading it: a search
 * that matched none of the organization's types is theirs to fix by typing
 * something else, and an organization with no configured types at all is an
 * organizer's to fix somewhere this screen cannot reach.
 */
const incidentTypeEmptyHint = computed(() => {
  if ((assignable.value?.types ?? []).length === 0) {
    return "No incident types are configured for this organization.";
  }

  return incidentTypeAddQuery.value.trim() === ""
    ? "Every configured incident type is already on this incident."
    : "No configured incident type matches that.";
});
const availableResponderOptions = computed(() =>
  filteredAddOptions(
    (assignable.value?.responders ?? []).filter(
      (responder) => !form.responderStaffIds.includes(responder.staffId),
    ),
    responderAddQuery.value,
    (responder) => `${responder.displayName} ${responder.detail}`,
  ),
);
const availableLinkedIncidentOptions = computed(() =>
  incident.value === null
    ? []
    : orderedLinkCandidates(incident.value, linkCandidates.value),
);
const availableFieldReportOptions = computed(() =>
  incident.value === null
    ? []
    : orderedFieldReportCandidates(
        incident.value,
        fieldReportCandidates.value,
        fieldReportAddQuery.value,
      ),
);
const selectedLinkedIncidents = computed(
  () => incident.value?.linkedIncidents ?? [],
);
const selectedAttachedFieldReports = computed(
  () => incident.value?.attachedFieldReports ?? [],
);
const isViewMode = computed(
  () => displayMode.value === "view" && incident.value !== null,
);
const canToggleViewMode = computed(
  () => canEdit.value && routeIncidentId.value !== null && incident.value !== null,
);
const heading = computed(() => {
  if (routeIncidentId.value === null) {
    return "Create incident";
  }

  if (isViewMode.value && incident.value) {
    return incident.value.title || "Incident";
  }

  return "Edit incident";
});

/**
 * A typed picker search goes back to the node.
 *
 * The list is paged, so the candidates for a link are whichever incidents the
 * node finds for that search rather than whichever ones happen to be on the
 * page the reader last looked at. Debounced because it is a keystroke handler
 * and the search runs across the incident record, its notes, and its attached
 * Field Reports.
 */
let linkCandidateSearchTimer: ReturnType<typeof setTimeout> | null = null;

watch(
  () => [routeIncidentId.value, routeName.value, eventId.value] as const,
  () => {
    displayMode.value = isIncidentShowRoute.value ? "view" : "edit";
    savedIncidentId.value = routeIncidentId.value;
    noteError.value = null;
    linkError.value = null;
    attachmentError.value = null;
    printError.value = null;

    void reload();
  },
  { immediate: true },
);

watch(isOfflineBlocked, (blocked) => {
  if (blocked) {
    autosaveState.value = "blocked_offline";
    autosaveMessage.value = offlineAutosaveMessage;
  } else if (autosaveState.value === "blocked_offline") {
    autosaveState.value = "saved";
    autosaveMessage.value = null;
  }
});

watch(linkedIncidentAddQuery, () => {
  if (linkCandidateSearchTimer !== null) {
    clearTimeout(linkCandidateSearchTimer);
  }

  linkCandidateSearchTimer = setTimeout(() => {
    void loadLinkCandidates();
  }, 250);
});

onMounted(() => {
  document.addEventListener("pointerdown", onDocumentPointerDown);
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", onDocumentPointerDown);

  if (linkCandidateSearchTimer !== null) {
    clearTimeout(linkCandidateSearchTimer);
  }
});

/** Read the incident and the option lists the form is built from. */
async function reload(): Promise<void> {
  await Promise.all([loadIncident(), loadOptions()]);
}

async function loadIncident(): Promise<void> {
  const incidentId = savedIncidentId.value;

  if (eventId.value === null || incidentId === null || !canView.value) {
    incident.value = null;
    resetFormFromIncident(null);

    return;
  }

  loadingIncident.value = true;
  loadError.value = null;

  try {
    const record = await getEventIncident(eventId.value, incidentId);

    incident.value = record;
    resetFormFromIncident(record);
  } catch (error) {
    incident.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this incident. Check the connection to this node and try again.",
    );
  } finally {
    loadingIncident.value = false;
  }
}

/**
 * Read what the node lets this form assign, and the incidents a link may point
 * at.
 *
 * One request answers both, because both are on the list read. A reader who
 * cannot edit is not shown a form, so they are not asked for.
 */
async function loadOptions(): Promise<void> {
  if (eventId.value === null || !canEdit.value) {
    return;
  }

  try {
    const list = await getEventIncidents(eventId.value, {
      state: "all",
      per_page: "50",
    });

    assignable.value = list.assignable;
    linkCandidates.value = list.incidents;
  } catch {
    // A form that cannot offer suggestions still saves: the node validates the
    // typed values either way, and an unreachable option list must not take the
    // incident down with it.
  }
}

async function loadLinkCandidates(): Promise<void> {
  if (eventId.value === null || !canEdit.value) {
    return;
  }

  try {
    const list = await getEventIncidents(eventId.value, {
      state: "all",
      per_page: "50",
      search: linkedIncidentAddQuery.value.trim(),
    });

    linkCandidates.value = list.incidents;
  } catch {
    // As above: a search that could not run leaves the last candidates alone.
  }
}

async function loadFieldReportCandidates(): Promise<void> {
  if (
    eventId.value === null ||
    !access.value.canViewFieldReports ||
    fieldReportCandidates.value.length > 0
  ) {
    return;
  }

  try {
    fieldReportCandidates.value = (
      await getEventFieldReports(eventId.value)
    ).reports;
  } catch {
    // As above.
  }
}

/** Put the form back on the record, and forget any autosave state for it. */
function resetFormFromIncident(record: ImsIncident | null): void {
  Object.assign(
    form,
    record
      ? incidentToAutosaveForm(record)
      : blankIncidentAutosaveForm(new Date(), assignable.value),
  );
  autosaveState.value = isOfflineBlocked.value ? "blocked_offline" : "saved";
  autosaveMessage.value = isOfflineBlocked.value ? offlineAutosaveMessage : null;
  lastSavedAt.value = record?.updatedAt ?? null;
  lastSavedSignature.value = formSignature(form);
  lastSavedForm.value = cloneAutosaveForm(form);
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

function showViewState(): void {
  if (incident.value) {
    displayMode.value = "view";
  }
}

function showEditState(): void {
  displayMode.value = "edit";
}

function priorityText(priorityLabel: string): string {
  return priorityLabel === "" ? "Priority not set" : priorityLabel;
}

function priorityClass(priorityLabel: string): string {
  return `ims-edit__priority-pill--${priorityLabel.toLowerCase()}`;
}

function responderText(): string {
  return incident.value && incident.value.responders.length > 0
    ? incident.value.responders
        .map((responder) => responder.displayName)
        .join(", ")
    : "Responders not set";
}

function attachmentSize(attachment: IncidentAttachment): string {
  const kilobytes = attachment.byteSize / 1024;

  return kilobytes < 1024
    ? `${Math.max(1, Math.round(kilobytes))} KB`
    : `${(kilobytes / 1024).toFixed(1)} MB`;
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
    autosaveMessage.value = offlineAutosaveMessage;

    return;
  }

  autosaveState.value = "saving";
  autosaveMessage.value = null;
  void autosave();
}

async function autosave(): Promise<void> {
  const signature = formSignature(form);
  const eventIdValue = eventId.value;
  autosaveQueued.value = false;

  if (eventIdValue === null) {
    return;
  }

  try {
    if (savedIncidentId.value === null) {
      savedIncidentId.value = await createIncident(
        eventIdValue,
        form,
        lastSavedForm.value,
      );
    } else {
      await updateIncident(eventIdValue, savedIncidentId.value, form);
    }

    lastSavedSignature.value = signature;
    lastSavedForm.value = cloneAutosaveForm(form);
    autosaveState.value = "saved";
    autosaveMessage.value = null;

    await refreshIncident();

    if (formSignature(form) !== signature || autosaveQueued.value) {
      commitAutosave();
    }
  } catch (error) {
    autosaveState.value = "failed";
    autosaveMessage.value = meridianErrorMessage(
      error,
      "Unable to autosave incident.",
    );
  }
}

/**
 * Re-read the incident after a write, without disturbing what is being typed.
 *
 * The read is the record; the form is the draft on top of it. Overwriting the
 * form here would take a keystroke back from someone typing during a save,
 * which is exactly when a save is likely to be running.
 */
async function refreshIncident(): Promise<void> {
  const incidentId = savedIncidentId.value;

  if (eventId.value === null || incidentId === null) {
    return;
  }

  try {
    const record = await getEventIncident(eventId.value, incidentId);

    incident.value = record;
    lastSavedAt.value = record.updatedAt;
  } catch (error) {
    loadError.value = meridianErrorMessage(
      error,
      "Unable to re-read this incident after saving.",
    );
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

/**
 * Enter takes the first match, and nothing when there is none.
 *
 * The picker chooses from the organization's configured incident types; it does
 * not create one. A name that matches nothing is a type this organization has
 * not configured, and the way to add it is to configure it.
 */
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
    void addLinkedIncident(linkedIncident.id);
  }
}

async function addLinkedIncident(targetIncidentId: string): Promise<void> {
  await runLinkCommand(async (eventIdValue, incidentId) => {
    await linkIncident(eventIdValue, incidentId, targetIncidentId);
    linkedIncidentAddQuery.value = "";
    linkedIncidentAddOpen.value = false;
  });
}

async function removeLinkedIncident(targetIncidentId: string): Promise<void> {
  await runLinkCommand((eventIdValue, incidentId) =>
    unlinkIncident(eventIdValue, incidentId, targetIncidentId),
  );
}

function openFieldReportAdd(): void {
  fieldReportAddOpen.value = true;
  incidentTypeAddOpen.value = false;
  responderAddOpen.value = false;
  linkedIncidentAddOpen.value = false;
  void loadFieldReportCandidates();
}

function addFirstFieldReportOption(): void {
  const [fieldReport] = availableFieldReportOptions.value;

  if (fieldReport) {
    void addFieldReport(fieldReport.id);
  }
}

async function addFieldReport(fieldReportId: string): Promise<void> {
  await runLinkCommand(async (eventIdValue, incidentId) => {
    await linkFieldReport(eventIdValue, incidentId, fieldReportId);
    fieldReportAddQuery.value = "";
    fieldReportAddOpen.value = false;
  });
}

async function removeFieldReport(fieldReportId: string): Promise<void> {
  await runLinkCommand((eventIdValue, incidentId) =>
    unlinkFieldReport(eventIdValue, incidentId, fieldReportId),
  );
}

/**
 * Every link command: send it, then re-read.
 *
 * Linking touches two records and writes a timeline entry on each, so what the
 * screen shows afterwards comes from the node rather than from an assumption
 * about what the link did.
 */
async function runLinkCommand(
  command: (eventId: string, incidentId: string) => Promise<void>,
): Promise<void> {
  const eventIdValue = eventId.value;
  const incidentId = savedIncidentId.value;

  linkError.value = null;

  if (eventIdValue === null || incidentId === null) {
    linkError.value = "Create the incident before linking records to it.";

    return;
  }

  try {
    await command(eventIdValue, incidentId);
    await refreshIncident();
  } catch (error) {
    linkError.value = meridianErrorMessage(
      error,
      "Unable to change this incident's links.",
    );
  }
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

/**
 * What a timeline field-change entry calls a field.
 *
 * The node names fields in its own snake_case, and the create command is handed
 * the same names, so both spellings resolve here rather than only the one this
 * screen happens to use.
 */
function fieldLabel(field: string): string {
  const labels: Record<string, string> = {
    title: "Title",
    status: "State",
    priorityLabel: "Priority",
    priority_label: "Priority",
    incidentTypeNames: "Incident types",
    incident_type_names: "Incident types",
    responders: "Responders",
    responder_staff_ids: "Responders",
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

  return field === "status" ? statusLabel(value) : value;
}

function canStrikeTimelineEntry(entry: IncidentTimelineEntry): boolean {
  return (
    access.value.canAddNote &&
    entry.entryType === "operational_note" &&
    !entry.strickenAt
  );
}

async function onStrikeNote(entry: IncidentTimelineEntry): Promise<void> {
  const eventIdValue = eventId.value;
  const incidentId = savedIncidentId.value;

  noteError.value = null;

  if (eventIdValue === null || incidentId === null) {
    noteError.value = "Create the incident before striking notes.";

    return;
  }

  const reason = window.prompt("Reason for striking this note");

  if (reason === null) {
    return;
  }

  try {
    await strikeIncidentNote(eventIdValue, incidentId, entry.id, reason);
    await refreshIncident();
  } catch (error) {
    noteError.value = meridianErrorMessage(
      error,
      "Unable to strike incident note.",
    );
  }
}

async function onAppendNote(): Promise<void> {
  const eventIdValue = eventId.value;
  const incidentId = savedIncidentId.value;

  noteError.value = null;

  if (!access.value.canAddNote) {
    noteError.value = "Incident note updates require IC operator or lead access.";

    return;
  }

  if (eventIdValue === null || incidentId === null) {
    noteError.value = "Create the incident before adding notes.";

    return;
  }

  try {
    await appendIncidentNote(eventIdValue, incidentId, noteBody.value);
    noteBody.value = "";
    await refreshIncident();
  } catch (error) {
    noteError.value = meridianErrorMessage(
      error,
      "Unable to add incident note.",
    );
  }
}

/**
 * Strike an attachment, with the reason the node requires (INC-013).
 *
 * A strike, not a delete: the file stops being served and why it was struck is
 * kept, so the reason is asked for rather than defaulted.
 */
async function onStrikeAttachment(
  attachment: IncidentAttachment,
): Promise<void> {
  const eventIdValue = eventId.value;
  const incidentId = savedIncidentId.value;

  attachmentError.value = null;

  if (eventIdValue === null || incidentId === null) {
    return;
  }

  const reason = window.prompt(
    `Reason for striking ${attachment.filename}`,
  );

  if (reason === null) {
    return;
  }

  try {
    await strikeIncidentAttachment(
      eventIdValue,
      incidentId,
      attachment.id,
      reason,
    );
    await refreshIncident();
  } catch (error) {
    attachmentError.value = meridianErrorMessage(
      error,
      "Unable to strike this attachment.",
    );
  }
}

async function onPrintPdf(): Promise<void> {
  const eventIdValue = eventId.value;

  printError.value = null;

  if (eventIdValue === null || incident.value === null) {
    printError.value = "Incident not found for this event.";

    return;
  }

  if (isOfflineBlocked.value) {
    printError.value =
      "Incident PDF print requires a server connection. Reconnect and try again.";

    return;
  }

  printBusy.value = true;

  try {
    await downloadIncidentPdf(eventIdValue, incident.value.id);
  } catch (error) {
    printError.value = meridianErrorMessage(
      error,
      "Unable to print incident PDF.",
    );
  } finally {
    printBusy.value = false;
  }
}
</script>

<template>
  <section class="ims-edit" aria-labelledby="ims-edit-heading">
    <div v-if="!canView" class="ims-edit__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <div
      v-else-if="requiresEditAccess && !hasRequiredEditAccess"
      class="ims-edit__restricted"
      role="status"
    >
      <RouterLink :to="{ name: 'ims.restricted' }">
        IC operator or lead access required
      </RouterLink>
    </div>

    <div
      v-else-if="routeIncidentId && loadingIncident && !incident"
      class="ims-edit__restricted"
      role="status"
    >
      Loading incident.
    </div>

    <!--
      The node's own sentence when it refused, and a plain not-found only when
      it answered without one. A refusal is not an absence, and reading them as
      the same thing is how a permission problem gets mistaken for a typo in a
      URL.
    -->
    <div
      v-else-if="routeIncidentId && !incident"
      class="ims-edit__restricted"
      role="alert"
    >
      {{ loadError ?? "Incident not found for this event." }}
    </div>

    <template v-else>
      <RouterLink class="ims-edit__back" :to="{ name: 'ims.incidents.index' }">
        Back to incidents
      </RouterLink>

      <header class="ims-edit__header">
        <div class="ims-edit__title-block">
          <p class="ims-edit__eyebrow">Incident Management System</p>
          <h1 id="ims-edit-heading" class="ims-edit__heading">
            {{ heading }}
          </h1>
        </div>
        <div class="ims-edit__number-stack">
          <p class="ims-edit__incident-number">
            {{ incident?.incidentNumber ?? "Not assigned yet" }}
          </p>
          <div v-if="canToggleViewMode" class="ims-edit__mode-actions">
            <button
              v-if="!isViewMode"
              type="button"
              class="ims-edit__mode-button"
              @click="showViewState"
            >
              View incident
            </button>
            <button
              v-else
              type="button"
              class="ims-edit__mode-button"
              @click="showEditState"
            >
              Edit incident
            </button>
          </div>
        </div>
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

      <div v-if="!isViewMode" class="ims-edit__autosave">
        <AutosaveStatus
          :state="autosaveState"
          :last-saved-at="lastSavedAt"
          :message="autosaveLineMessage"
          repair-href="/readiness"
        />
      </div>

      <form
        v-if="!isViewMode"
        class="ims-edit__form"
        aria-label="Incident autosave form"
      >
        <section
          class="ims-edit__panel ims-edit__panel--span"
          aria-label="Incident details"
        >
          <div class="ims-edit__detail-grid">
            <div class="ims-edit__field ims-edit__field--readonly">
              <span>IMS #</span>
              <output>{{ incident?.incidentNumber ?? "Not assigned yet" }}</output>
            </div>

            <!--
              The state and priority vocabularies come off the list read's
              `assignable` block, so this form offers exactly what
              `IncidentUpdateService` accepts. They used to be typed out here,
              which meant a state the node added was one this screen could not
              set and a state it removed was one this screen still offered.
            -->
            <label class="ims-edit__field">
              <span>State</span>
              <select
                id="ims-edit-status"
                v-model="form.status"
                :disabled="isOfflineBlocked"
                @change="commitAutosave"
              >
                <option
                  v-for="status in statusOptions"
                  :key="status"
                  :value="status"
                >
                  {{ statusLabel(status) }}
                </option>
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
                  v-for="priority in priorityOptions"
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
            <!--
              A chooser, not a creator. Incident types are configured for the
              organization (requirements: configurable areas), so this picker
              offers what the node configured and says so when nothing matches
              rather than offering to invent one. The panel still opens on an
              empty result, because a control that silently does nothing reads
              as broken.
            -->
            <div
              v-if="incidentTypeAddOpen"
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
              <p
                v-if="availableIncidentTypeOptions.length === 0"
                class="ims-edit__add-hint"
              >
                {{ incidentTypeEmptyHint }}
              </p>
            </div>
          </section>
        </div>

        <section
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
              :disabled="isOfflineBlocked || !savedIncidentId"
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
              :disabled="isOfflineBlocked || !savedIncidentId"
              @click="addLinkedIncident(linkedIncident.id)"
            >
              {{ linkedIncident.incidentNumber }} -
              {{ linkedIncident.title || "Untitled incident" }}
            </button>
          </div>
        </section>

        <section
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
              :disabled="isOfflineBlocked || !savedIncidentId"
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
              :disabled="isOfflineBlocked || !savedIncidentId"
              @click="addFieldReport(fieldReport.id)"
            >
              {{ fieldReport.displayNumber }} -
              {{ fieldReport.title }}
            </button>
          </div>
        </section>

        <p v-if="linkError" class="ims-edit__error ims-edit__error--span" role="alert">
          {{ linkError }}
        </p>

        <section
          class="ims-edit__panel ims-edit__panel--span"
          aria-labelledby="ims-edit-location-heading"
        >
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

        <!--
          Attachments are listed and struck here, never uploaded: an incident
          attachment reaches the node through the Field Report it arrived on,
          and there is no incident upload endpoint to offer a control for.
          Striking keeps the file and the reason (INC-013), which is why the
          button says strike rather than delete.
        -->
        <section
          class="ims-edit__panel ims-edit__panel--span"
          aria-labelledby="ims-edit-attachments-heading"
        >
          <div class="ims-edit__panel-heading">
            <h2 id="ims-edit-attachments-heading">Attachments</h2>
          </div>
          <div id="ims-edit-attachments" class="ims-edit__selected-list">
            <div
              v-for="attachment in attachments"
              :key="attachment.id"
              class="ims-edit__selected-row"
            >
              <span>{{ attachment.filename }}</span>
              <em class="ims-edit__attachment-detail">
                {{ attachment.mimeType }} - {{ attachmentSize(attachment) }}
              </em>
              <button
                type="button"
                class="ims-edit__remove-button"
                :disabled="isOfflineBlocked"
                :aria-label="`Strike attachment ${attachment.filename}`"
                @click="onStrikeAttachment(attachment)"
              >
                Strike
              </button>
            </div>
            <p v-if="attachments.length === 0" class="ims-edit__empty-row">
              No attachments on this incident.
            </p>
          </div>
          <p v-if="attachmentError" class="ims-edit__error" role="alert">
            {{ attachmentError }}
          </p>
        </section>
      </form>

      <section
        v-if="isViewMode && incident"
        class="ims-edit__view"
        aria-labelledby="ims-edit-view-heading"
      >
        <div class="ims-edit__view-heading">
          <h2 id="ims-edit-view-heading">Current state</h2>
          <div v-if="canPrintPdf" class="ims-edit__print">
            <button
              type="button"
              class="ims-edit__print-button"
              :disabled="printBusy || isOfflineBlocked"
              :aria-busy="printBusy"
              @click="onPrintPdf"
            >
              {{ printBusy ? "Preparing PDF..." : "Print PDF" }}
            </button>
            <p
              v-if="isOfflineBlocked"
              class="ims-edit__print-hint"
              role="status"
            >
              Incident PDF print requires a server connection.
            </p>
            <p v-if="printError" class="ims-edit__print-error" role="alert">
              {{ printError }}
            </p>
          </div>
        </div>
        <dl class="ims-edit__view-grid">
          <div>
            <dt>IMS #</dt>
            <dd>{{ incident.incidentNumber }}</dd>
          </div>
          <div>
            <dt>State</dt>
            <dd>
              <span class="ims-edit__state-pill">
                {{ statusLabel(incident.status) }}
              </span>
            </dd>
          </div>
          <div>
            <dt>Priority</dt>
            <dd>
              <span
                class="ims-edit__priority-pill"
                :class="priorityClass(incident.priorityLabel)"
              >
                {{ priorityText(incident.priorityLabel) }}
              </span>
            </dd>
          </div>
          <div>
            <dt>Summary</dt>
            <dd>{{ incident.title || "Untitled incident" }}</dd>
          </div>
          <div>
            <dt>Started</dt>
            <dd>{{ formatIncidentDateTime(incident.startedAt) }}</dd>
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
              class="ims-edit__type-list"
            >
              <span
                v-for="typeName in incident.incidentTypeNames"
                :key="typeName"
                class="ims-edit__type-chip"
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
            <dt>Linked incidents</dt>
            <dd
              v-if="incident.linkedIncidents.length > 0"
              class="ims-edit__view-list"
            >
              <RouterLink
                v-for="linkedIncident in incident.linkedIncidents"
                :key="linkedIncident.id"
                class="ims-edit__view-linked-row"
                :to="{
                  name: 'ims.incidents.show',
                  params: { incidentId: linkedIncident.id },
                }"
              >
                {{ linkedIncident.incidentNumber }} -
                {{ linkedIncident.title || "Untitled incident" }}
              </RouterLink>
            </dd>
            <dd v-else>No linked incidents</dd>
          </div>
          <div>
            <dt>Attached Field Reports</dt>
            <dd
              v-if="incident.attachedFieldReports.length > 0"
              class="ims-edit__view-list"
            >
              <RouterLink
                v-for="fieldReport in incident.attachedFieldReports"
                :key="fieldReport.id"
                class="ims-edit__view-field-report-row"
                :to="{
                  name: 'ims.field-reports.show',
                  params: { fieldReportId: fieldReport.id },
                }"
              >
                {{ fieldReport.displayNumber }} - {{ fieldReport.title }}
              </RouterLink>
            </dd>
            <dd v-else>No attached Field Reports</dd>
          </div>
          <div>
            <dt>Created by</dt>
            <dd>{{ incident.createdByName ?? "Creator unavailable" }}</dd>
          </div>
        </dl>
      </section>

      <section
        class="ims-edit__timeline"
        aria-labelledby="ims-edit-timeline-heading"
      >
        <div class="ims-edit__timeline-heading">
          <h2 id="ims-edit-timeline-heading">Timeline</h2>
          <button type="button" @click="showFullHistory = !showFullHistory">
            {{ showFullHistory ? "Hide full history" : "Show full history" }}
          </button>
        </div>
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
            <button
              v-if="canStrikeTimelineEntry(entry)"
              class="ims-edit__timeline-strike"
              type="button"
              @click="onStrikeNote(entry)"
            >
              Strike note
            </button>
          </li>
        </ol>

        <form
          v-if="access.canAddNote"
          class="ims-edit__note-form"
          @submit.prevent="onAppendNote"
        >
          <label for="ims-note-body">Operational note</label>
          <textarea
            id="ims-note-body"
            v-model="noteBody"
            rows="4"
            :disabled="isOfflineBlocked || !savedIncidentId"
          />
          <p v-if="noteError" class="ims-edit__error" role="alert">
            {{ noteError }}
          </p>
          <button type="submit" :disabled="isOfflineBlocked || !savedIncidentId">
            Add note
          </button>
        </form>
      </section>
    </template>
  </section>
</template>

<style scoped>
.ims-edit {
  box-sizing: border-box;
  width: var(--m-content-workflow);
  margin-inline: auto;
  display: grid;
  align-content: start;
  gap: var(--m-stack-gap);
}

.ims-edit *,
.ims-edit *::before,
.ims-edit *::after {
  box-sizing: border-box;
}

.ims-edit__back,
.ims-edit__restricted a,
.ims-edit__mode-button,
.ims-edit__print-button {
  color: var(--m-action-secondary-bg);
  font-weight: 800;
}

.ims-edit__print-button {
  justify-self: end;
  border: 1px solid var(--m-action-secondary-bg);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  background: transparent;
  cursor: pointer;
  font: inherit;
}

.ims-edit__mode-button {
  display: inline-flex;
  min-height: 2rem;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--m-action-secondary-bg);
  border-radius: var(--m-radius-sm);
  padding: 0 var(--m-space-2);
  background: var(--m-surface-base);
  cursor: pointer;
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 800;
  line-height: 1;
  white-space: nowrap;
}

.ims-edit__print {
  display: grid;
  gap: var(--m-space-2);
  justify-items: end;
}

.ims-edit__print-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.ims-edit__print-hint,
.ims-edit__print-error {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.ims-edit__print-error {
  color: var(--m-status-danger-fg, var(--m-text-muted));
}

.ims-edit__restricted {
  color: var(--m-text-muted);
}

/*
 * The autosave row's reserved height.
 *
 * The row is always rendered, so nothing below it moves when a save starts or
 * finishes. The reserve covers the wording changing length as well: two lines
 * on a phone, where the offline sentence wraps, and one past the breakpoint
 * where it does not. The status block sits at the top of the reserve rather
 * than stretching to fill it, so the band keeps its own proportions.
 */
.ims-edit__autosave {
  display: grid;
  align-content: start;
  min-height: 4.25rem;
}

@media (min-width: 48rem) {
  .ims-edit__autosave {
    min-height: 2.5rem;
  }
}

.ims-edit__header {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: start;
  gap: var(--m-space-2) var(--m-space-4);
}

.ims-edit__title-block {
  grid-column: 1;
  grid-row: 1;
  min-width: 0;
}

.ims-edit__number-stack {
  display: grid;
  grid-column: 2;
  grid-row: 1 / span 2;
  gap: var(--m-space-2);
  justify-items: end;
  min-width: max-content;
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
  color: var(--m-text-secondary);
  font-weight: 800;
  white-space: nowrap;
}

.ims-edit__mode-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
}

.ims-edit__chips {
  display: flex;
  grid-column: 1;
  grid-row: 2;
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
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
}

.ims-edit__chip--name-reference {
  border: 1px solid var(--m-action-secondary-bg);
  background: var(--m-surface-base);
  color: var(--m-action-secondary-bg);
}

.ims-edit__chip:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-edit__mode-button:focus-visible,
.ims-edit__print-button:focus-visible,
.ims-edit__view-list a:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-edit__view {
  display: grid;
  gap: var(--m-space-3);
  overflow: hidden;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-edit__view-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
  min-height: 2.25rem;
  border-bottom: 1px solid var(--m-border-default);
  padding: var(--m-space-1) var(--m-space-3);
  background: color-mix(
    in srgb,
    var(--m-surface-raised) 84%,
    var(--m-border-default)
  );
}

.ims-edit__view-heading h2 {
  margin: 0;
  font-size: var(--m-text-md);
  font-weight: 900;
}

.ims-edit__view-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-3);
}

.ims-edit__view-grid div {
  min-width: 0;
}

.ims-edit__view-grid dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-edit__view-grid dd {
  margin: var(--m-space-1) 0 0;
  overflow-wrap: anywhere;
}

.ims-edit__state-pill,
.ims-edit__priority-pill,
.ims-edit__type-chip {
  display: inline-flex;
  min-height: 1.75rem;
  align-items: center;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: 0 var(--m-space-2);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-edit__priority-pill--routine {
  border-color: var(--m-status-neutral);
  color: var(--m-text-secondary);
}

.ims-edit__priority-pill--important {
  border-color: var(--m-attention-attention);
  color: var(--m-text-primary);
}

.ims-edit__priority-pill--serious {
  border-color: var(--m-attention-warning);
  color: var(--m-text-primary);
}

.ims-edit__priority-pill--critical {
  border-color: var(--m-attention-critical);
  color: var(--m-text-primary);
}

.ims-edit__type-list,
.ims-edit__view-list {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.ims-edit__type-chip {
  border-color: var(--m-action-secondary-bg);
  background: var(--m-surface-base);
  color: var(--m-action-secondary-bg);
}

.ims-edit__view-list a {
  display: inline-flex;
  min-height: 2rem;
  align-items: center;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: 0 var(--m-space-2);
  background: var(--m-surface-base);
  color: var(--m-action-secondary-bg);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-decoration: none;
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
  background: var(--m-surface-base);
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
  background: var(--m-action-destructive-bg);
  color: var(--m-action-destructive-text);
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
  background: var(--m-surface-base);
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


.ims-edit__add-hint {
  margin: 0;
  padding: var(--m-space-2) var(--m-space-3);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
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

.ims-edit__timeline-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
  min-height: 2.25rem;
  border-bottom: 1px solid var(--m-border-default);
  padding: var(--m-space-1) var(--m-space-3);
  background: color-mix(
    in srgb,
    var(--m-surface-raised) 84%,
    var(--m-border-default)
  );
}

.ims-edit__timeline-heading h2 {
  margin: 0;
  font-size: var(--m-text-lg);
}

.ims-edit__timeline-heading button {
  min-height: 2.25rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: 0 var(--m-space-3);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 800;
}

.ims-edit__timeline-heading button:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
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
  padding: var(--m-space-1) 5.5rem var(--m-space-2) var(--m-space-4);
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

.ims-edit__timeline-strike {
  position: absolute;
  top: var(--m-space-1);
  right: 0;
  border: 0;
  padding: 0;
  background: transparent;
  color: var(--m-action-destructive-bg);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 800;
  line-height: 1.2;
}

.ims-edit__timeline-strike:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
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
  background: var(--m-surface-base);
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

.ims-edit__error--span {
  grid-column: 1 / -1;
}

.ims-edit__attachment-detail {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-style: normal;
}

.ims-edit__error {
  margin: 0;
  color: var(--m-status-danger);
  font-size: var(--m-text-sm);
}

@media (max-width: 48rem) {
  .ims-edit {
    width: 100%;
    gap: var(--m-space-4);
  }

  .ims-edit__header {
    grid-template-columns: 1fr;
    gap: var(--m-space-2);
  }

  .ims-edit__title-block,
  .ims-edit__number-stack,
  .ims-edit__incident-number,
  .ims-edit__mode-actions,
  .ims-edit__chips {
    grid-column: 1;
    grid-row: auto;
  }

  .ims-edit__number-stack {
    justify-self: start;
    justify-items: start;
    min-width: 0;
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

/*
 * Wide editing layout (M11.20 follow-up).
 *
 * The incident form is a column of independent panels: details, responders,
 * types, linked incidents, attached Field Reports, location. Below ~1500px that
 * column is the right shape. Above it the panels are each far narrower than the
 * page, so stacking them pushes the timeline — the thing an operator reads while
 * editing — off the fold for no reason.
 *
 * The panel row collapses into the form grid with `display: contents` so
 * responders and types become peers of the other panels instead of a nested
 * two-up block, which keeps every panel on one shared column rhythm.
 */
@media (min-width: 94rem) {
  .ims-edit__form {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
  }

  .ims-edit__panel-grid {
    display: contents;
  }

  /* Panels whose own field grid earns the full row. */
  .ims-edit__panel--span {
    grid-column: 1 / -1;
  }
}

/*
 * Wider still: the timeline sits beside the form rather than under it. Editing
 * a field and reading what it just recorded belong on one screen.
 */
@media (min-width: 120rem) {
  .ims-edit {
    grid-template-columns: minmax(0, 1.75fr) minmax(24rem, 1fr);
  }

  .ims-edit__back,
  .ims-edit__header {
    grid-column: 1 / -1;
  }

  .ims-edit__form {
    grid-column: 1;
  }

  .ims-edit__timeline {
    grid-column: 2;
    align-self: start;
  }

  /* Back to one column when the form is not on screen (view mode). */
  .ims-edit__view {
    grid-column: 1 / -1;
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
