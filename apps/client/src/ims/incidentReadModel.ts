export type IncidentRole =
  | "ic_viewer"
  | "ic_operator"
  | "ic_lead"
  | "department_lead"
  | "organizer"
  | "staff";

export interface IncidentSessionContext {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly organizationLabel: string;
  readonly icDepartmentLabel: string;
  readonly role: IncidentRole;
  readonly roleLabel: string;
}

export type IncidentListOpenMode = "view" | "edit";

/**
 * One saved incident list selection (M11.19).
 *
 * Presets capture what is being looked for, never where the reader had paged
 * to, so applying one always starts at the first page.
 */
export interface IncidentListFilterSelection {
  readonly search: string;
  readonly state: string;
  readonly priority: string;
  readonly type: string;
  readonly responder: string;
  readonly shift: string;
  readonly sort: string;
  readonly direction: string;
}

export interface IncidentListPreset {
  readonly id: string;
  readonly eventId: string;
  readonly name: string;
  readonly filters: IncidentListFilterSelection;
  readonly query: Record<string, string>;
}

export const INCIDENT_LIST_PRESET_NAME_MAX_LENGTH = 60;

export const INCIDENT_LIST_PRESET_MAX_PER_EVENT = 20;

export const INCIDENT_LIST_PAGE_SIZES: readonly number[] = Object.freeze([
  10, 25, 50, 100,
]);

export const DEFAULT_INCIDENT_LIST_PAGE_SIZE = 25;

export interface IncidentTimelineEntry {
  readonly id: string;
  readonly incidentId: string;
  readonly actorName: string | null;
  readonly entryType:
    | "incident_opened"
    | "operational_note"
    | "incident_field_updated"
    | "incident_linked"
    | "incident_unlinked"
    | "field_report_linked"
    | "field_report_unlinked"
    | "incident_attachment_stricken";
  readonly body: string | null;
  readonly previousValue?: Record<string, string | null>;
  readonly newValue?: Record<string, string | null>;
  readonly createdAt: string;
  readonly strickenAt?: string | null;
  readonly strickenReason?: string | null;
}

export interface NameReferenceChip {
  readonly token: string;
  readonly normalizedToken: string;
}

export interface IncidentTagChip {
  readonly tag: string;
  readonly normalizedTag: string;
}

export type IncidentPriorityLabel =
  | "Routine"
  | "Important"
  | "Serious"
  | "Critical";

export interface IncidentResponder {
  readonly staffId: string;
  readonly displayName: string;
  readonly relationshipLabel: string;
}

export interface LinkedIncidentSummary {
  readonly id: string;
  readonly incidentNumber: string;
  readonly title: string;
  readonly status: ImsIncident["status"];
}

export interface AttachedFieldReportSummary {
  readonly id: string;
  readonly displayNumber: string;
  readonly title: string;
  readonly authorName: string;
  readonly body: string;
  readonly linkedAt: string;
}

export interface FieldReportLinkCandidate {
  readonly id: string;
  readonly eventId: string;
  readonly displayNumber: string;
  readonly title: string;
  readonly authorName: string;
  readonly body: string;
  readonly createdAt: string;
}

export interface ImsFieldReportListItem extends FieldReportLinkCandidate {
  readonly relatedIncidents: readonly {
    readonly id: string;
    readonly incidentNumber: string;
    readonly title: string;
    readonly status: ImsIncident["status"];
    readonly priorityLabel: IncidentPriorityLabel;
  }[];
}

export interface ImsIncident {
  readonly id: string;
  readonly eventId: string;
  readonly incidentNumber: string;
  readonly title: string;
  readonly status: "open" | "on_scene" | "monitoring" | "on_hold" | "closed";
  readonly priorityLabel: IncidentPriorityLabel;
  readonly incidentTypeNames: readonly string[];
  readonly responders: readonly IncidentResponder[];
  readonly linkedIncidents: readonly LinkedIncidentSummary[];
  readonly attachedFieldReports: readonly AttachedFieldReportSummary[];
  readonly startedAt: string;
  readonly locationName: string | null;
  readonly locationAddress: string | null;
  readonly locationDetails: string | null;
  readonly createdByName: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly nameReferenceChips: readonly NameReferenceChip[];
  readonly tagChips: readonly IncidentTagChip[];
  readonly timelineEntries: readonly IncidentTimelineEntry[];
}

export interface IncidentAutosaveForm {
  title: string;
  status: ImsIncident["status"];
  priorityLabel: IncidentPriorityLabel;
  incidentTypeNames: string[];
  responderStaffIds: string[];
  startedAt: string;
  locationName: string;
  locationAddress: string;
  locationDetails: string;
}

export const LOCAL_IMS_EVENT_ID = "11111111-1111-4111-8111-111111111111";

export const INCIDENT_PRIORITY_LABELS: readonly IncidentPriorityLabel[] =
  Object.freeze(["Routine", "Important", "Serious", "Critical"]);

export const INCIDENT_TYPE_OPTIONS: readonly string[] = Object.freeze([
  "Medical",
  "Safety",
  "Logistics",
  "Radio",
  "Weather",
]);

export const RESPONDER_OPTIONS: readonly IncidentResponder[] = Object.freeze([
  Object.freeze({
    staffId: "22222222-2222-4222-8222-222222222201",
    displayName: "Vera Ranger",
    relationshipLabel: "Responder",
  }),
  Object.freeze({
    staffId: "22222222-2222-4222-8222-222222222202",
    displayName: "Omar Operator",
    relationshipLabel: "Responder",
  }),
  Object.freeze({
    staffId: "22222222-2222-4222-8222-222222222203",
    displayName: "Ingrid ICLead",
    relationshipLabel: "Responder",
  }),
]);

const IC_ROLES: readonly IncidentRole[] = [
  "ic_viewer",
  "ic_operator",
  "ic_lead",
];

const LOCAL_SESSION: IncidentSessionContext = Object.freeze({
  eventId: LOCAL_IMS_EVENT_ID,
  eventLabel: "Local Field Event",
  organizationLabel: "Local Field Organization",
  icDepartmentLabel: "Rangers",
  role: "ic_lead",
  roleLabel: "Incident Command Lead",
});

const LOCAL_INCIDENTS: readonly ImsIncident[] = Object.freeze([
  Object.freeze({
    id: "incident-gate-medical",
    eventId: LOCAL_IMS_EVENT_ID,
    incidentNumber: "INC-2027-000042",
    title: "Medical assist near Gate A",
    status: "on_scene",
    priorityLabel: "Serious",
    incidentTypeNames: Object.freeze(["Medical", "Safety"]),
    responders: Object.freeze([
      Object.freeze({
        staffId: "22222222-2222-4222-8222-222222222201",
        displayName: "Vera Ranger",
        relationshipLabel: "Responder",
      }),
    ]),
    linkedIncidents: Object.freeze([
      Object.freeze({
        id: "incident-radio-check",
        incidentNumber: "INC-2027-000041",
        title: "Radio relay check",
        status: "monitoring",
      }),
    ]),
    attachedFieldReports: Object.freeze([]),
    startedAt: "2027-07-04T20:15:00.000Z",
    locationName: "Gate A",
    locationAddress: "North entry road",
    locationDetails: "Responder staged near the shade structure.",
    createdByName: "Ingrid ICLead",
    createdAt: "2027-07-04T20:18:00.000Z",
    updatedAt: "2027-07-04T20:32:00.000Z",
    nameReferenceChips: Object.freeze([
      Object.freeze({
        token: "Blue-Hat",
        normalizedToken: "blue-hat",
      }),
      Object.freeze({
        token: "Gate_A",
        normalizedToken: "gate_a",
      }),
    ]),
    tagChips: Object.freeze([
      Object.freeze({
        tag: "medical",
        normalizedTag: "medical",
      }),
    ]),
    timelineEntries: Object.freeze([
      Object.freeze({
        id: "timeline-gate-opened",
        incidentId: "incident-gate-medical",
        actorName: "Ingrid ICLead",
        entryType: "incident_opened",
        body: "Incident INC-2027-000042 opened.",
        createdAt: "2027-07-04T20:18:00.000Z",
      }),
      Object.freeze({
        id: "timeline-gate-note",
        incidentId: "incident-gate-medical",
        actorName: "Omar ICOperator",
        entryType: "operational_note",
        body: "Responder is on scene and monitoring breathing.",
        createdAt: "2027-07-04T20:32:00.000Z",
      }),
    ]),
  }),
  Object.freeze({
    id: "incident-radio-check",
    eventId: LOCAL_IMS_EVENT_ID,
    incidentNumber: "INC-2027-000041",
    title: "Radio relay check",
    status: "monitoring",
    priorityLabel: "Routine",
    incidentTypeNames: Object.freeze(["Radio"]),
    responders: Object.freeze([]),
    linkedIncidents: Object.freeze([
      Object.freeze({
        id: "incident-gate-medical",
        incidentNumber: "INC-2027-000042",
        title: "Medical assist near Gate A",
        status: "on_scene",
      }),
    ]),
    attachedFieldReports: Object.freeze([]),
    startedAt: "2027-07-04T19:40:00.000Z",
    locationName: "Ranger HQ",
    locationAddress: null,
    locationDetails: "Monitoring signal reports from the west side.",
    createdByName: "Omar ICOperator",
    createdAt: "2027-07-04T19:45:00.000Z",
    updatedAt: "2027-07-04T19:56:00.000Z",
    nameReferenceChips: Object.freeze([]),
    tagChips: Object.freeze([]),
    timelineEntries: Object.freeze([
      Object.freeze({
        id: "timeline-radio-opened",
        incidentId: "incident-radio-check",
        actorName: "Omar ICOperator",
        entryType: "incident_opened",
        body: "Incident INC-2027-000041 opened.",
        createdAt: "2027-07-04T19:45:00.000Z",
      }),
      Object.freeze({
        id: "timeline-radio-note",
        incidentId: "incident-radio-check",
        actorName: "Omar ICOperator",
        entryType: "operational_note",
        body: "Monitoring signal reports from the west side.",
        createdAt: "2027-07-04T19:56:00.000Z",
      }),
    ]),
  }),
  Object.freeze({
    id: "incident-closed-supply",
    eventId: LOCAL_IMS_EVENT_ID,
    incidentNumber: "INC-2027-000040",
    title: "Closed supply handoff",
    status: "closed",
    priorityLabel: "Important",
    incidentTypeNames: Object.freeze(["Logistics"]),
    responders: Object.freeze([]),
    linkedIncidents: Object.freeze([]),
    attachedFieldReports: Object.freeze([]),
    startedAt: "2027-07-04T18:05:00.000Z",
    locationName: "Depot",
    locationAddress: null,
    locationDetails: "Resolved supply handoff at the depot.",
    createdByName: "Ingrid ICLead",
    createdAt: "2027-07-04T18:10:00.000Z",
    updatedAt: "2027-07-04T18:45:00.000Z",
    nameReferenceChips: Object.freeze([]),
    tagChips: Object.freeze([
      Object.freeze({
        tag: "logistics",
        normalizedTag: "logistics",
      }),
    ]),
    timelineEntries: Object.freeze([
      Object.freeze({
        id: "timeline-closed-supply-opened",
        incidentId: "incident-closed-supply",
        actorName: "Ingrid ICLead",
        entryType: "incident_opened",
        body: "Incident INC-2027-000040 opened.",
        createdAt: "2027-07-04T18:10:00.000Z",
      }),
      Object.freeze({
        id: "timeline-closed-supply-note",
        incidentId: "incident-closed-supply",
        actorName: "Ingrid ICLead",
        entryType: "operational_note",
        body: "Closed after supplies were handed off. #logistics",
        createdAt: "2027-07-04T18:45:00.000Z",
      }),
    ]),
  }),
]);

const LOCAL_FIELD_REPORTS: readonly FieldReportLinkCandidate[] = Object.freeze([
  Object.freeze({
    id: "field-report-medical-gate",
    eventId: LOCAL_IMS_EVENT_ID,
    displayNumber: "FRA-2027-000123",
    title: "Medical observation near Gate A",
    authorName: "Vera Ranger",
    body: "Observed medical response near Gate A for @Blue-Hat. Follow-up requested. #medical",
    createdAt: "2027-07-04T20:34:00.000Z",
  }),
  Object.freeze({
    id: "field-report-radio-relay",
    eventId: LOCAL_IMS_EVENT_ID,
    displayNumber: "FRA-2027-000122",
    title: "Radio relay notes",
    authorName: "Omar Operator",
    body: "West-side relay heard intermittent traffic from Ranger HQ. #radio",
    createdAt: "2027-07-04T20:36:00.000Z",
  }),
  Object.freeze({
    id: "field-report-newer-logistics",
    eventId: LOCAL_IMS_EVENT_ID,
    displayNumber: "FRA-2027-000124",
    title: "Supply cart movement",
    authorName: "Ingrid ICLead",
    body: "Logistics moved shade supplies toward the north road. #logistics",
    createdAt: "2027-07-04T20:38:00.000Z",
  }),
]);

let session: IncidentSessionContext | null = null;
let noteSequence = 0;
let incidentSequence = 42;
let fieldUpdateSequence = 0;
let incidentLinkSequence = 0;
let incidentFieldReportLinkSequence = 0;
let incidentListOpenMode: IncidentListOpenMode = "view";

const localIncidentListPresets = new Map<string, IncidentListPreset>();
let incidentListPresetSequence = 0;
const localTimelineEntries = new Map<string, IncidentTimelineEntry[]>();
const localTimelineEntryOverrides = new Map<string, IncidentTimelineEntry>();
const localIncidentUpdatedAt = new Map<string, string>();
const localIncidentOverrides = new Map<string, ImsIncident>();
const localCreatedIncidents = new Map<string, ImsIncident>();

export function installDevelopmentIncidentSession(): void {
  session = LOCAL_SESSION;
}

export function installIncidentSession(context: IncidentSessionContext): void {
  session = Object.freeze({ ...context });
}

export function clearIncidentSession(): void {
  session = null;
  localTimelineEntries.clear();
  localTimelineEntryOverrides.clear();
  localIncidentUpdatedAt.clear();
  localIncidentOverrides.clear();
  localCreatedIncidents.clear();
  localIncidentListPresets.clear();
  incidentListPresetSequence = 0;
  noteSequence = 0;
  incidentSequence = 42;
  fieldUpdateSequence = 0;
  incidentLinkSequence = 0;
  incidentFieldReportLinkSequence = 0;
  incidentListOpenMode = "view";
}

export function resolveIncidentSession(): IncidentSessionContext | null {
  return session;
}

export function hasIncidentCommandAccess(
  context: IncidentSessionContext | null,
): boolean {
  return context !== null && IC_ROLES.includes(context.role);
}

export function canAppendIncidentNote(
  context: IncidentSessionContext | null,
): boolean {
  return context?.role === "ic_operator" || context?.role === "ic_lead";
}

export function canEditIncident(
  context: IncidentSessionContext | null,
): boolean {
  return canAppendIncidentNote(context);
}

export function resolveIncidentListOpenMode(): IncidentListOpenMode {
  return incidentListOpenMode;
}

export function setIncidentListOpenMode(mode: IncidentListOpenMode): void {
  incidentListOpenMode = mode;
}

export function canPrintIncidentPdf(
  context: IncidentSessionContext | null,
): boolean {
  return context?.role === "ic_lead";
}

export function listIncidentsForSession(
  context: IncidentSessionContext | null,
  search = "",
): ImsIncident[] {
  if (!hasIncidentCommandAccess(context)) {
    return [];
  }

  const normalizedSearch = normalizeIncidentSearch(search);

  return [...LOCAL_INCIDENTS, ...localCreatedIncidents.values()]
    .filter((incident) => incident.eventId === context?.eventId)
    .map((incident) =>
      incidentWithLocalTimeline(localIncidentOverrides.get(incident.id) ?? incident),
    )
    .filter((incident) => incidentMatchesSearch(incident, normalizedSearch))
    .sort((left, right) => right.updatedAt.localeCompare(left.updatedAt));
}

/**
 * The caller's saved incident list presets for this event (M11.19).
 *
 * Presets are personal view state and never an authorization source: a session
 * without IC access sees none, and the list read is gated independently.
 */
export function listIncidentPresetsForSession(
  context: IncidentSessionContext | null,
): IncidentListPreset[] {
  if (!hasIncidentCommandAccess(context)) {
    return [];
  }

  return [...localIncidentListPresets.values()]
    .filter((preset) => preset.eventId === context?.eventId)
    .sort((left, right) =>
      left.name.localeCompare(right.name, undefined, { sensitivity: "base" }),
    );
}

export function saveIncidentPresetForSession(
  context: IncidentSessionContext | null,
  name: string,
  selection: IncidentListFilterSelection,
): IncidentListPreset {
  if (!hasIncidentCommandAccess(context)) {
    throw new Error("Incident Command access is required to save list presets.");
  }

  const trimmed = name.trim();

  if (trimmed.length === 0) {
    throw new Error("Preset name is required.");
  }

  if (trimmed.length > INCIDENT_LIST_PRESET_NAME_MAX_LENGTH) {
    throw new Error(
      `Preset name may not be greater than ${INCIDENT_LIST_PRESET_NAME_MAX_LENGTH} characters.`,
    );
  }

  const existing = listIncidentPresetsForSession(context).find(
    (preset) => preset.name.toLowerCase() === trimmed.toLowerCase(),
  );

  if (
    !existing &&
    listIncidentPresetsForSession(context).length >=
      INCIDENT_LIST_PRESET_MAX_PER_EVENT
  ) {
    throw new Error(
      `You already have ${INCIDENT_LIST_PRESET_MAX_PER_EVENT} saved incident list presets for this event. Delete one before saving another.`,
    );
  }

  const preset: IncidentListPreset = Object.freeze({
    id: existing?.id ?? `local-incident-preset-${++incidentListPresetSequence}`,
    eventId: context?.eventId ?? LOCAL_IMS_EVENT_ID,
    name: trimmed,
    filters: Object.freeze({ ...selection }),
    query: Object.freeze(incidentPresetQuery(selection)),
  });

  localIncidentListPresets.set(preset.id, preset);

  return preset;
}

export function deleteIncidentPresetForSession(
  context: IncidentSessionContext | null,
  presetId: string,
): void {
  if (!hasIncidentCommandAccess(context)) {
    throw new Error(
      "Incident Command access is required to delete list presets.",
    );
  }

  const preset = localIncidentListPresets.get(presetId);

  if (!preset || preset.eventId !== context?.eventId) {
    throw new Error("Saved incident list preset not found.");
  }

  localIncidentListPresets.delete(presetId);
}

/**
 * Only non-default values become query parameters, so an applied preset reads
 * as the selection it saved rather than a wall of redundant defaults.
 */
function incidentPresetQuery(
  selection: IncidentListFilterSelection,
): Record<string, string> {
  const defaults: IncidentListFilterSelection = {
    search: "",
    state: "active",
    priority: "all",
    type: "all",
    responder: "all",
    shift: "all",
    sort: "updated",
    direction: "desc",
  };
  const query: Record<string, string> = {};

  for (const key of Object.keys(defaults) as (keyof IncidentListFilterSelection)[]) {
    const value = selection[key];

    if (value !== "" && value !== defaults[key]) {
      query[key] = value;
    }
  }

  return query;
}

/**
 * Incident type labels in use for this event, for list filter controls
 * (M11.19; UI contract 15.1).
 */
export function incidentTypeOptionsForSession(
  context: IncidentSessionContext | null,
): string[] {
  const names = new Map<string, string>();

  for (const incident of listIncidentsForSession(context)) {
    for (const name of incident.incidentTypeNames) {
      const key = name.toLowerCase();

      if (!names.has(key)) {
        names.set(key, name);
      }
    }
  }

  return [...names.values()].sort((left, right) =>
    left.localeCompare(right, undefined, { sensitivity: "base" }),
  );
}

/**
 * Responders attached to this event's incidents, for list filter controls
 * (M11.19; UI contract 15.1).
 */
export function incidentResponderOptionsForSession(
  context: IncidentSessionContext | null,
): IncidentResponder[] {
  const responders = new Map<string, IncidentResponder>();

  for (const incident of listIncidentsForSession(context)) {
    for (const responder of incident.responders) {
      if (!responders.has(responder.staffId)) {
        responders.set(responder.staffId, responder);
      }
    }
  }

  return [...responders.values()].sort((left, right) =>
    left.displayName.localeCompare(right.displayName, undefined, {
      sensitivity: "base",
    }),
  );
}

export function listFieldReportsForSession(
  context: IncidentSessionContext | null,
): ImsFieldReportListItem[] {
  if (!hasIncidentCommandAccess(context)) {
    return [];
  }

  const incidents = listIncidentsForSession(context);

  return LOCAL_FIELD_REPORTS.filter(
    (report) => report.eventId === context?.eventId,
  )
    .map((report) =>
      Object.freeze({
        ...report,
        relatedIncidents: Object.freeze(
          incidents
            .filter((incident) =>
              incident.attachedFieldReports.some(
                (attached) => attached.id === report.id,
              ),
            )
            .map((incident) =>
              Object.freeze({
                id: incident.id,
                incidentNumber: incident.incidentNumber,
                title: incident.title,
                status: incident.status,
                priorityLabel: incident.priorityLabel,
              }),
            ),
        ),
      }),
    )
    .sort((left, right) => right.createdAt.localeCompare(left.createdAt));
}

export function findIncidentForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
): ImsIncident | null {
  return (
    listIncidentsForSession(context).find(
      (incident) => incident.id === incidentId,
    ) ?? null
  );
}

export function findFieldReportForSession(
  context: IncidentSessionContext | null,
  fieldReportId: string,
): ImsFieldReportListItem | null {
  return (
    listFieldReportsForSession(context).find(
      (report) => report.id === fieldReportId,
    ) ?? null
  );
}

function findStoredIncidentForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
): ImsIncident | null {
  if (!hasIncidentCommandAccess(context)) {
    return null;
  }

  const incident =
    localIncidentOverrides.get(incidentId) ??
    localCreatedIncidents.get(incidentId) ??
    LOCAL_INCIDENTS.find((candidate) => candidate.id === incidentId) ??
    null;

  return incident?.eventId === context?.eventId ? incident : null;
}

export function appendIncidentNoteForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
  body: string,
  createdAt = new Date(),
): ImsIncident {
  if (!canAppendIncidentNote(context)) {
    throw new Error("Only IC operators and IC leads may add incident notes.");
  }

  const trimmedBody = body.trim();
  if (trimmedBody.length === 0) {
    throw new Error("Incident note body is required.");
  }

  const incident = findStoredIncidentForSession(context, incidentId);
  if (!incident) {
    throw new Error("Incident not found for this event.");
  }

  const timestamp = createdAt.toISOString();
  const entry: IncidentTimelineEntry = Object.freeze({
    id: `local-incident-note-${++noteSequence}`,
    incidentId: incident.id,
    actorName: context?.roleLabel ?? null,
    entryType: "operational_note",
    body: trimmedBody,
    createdAt: timestamp,
  });

  localTimelineEntries.set(incident.id, [
    ...(localTimelineEntries.get(incident.id) ?? []),
    entry,
  ]);
  localIncidentUpdatedAt.set(incident.id, timestamp);

  return findIncidentForSession(context, incidentId) ?? incident;
}

export function strikeIncidentNoteForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
  timelineEntryId: string,
  reason: string,
  strickenAt = new Date(),
): ImsIncident {
  if (!canAppendIncidentNote(context)) {
    throw new Error("Only IC operators and IC leads may strike incident notes.");
  }

  const trimmedReason = reason.trim();
  if (trimmedReason.length === 0) {
    throw new Error("Incident note strike reason is required.");
  }

  const incident = findIncidentForSession(context, incidentId);
  if (!incident) {
    throw new Error("Incident not found for this event.");
  }

  const entry = incident.timelineEntries.find(
    (candidate) => candidate.id === timelineEntryId,
  );
  if (!entry) {
    throw new Error("Incident note not found for this event.");
  }

  if (entry.entryType !== "operational_note") {
    throw new Error("Only operational notes may be stricken.");
  }

  if (entry.strickenAt) {
    throw new Error("Incident note is already stricken.");
  }

  const timestamp = strickenAt.toISOString();
  localTimelineEntryOverrides.set(
    entry.id,
    Object.freeze({
      ...entry,
      strickenAt: timestamp,
      strickenReason: trimmedReason,
    }),
  );
  localIncidentUpdatedAt.set(incident.id, timestamp);

  return findIncidentForSession(context, incidentId) ?? incident;
}

export function blankIncidentAutosaveForm(now = new Date()): IncidentAutosaveForm {
  return {
    title: "",
    status: "open",
    priorityLabel: "Routine",
    incidentTypeNames: [],
    responderStaffIds: [],
    startedAt: toDatetimeLocalValue(now),
    locationName: "",
    locationAddress: "",
    locationDetails: "",
  };
}

export function incidentToAutosaveForm(
  incident: ImsIncident,
): IncidentAutosaveForm {
  return {
    title: incident.title,
    status: incident.status,
    priorityLabel: incident.priorityLabel,
    incidentTypeNames: [...incident.incidentTypeNames],
    responderStaffIds: incident.responders.map((responder) => responder.staffId),
    startedAt: toDatetimeLocalValue(new Date(incident.startedAt)),
    locationName: incident.locationName ?? "",
    locationAddress: incident.locationAddress ?? "",
    locationDetails: incident.locationDetails ?? "",
  };
}

export function createIncidentFromAutosaveForm(
  context: IncidentSessionContext | null,
  form: IncidentAutosaveForm,
  previousForm: IncidentAutosaveForm | null = null,
  createdAt = new Date(),
): ImsIncident {
  if (!canEditIncident(context)) {
    throw new Error("Only IC operators and IC leads may create incidents.");
  }

  const timestamp = createdAt.toISOString();
  const id = `local-incident-${++incidentSequence}`;
  const startedAt = fromDatetimeLocalValue(form.startedAt, createdAt);
  const nextValues = {
    title: normalizedTitle(form.title),
    status: form.status,
    priorityLabel: validatedPriorityLabel(form.priorityLabel),
    incidentTypeNames: Object.freeze(normalizedStringList(form.incidentTypeNames)),
    responders: Object.freeze(respondersForStaffIds(form.responderStaffIds)),
    linkedIncidents: Object.freeze([]),
    attachedFieldReports: Object.freeze([]),
    startedAt,
    locationName: nullableText(form.locationName),
    locationAddress: nullableText(form.locationAddress),
    locationDetails: nullableText(form.locationDetails),
  };
  const previousValue = previousForm
    ? changedInitialAutosaveFields(previousForm, nextValues, createdAt, "before")
    : {};
  const newValue = previousForm
    ? changedInitialAutosaveFields(previousForm, nextValues, createdAt, "after")
    : {};
  const incident: ImsIncident = Object.freeze({
    id,
    eventId: context?.eventId ?? LOCAL_IMS_EVENT_ID,
    incidentNumber: `INC-2027-${String(incidentSequence).padStart(6, "0")}`,
    ...nextValues,
    createdByName: context?.roleLabel ?? null,
    createdAt: timestamp,
    updatedAt: timestamp,
    nameReferenceChips: Object.freeze([]),
    tagChips: Object.freeze([]),
    timelineEntries: Object.freeze([
      Object.freeze({
        id: `local-incident-opened-${incidentSequence}`,
        incidentId: id,
        actorName: context?.roleLabel ?? null,
        entryType: "incident_opened",
        body: `Incident INC-2027-${String(incidentSequence).padStart(6, "0")} opened.`,
        createdAt: timestamp,
      }),
      ...(Object.keys(newValue).length > 0
        ? [
            Object.freeze({
              id: `local-incident-field-${++fieldUpdateSequence}`,
              incidentId: id,
              actorName: context?.roleLabel ?? null,
              entryType: "incident_field_updated" as const,
              body: timelineFieldUpdateBody(newValue),
              previousValue,
              newValue,
              createdAt: timestamp,
            }),
          ]
        : []),
    ]),
  });

  localCreatedIncidents.set(incident.id, incident);

  return incident;
}

export function updateIncidentFromAutosaveForm(
  context: IncidentSessionContext | null,
  incidentId: string,
  form: IncidentAutosaveForm,
  updatedAt = new Date(),
): ImsIncident {
  if (!canEditIncident(context)) {
    throw new Error("Only IC operators and IC leads may edit incidents.");
  }

  const incident = findStoredIncidentForSession(context, incidentId);
  if (!incident) {
    throw new Error("Incident not found for this event.");
  }

  const timestamp = updatedAt.toISOString();
  const nextValues = {
    title: normalizedTitle(form.title),
    status: form.status,
    priorityLabel: validatedPriorityLabel(form.priorityLabel),
    incidentTypeNames: Object.freeze(normalizedStringList(form.incidentTypeNames)),
    responders: Object.freeze(respondersForStaffIds(form.responderStaffIds)),
    startedAt: fromDatetimeLocalValue(form.startedAt, updatedAt),
    locationName: nullableText(form.locationName),
    locationAddress: nullableText(form.locationAddress),
    locationDetails: nullableText(form.locationDetails),
  };
  const previousValue = changedAutosaveFields(incident, nextValues, "before");
  const newValue = changedAutosaveFields(incident, nextValues, "after");

  if (Object.keys(newValue).length === 0) {
    return incident;
  }

  const nextIncident: ImsIncident = Object.freeze({
    ...incident,
    ...nextValues,
    updatedAt: timestamp,
  });

  localIncidentOverrides.set(incident.id, nextIncident);
  localIncidentUpdatedAt.set(incident.id, timestamp);
  localTimelineEntries.set(incident.id, [
    ...(localTimelineEntries.get(incident.id) ?? []),
    Object.freeze({
      id: `local-incident-field-${++fieldUpdateSequence}`,
      incidentId: incident.id,
      actorName: context?.roleLabel ?? null,
      entryType: "incident_field_updated",
      body: timelineFieldUpdateBody(newValue),
      previousValue,
      newValue,
      createdAt: timestamp,
    }),
  ]);

  return findIncidentForSession(context, incidentId) ?? nextIncident;
}

export function availableLinkedIncidentOptionsForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
  search = "",
): ImsIncident[] {
  const incident = findIncidentForSession(context, incidentId);

  if (!incident) {
    return [];
  }

  const selectedIds = new Set(incident.linkedIncidents.map((linked) => linked.id));
  const normalizedSearch = normalizeIncidentSearch(search);
  const incidentTags = linkSuggestionTagSet(incident);

  return listIncidentsForSession(context)
    .filter((candidate) => candidate.id !== incident.id)
    .filter((candidate) => !selectedIds.has(candidate.id))
    .filter((candidate) => incidentMatchesSearch(candidate, normalizedSearch))
    .sort((left, right) =>
      compareLinkSuggestionCandidates(left, right, incidentTags),
    )
    .slice(0, 6);
}

export function availableFieldReportOptionsForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
  search = "",
): FieldReportLinkCandidate[] {
  const incident = findIncidentForSession(context, incidentId);

  if (!incident) {
    return [];
  }

  const selectedIds = new Set(
    incident.attachedFieldReports.map((report) => report.id),
  );
  const normalizedSearch = normalizeIncidentSearch(search);
  const incidentTags = linkSuggestionTagSet(incident);

  return LOCAL_FIELD_REPORTS.filter(
    (report) => report.eventId === context?.eventId,
  )
    .filter((report) => !selectedIds.has(report.id))
    .filter((report) => fieldReportMatchesSearch(report, normalizedSearch))
    .sort((left, right) =>
      compareFieldReportSuggestionCandidates(left, right, incidentTags),
    )
    .slice(0, 6);
}

export function linkIncidentForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
  targetIncidentId: string,
  createdAt = new Date(),
): ImsIncident {
  if (!canEditIncident(context)) {
    throw new Error("Only IC operators and IC leads may link incidents.");
  }

  if (incidentId === targetIncidentId) {
    throw new Error("An incident cannot be linked to itself.");
  }

  const incident = findStoredIncidentForSession(context, incidentId);
  const target = findStoredIncidentForSession(context, targetIncidentId);

  if (!incident || !target) {
    throw new Error("Linked incident not found for this event.");
  }

  if (incident.eventId !== target.eventId) {
    throw new Error("Linked incidents must belong to the same event.");
  }

  if (incident.linkedIncidents.some((linked) => linked.id === target.id)) {
    throw new Error("Incidents are already linked.");
  }

  const timestamp = createdAt.toISOString();
  writeLinkedIncidentPair(incident, target, timestamp, "incident_linked", context);

  return findIncidentForSession(context, incidentId) ?? incident;
}

export function unlinkIncidentForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
  targetIncidentId: string,
  createdAt = new Date(),
): ImsIncident {
  if (!canEditIncident(context)) {
    throw new Error("Only IC operators and IC leads may link incidents.");
  }

  const incident = findStoredIncidentForSession(context, incidentId);
  const target = findStoredIncidentForSession(context, targetIncidentId);

  if (!incident || !target) {
    throw new Error("Linked incident not found for this event.");
  }

  if (!incident.linkedIncidents.some((linked) => linked.id === target.id)) {
    throw new Error("Incidents are not currently linked.");
  }

  const timestamp = createdAt.toISOString();
  writeLinkedIncidentPair(incident, target, timestamp, "incident_unlinked", context);

  return findIncidentForSession(context, incidentId) ?? incident;
}

export function linkFieldReportForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
  fieldReportId: string,
  createdAt = new Date(),
): ImsIncident {
  if (!canEditIncident(context)) {
    throw new Error("Only IC operators and IC leads may link Field Reports.");
  }

  const incident = findStoredIncidentForSession(context, incidentId);
  const report = LOCAL_FIELD_REPORTS.find(
    (candidate) => candidate.id === fieldReportId,
  );

  if (!incident) {
    throw new Error("Incident not found for this event.");
  }

  if (!report || report.eventId !== context?.eventId) {
    throw new Error("Field Report not found for this event.");
  }

  if (incident.eventId !== report.eventId) {
    throw new Error("Field Report must belong to the same event as the incident.");
  }

  if (incident.attachedFieldReports.some((attached) => attached.id === report.id)) {
    throw new Error("Field Report is already linked to this incident.");
  }

  const timestamp = createdAt.toISOString();
  const linkId = `local-incident-field-report-link-${++incidentFieldReportLinkSequence}`;
  const nextIncident = Object.freeze({
    ...incident,
    attachedFieldReports: Object.freeze(
      mergeAttachedFieldReports([
        ...incident.attachedFieldReports,
        attachedFieldReportSummary(report, timestamp),
      ]),
    ),
    updatedAt: timestamp,
  });

  localIncidentOverrides.set(incident.id, nextIncident);
  localIncidentUpdatedAt.set(incident.id, timestamp);
  localTimelineEntries.set(incident.id, [
    ...(localTimelineEntries.get(incident.id) ?? []),
    fieldReportLinkedTimelineEntry(incident, report, linkId, timestamp, context),
  ]);

  return findIncidentForSession(context, incidentId) ?? nextIncident;
}

export function unlinkFieldReportForSession(
  context: IncidentSessionContext | null,
  incidentId: string,
  fieldReportId: string,
  createdAt = new Date(),
): ImsIncident {
  if (!canEditIncident(context)) {
    throw new Error("Only IC operators and IC leads may link Field Reports.");
  }

  const incident = findStoredIncidentForSession(context, incidentId);
  const report = LOCAL_FIELD_REPORTS.find(
    (candidate) => candidate.id === fieldReportId,
  );

  if (!incident) {
    throw new Error("Incident not found for this event.");
  }

  if (!report || report.eventId !== context?.eventId) {
    throw new Error("Field Report not found for this event.");
  }

  if (incident.eventId !== report.eventId) {
    throw new Error("Field Report must belong to the same event as the incident.");
  }

  if (!incident.attachedFieldReports.some((attached) => attached.id === report.id)) {
    throw new Error("Field Report is not currently linked to this incident.");
  }

  const timestamp = createdAt.toISOString();
  const nextIncident = Object.freeze({
    ...incident,
    attachedFieldReports: Object.freeze(
      incident.attachedFieldReports.filter(
        (attached) => attached.id !== report.id,
      ),
    ),
    updatedAt: timestamp,
  });
  const strickenReason = "Field Report removed from incident.";
  const timelineEntries = localTimelineEntries.get(incident.id) ?? [];

  localIncidentOverrides.set(incident.id, nextIncident);
  localIncidentUpdatedAt.set(incident.id, timestamp);
  localTimelineEntries.set(incident.id, [
    ...timelineEntries.map((entry) =>
      entry.entryType === "field_report_linked" &&
      entry.newValue?.fieldReportId === report.id &&
      !entry.strickenAt
        ? Object.freeze({
            ...entry,
            strickenAt: timestamp,
            strickenReason,
          })
        : entry,
    ),
    fieldReportUnlinkedTimelineEntry(incident, report, timestamp, context),
  ]);

  return findIncidentForSession(context, incidentId) ?? nextIncident;
}

export function statusLabel(status: ImsIncident["status"]): string {
  return {
    open: "Open",
    on_scene: "On Scene",
    monitoring: "Monitoring",
    on_hold: "On Hold",
    closed: "Closed",
  }[status];
}

export function formatIncidentDateTime(value: string): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const year = String(date.getFullYear());
  const hour = String(date.getHours()).padStart(2, "0");
  const minute = String(date.getMinutes()).padStart(2, "0");

  return `${month}-${day}-${year} ${hour}:${minute}`;
}

export function visibleIncidentTimelineEntries(
  entries: readonly IncidentTimelineEntry[],
  showFullHistory: boolean,
): readonly IncidentTimelineEntry[] {
  if (showFullHistory) {
    return entries;
  }

  const latestOpenCloseEntryId = latestOpenCloseStatusEntry(entries)?.id ?? null;

  return entries.filter((entry) => {
    if (entry.strickenAt) {
      return false;
    }

    if (
      entry.entryType === "incident_opened" ||
      entry.entryType === "operational_note" ||
      entry.entryType === "field_report_linked"
    ) {
      return true;
    }

    return entry.id === latestOpenCloseEntryId;
  });
}

function latestOpenCloseStatusEntry(
  entries: readonly IncidentTimelineEntry[],
): IncidentTimelineEntry | null {
  const statusEntries = entries.filter((entry) => {
    if (entry.strickenAt || entry.entryType !== "incident_field_updated") {
      return false;
    }

    const status = entry.newValue?.status;

    return status === "open" || status === "closed";
  });

  return statusEntries[statusEntries.length - 1] ?? null;
}

function incidentWithLocalTimeline(incident: ImsIncident): ImsIncident {
  const timelineEntries = [
    ...incident.timelineEntries,
    ...(localTimelineEntries.get(incident.id) ?? []),
  ]
    .map((entry) => localTimelineEntryOverrides.get(entry.id) ?? entry)
    .sort((left, right) => left.createdAt.localeCompare(right.createdAt));

  return Object.freeze({
    ...incident,
    updatedAt: localIncidentUpdatedAt.get(incident.id) ?? incident.updatedAt,
    nameReferenceChips: Object.freeze(
      mergeNameReferenceChips([
        ...incident.nameReferenceChips,
        ...extractIncidentNameReferences(incident, timelineEntries),
      ]),
    ),
    tagChips: Object.freeze(
      mergeTagChips([
        ...incident.tagChips,
        ...extractIncidentTags(incident, timelineEntries),
      ]),
    ),
    timelineEntries: Object.freeze(timelineEntries),
  });
}

function writeLinkedIncidentPair(
  incident: ImsIncident,
  target: ImsIncident,
  timestamp: string,
  entryType: "incident_linked" | "incident_unlinked",
  context: IncidentSessionContext | null,
): void {
  const link = entryType === "incident_linked";
  const nextIncident = Object.freeze({
    ...incident,
    linkedIncidents: Object.freeze(
      link
        ? mergeLinkedIncidents([
            ...incident.linkedIncidents,
            linkedIncidentSummary(target),
          ])
        : incident.linkedIncidents.filter((linked) => linked.id !== target.id),
    ),
    updatedAt: timestamp,
  });
  const nextTarget = Object.freeze({
    ...target,
    linkedIncidents: Object.freeze(
      link
        ? mergeLinkedIncidents([
            ...target.linkedIncidents,
            linkedIncidentSummary(incident),
          ])
        : target.linkedIncidents.filter((linked) => linked.id !== incident.id),
    ),
    updatedAt: timestamp,
  });

  localIncidentOverrides.set(incident.id, nextIncident);
  localIncidentOverrides.set(target.id, nextTarget);
  localIncidentUpdatedAt.set(incident.id, timestamp);
  localIncidentUpdatedAt.set(target.id, timestamp);
  localTimelineEntries.set(incident.id, [
    ...(localTimelineEntries.get(incident.id) ?? []),
    linkedIncidentTimelineEntry(incident, target, timestamp, entryType, context),
  ]);
  localTimelineEntries.set(target.id, [
    ...(localTimelineEntries.get(target.id) ?? []),
    linkedIncidentTimelineEntry(target, incident, timestamp, entryType, context),
  ]);
}

function linkedIncidentTimelineEntry(
  incident: ImsIncident,
  target: ImsIncident,
  timestamp: string,
  entryType: "incident_linked" | "incident_unlinked",
  context: IncidentSessionContext | null,
): IncidentTimelineEntry {
  const verb = entryType === "incident_linked" ? "Linked" : "Unlinked";

  return Object.freeze({
    id: `local-incident-link-${++incidentLinkSequence}`,
    incidentId: incident.id,
    actorName: context?.roleLabel ?? null,
    entryType,
    body: `${verb} related incident ${target.incidentNumber}: ${target.title || "Untitled incident"}.`,
    previousValue:
      entryType === "incident_unlinked"
        ? { linkedIncidentId: target.id }
        : undefined,
    newValue:
      entryType === "incident_linked"
        ? { linkedIncidentId: target.id }
        : undefined,
    createdAt: timestamp,
  });
}

function linkedIncidentSummary(incident: ImsIncident): LinkedIncidentSummary {
  return Object.freeze({
    id: incident.id,
    incidentNumber: incident.incidentNumber,
    title: incident.title,
    status: incident.status,
  });
}

function attachedFieldReportSummary(
  report: FieldReportLinkCandidate,
  linkedAt: string,
): AttachedFieldReportSummary {
  return Object.freeze({
    id: report.id,
    displayNumber: report.displayNumber,
    title: report.title,
    authorName: report.authorName,
    body: report.body,
    linkedAt,
  });
}

function mergeLinkedIncidents(
  linkedIncidents: readonly LinkedIncidentSummary[],
): LinkedIncidentSummary[] {
  const byId = new Map<string, LinkedIncidentSummary>();

  for (const incident of linkedIncidents) {
    if (!byId.has(incident.id)) {
      byId.set(incident.id, incident);
    }
  }

  return [...byId.values()].sort((left, right) =>
    left.incidentNumber.localeCompare(right.incidentNumber),
  );
}

function mergeAttachedFieldReports(
  attachedFieldReports: readonly AttachedFieldReportSummary[],
): AttachedFieldReportSummary[] {
  const byId = new Map<string, AttachedFieldReportSummary>();

  for (const report of attachedFieldReports) {
    if (!byId.has(report.id)) {
      byId.set(report.id, report);
    }
  }

  return [...byId.values()].sort((left, right) =>
    left.displayNumber.localeCompare(right.displayNumber),
  );
}

function fieldReportLinkedTimelineEntry(
  incident: ImsIncident,
  report: FieldReportLinkCandidate,
  linkId: string,
  timestamp: string,
  context: IncidentSessionContext | null,
): IncidentTimelineEntry {
  return Object.freeze({
    id: `local-field-report-linked-${incidentFieldReportLinkSequence}`,
    incidentId: incident.id,
    actorName: context?.roleLabel ?? null,
    entryType: "field_report_linked",
    body: `Field Report: ${report.title}\nAuthor: ${report.authorName}\n${report.body}`,
    newValue: {
      fieldReportId: report.id,
      incidentFieldReportId: linkId,
    },
    createdAt: timestamp,
    strickenAt: null,
    strickenReason: null,
  });
}

function fieldReportUnlinkedTimelineEntry(
  incident: ImsIncident,
  report: FieldReportLinkCandidate,
  timestamp: string,
  context: IncidentSessionContext | null,
): IncidentTimelineEntry {
  return Object.freeze({
    id: `local-field-report-unlinked-${++incidentFieldReportLinkSequence}`,
    incidentId: incident.id,
    actorName: context?.roleLabel ?? null,
    entryType: "field_report_unlinked",
    body: `Removed Field Report ${report.displayNumber}: ${report.title}.`,
    previousValue: {
      fieldReportId: report.id,
    },
    createdAt: timestamp,
  });
}

function compareLinkSuggestionCandidates(
  left: ImsIncident,
  right: ImsIncident,
  incidentTags: ReadonlySet<string>,
): number {
  const leftSharesTags = sharesLinkSuggestionTag(left, incidentTags);
  const rightSharesTags = sharesLinkSuggestionTag(right, incidentTags);

  if (leftSharesTags !== rightSharesTags) {
    return leftSharesTags ? -1 : 1;
  }

  const createdAtComparison = right.createdAt.localeCompare(left.createdAt);

  return createdAtComparison === 0
    ? right.incidentNumber.localeCompare(left.incidentNumber)
    : createdAtComparison;
}

function compareFieldReportSuggestionCandidates(
  left: FieldReportLinkCandidate,
  right: FieldReportLinkCandidate,
  incidentTags: ReadonlySet<string>,
): number {
  const leftSharesTags = sharesFieldReportSuggestionTag(left, incidentTags);
  const rightSharesTags = sharesFieldReportSuggestionTag(right, incidentTags);

  if (leftSharesTags !== rightSharesTags) {
    return leftSharesTags ? -1 : 1;
  }

  const createdAtComparison = right.createdAt.localeCompare(left.createdAt);

  return createdAtComparison === 0
    ? right.displayNumber.localeCompare(left.displayNumber)
    : createdAtComparison;
}

function sharesLinkSuggestionTag(
  incident: ImsIncident,
  incidentTags: ReadonlySet<string>,
): boolean {
  if (incidentTags.size === 0) {
    return false;
  }

  return [...linkSuggestionTagSet(incident)].some((tag) =>
    incidentTags.has(tag),
  );
}

function sharesFieldReportSuggestionTag(
  report: FieldReportLinkCandidate,
  incidentTags: ReadonlySet<string>,
): boolean {
  if (incidentTags.size === 0) {
    return false;
  }

  return [...fieldReportSuggestionTagSet(report)].some((tag) =>
    incidentTags.has(tag),
  );
}

function linkSuggestionTagSet(incident: ImsIncident): Set<string> {
  return new Set(
    [
      ...incident.tagChips.map((chip) => chip.normalizedTag),
      ...[
        incident.title,
        ...incident.incidentTypeNames,
        ...incident.responders.map((responder) => responder.displayName),
        ...incident.attachedFieldReports.map((report) => report.body),
        ...incident.timelineEntries
          .filter((entry) => !entry.strickenAt)
          .map((entry) => entry.body ?? ""),
      ].flatMap(parseTags).map((chip) => chip.normalizedTag),
    ].filter((tag) => tag.length > 0),
  );
}

function fieldReportSuggestionTagSet(report: FieldReportLinkCandidate): Set<string> {
  return new Set(
    [report.title, report.body]
      .flatMap(parseTags)
      .map((chip) => chip.normalizedTag)
      .filter((tag) => tag.length > 0),
  );
}

function normalizeIncidentSearch(search: string): string {
  const trimmed = search.trim();

  return trimmed.startsWith("@") || trimmed.startsWith("#")
    ? trimmed.slice(1).toLowerCase()
    : trimmed.toLowerCase();
}

function fieldReportMatchesSearch(
  report: FieldReportLinkCandidate,
  normalizedSearch: string,
): boolean {
  if (normalizedSearch.length === 0) {
    return true;
  }

  const searchableText = [
    report.displayNumber,
    report.title,
    report.authorName,
    report.body,
  ].join(" ").toLowerCase();

  return (
    searchableText.includes(normalizedSearch) ||
    parseTags(report.body).some((chip) => chip.normalizedTag === normalizedSearch)
  );
}

function incidentMatchesSearch(
  incident: ImsIncident,
  normalizedSearch: string,
): boolean {
  if (normalizedSearch.length === 0) {
    return true;
  }

  // Mirrors the server list search (M11.19): the incident record, its active
  // notes, and its attached Field Reports. Stricken history stays out of search
  // for the same reason it stays out of the default timeline.
  const searchableText = [
    incident.incidentNumber,
    incident.title,
    incident.locationName ?? "",
    incident.locationAddress ?? "",
    incident.locationDetails ?? "",
    ...incident.incidentTypeNames,
    ...incident.responders.map((responder) => responder.displayName),
    ...incident.timelineEntries
      .filter((entry) => !entry.strickenAt)
      .map((entry) => entry.body ?? ""),
    ...incident.attachedFieldReports.flatMap((report) => [
      report.displayNumber,
      report.title,
      report.body,
    ]),
  ].join(" ").toLowerCase();

  return (
    searchableText.includes(normalizedSearch) ||
    incident.nameReferenceChips.some(
      (chip) => chip.normalizedToken === normalizedSearch,
    ) ||
    incident.tagChips.some((chip) => chip.normalizedTag === normalizedSearch)
  );
}

function extractIncidentNameReferences(
  incident: ImsIncident,
  timelineEntries: readonly IncidentTimelineEntry[],
): NameReferenceChip[] {
  return [
    incident.title,
    incident.locationName ?? "",
    incident.locationAddress ?? "",
    incident.locationDetails ?? "",
    ...incident.incidentTypeNames,
    ...incident.responders.map((responder) => responder.displayName),
    ...incident.attachedFieldReports.map((report) => report.body),
    ...timelineEntries
      .filter((entry) => entry.entryType !== "field_report_linked")
      .filter((entry) => !entry.strickenAt)
      .map((entry) => entry.body ?? ""),
  ].flatMap(parseNameReferences);
}

function extractIncidentTags(
  incident: ImsIncident,
  timelineEntries: readonly IncidentTimelineEntry[],
): IncidentTagChip[] {
  return [
    incident.title,
    ...incident.incidentTypeNames,
    ...incident.responders.map((responder) => responder.displayName),
    ...incident.attachedFieldReports.map((report) => report.body),
    ...timelineEntries
      .filter((entry) => entry.entryType !== "field_report_linked")
      .filter((entry) => !entry.strickenAt)
      .map((entry) => entry.body ?? ""),
  ].flatMap(parseTags);
}

function parseNameReferences(text: string): NameReferenceChip[] {
  const matches = text.matchAll(/@([A-Za-z0-9_-]+)/gu);

  return mergeNameReferenceChips(
    [...matches].map((match) => ({
      token: match[1] ?? "",
      normalizedToken: (match[1] ?? "").toLowerCase(),
    })),
  );
}

function parseTags(text: string): IncidentTagChip[] {
  const matches = text.matchAll(/#([A-Za-z0-9_-]+)/gu);

  return mergeTagChips(
    [...matches].map((match) => ({
      tag: match[1] ?? "",
      normalizedTag: (match[1] ?? "").toLowerCase(),
    })),
  );
}

function mergeNameReferenceChips(
  chips: readonly NameReferenceChip[],
): NameReferenceChip[] {
  const byNormalizedToken = new Map<string, NameReferenceChip>();

  for (const chip of chips) {
    if (chip.normalizedToken.length === 0) {
      continue;
    }

    if (!byNormalizedToken.has(chip.normalizedToken)) {
      byNormalizedToken.set(chip.normalizedToken, chip);
    }
  }

  return [...byNormalizedToken.values()];
}

function mergeTagChips(chips: readonly IncidentTagChip[]): IncidentTagChip[] {
  const byNormalizedTag = new Map<string, IncidentTagChip>();

  for (const chip of chips) {
    if (chip.normalizedTag.length === 0) {
      continue;
    }

    if (!byNormalizedTag.has(chip.normalizedTag)) {
      byNormalizedTag.set(chip.normalizedTag, chip);
    }
  }

  return [...byNormalizedTag.values()];
}

function normalizedTitle(value: string): string {
  const title = value.trim();

  if (title.length > 200) {
    throw new Error("Incident title may not be greater than 200 characters.");
  }

  return title;
}

function validatedPriorityLabel(value: IncidentPriorityLabel): IncidentPriorityLabel {
  if (!INCIDENT_PRIORITY_LABELS.includes(value)) {
    throw new Error("Incident priority label is invalid.");
  }

  return value;
}

function normalizedStringList(values: readonly string[]): string[] {
  const seen = new Set<string>();
  const nextValues: string[] = [];

  for (const value of values) {
    const trimmed = value.trim();

    if (trimmed.length === 0) {
      continue;
    }

    const key = trimmed.toLowerCase();

    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    nextValues.push(trimmed);
  }

  return nextValues;
}

function respondersForStaffIds(staffIds: readonly string[]): IncidentResponder[] {
  const selectedIds = new Set(staffIds);

  return RESPONDER_OPTIONS.filter((responder) =>
    selectedIds.has(responder.staffId),
  );
}

function nullableText(value: string): string | null {
  const trimmed = value.trim();

  return trimmed.length === 0 ? null : trimmed;
}

function toDatetimeLocalValue(date: Date): string {
  if (Number.isNaN(date.getTime())) {
    return "";
  }

  const year = String(date.getFullYear()).padStart(4, "0");
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const hour = String(date.getHours()).padStart(2, "0");
  const minute = String(date.getMinutes()).padStart(2, "0");

  return `${year}-${month}-${day}T${hour}:${minute}`;
}

function fromDatetimeLocalValue(value: string, fallback: Date): string {
  const parsed = new Date(value);

  return Number.isNaN(parsed.getTime())
    ? fallback.toISOString()
    : parsed.toISOString();
}

function autosaveDiffSnapshot(
  incident: ImsIncident,
): Record<string, string | null> {
  return {
    title: incident.title,
    status: incident.status,
    priorityLabel: incident.priorityLabel,
    startedAt: incident.startedAt,
    locationName: incident.locationName,
    locationAddress: incident.locationAddress,
    locationDetails: incident.locationDetails,
    incidentTypeNames: incident.incidentTypeNames.join(", "),
    responders: incident.responders
      .map((responder) => responder.displayName)
      .join(", "),
  };
}

function changedAutosaveFields(
  incident: ImsIncident,
  nextValues: Pick<
    ImsIncident,
    | "title"
    | "status"
    | "priorityLabel"
    | "incidentTypeNames"
    | "responders"
    | "startedAt"
    | "locationName"
    | "locationAddress"
    | "locationDetails"
  >,
  direction: "before" | "after",
): Record<string, string | null> {
  const before = autosaveDiffSnapshot(incident);
  const after: Record<string, string | null> = {
    title: nextValues.title,
    status: nextValues.status,
    priorityLabel: nextValues.priorityLabel,
    startedAt: nextValues.startedAt,
    locationName: nextValues.locationName,
    locationAddress: nextValues.locationAddress,
    locationDetails: nextValues.locationDetails,
    incidentTypeNames: nextValues.incidentTypeNames.join(", "),
    responders: nextValues.responders
      .map((responder) => responder.displayName)
      .join(", "),
  };
  const values = direction === "before" ? before : after;
  const changed: Record<string, string | null> = {};

  for (const field of Object.keys(after)) {
    if (before[field] !== after[field]) {
      changed[field] = values[field] ?? null;
    }
  }

  return changed;
}

function changedInitialAutosaveFields(
  previousForm: IncidentAutosaveForm,
  nextValues: Pick<
    ImsIncident,
    | "title"
    | "status"
    | "priorityLabel"
    | "incidentTypeNames"
    | "responders"
    | "startedAt"
    | "locationName"
    | "locationAddress"
    | "locationDetails"
  >,
  fallback: Date,
  direction: "before" | "after",
): Record<string, string | null> {
  const before: Record<string, string | null> = {
    title: normalizedTitle(previousForm.title),
    status: previousForm.status,
    priorityLabel: previousForm.priorityLabel,
    startedAt: fromDatetimeLocalValue(previousForm.startedAt, fallback),
    locationName: nullableText(previousForm.locationName),
    locationAddress: nullableText(previousForm.locationAddress),
    locationDetails: nullableText(previousForm.locationDetails),
    incidentTypeNames: normalizedStringList(previousForm.incidentTypeNames).join(", "),
    responders: respondersForStaffIds(previousForm.responderStaffIds)
      .map((responder) => responder.displayName)
      .join(", "),
  };
  const after: Record<string, string | null> = {
    title: nextValues.title,
    status: nextValues.status,
    priorityLabel: nextValues.priorityLabel,
    startedAt: nextValues.startedAt,
    locationName: nextValues.locationName,
    locationAddress: nextValues.locationAddress,
    locationDetails: nextValues.locationDetails,
    incidentTypeNames: nextValues.incidentTypeNames.join(", "),
    responders: nextValues.responders
      .map((responder) => responder.displayName)
      .join(", "),
  };
  const values = direction === "before" ? before : after;
  const changed: Record<string, string | null> = {};

  for (const field of Object.keys(after)) {
    if (before[field] !== after[field]) {
      changed[field] = values[field] ?? null;
    }
  }

  return changed;
}

function timelineFieldUpdateBody(
  newValue: Record<string, string | null>,
): string {
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

  return field === "status" ? statusLabel(value as ImsIncident["status"]) : value;
}
