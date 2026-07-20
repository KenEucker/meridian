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

export interface IncidentTimelineEntry {
  readonly id: string;
  readonly incidentId: string;
  readonly actorName: string | null;
  readonly entryType:
    | "incident_opened"
    | "operational_note"
    | "incident_field_updated";
  readonly body: string | null;
  readonly previousValue?: Record<string, string | null>;
  readonly newValue?: Record<string, string | null>;
  readonly createdAt: string;
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

export interface ImsIncident {
  readonly id: string;
  readonly eventId: string;
  readonly incidentNumber: string;
  readonly title: string;
  readonly status: "open" | "on_scene" | "monitoring" | "on_hold" | "closed";
  readonly priorityLabel: IncidentPriorityLabel;
  readonly incidentTypeNames: readonly string[];
  readonly responders: readonly IncidentResponder[];
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
  role: "ic_operator",
  roleLabel: "Incident Command Operator",
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
]);

let session: IncidentSessionContext | null = null;
let noteSequence = 0;
let incidentSequence = 42;
let fieldUpdateSequence = 0;

const localTimelineEntries = new Map<string, IncidentTimelineEntry[]>();
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
  localIncidentUpdatedAt.clear();
  localIncidentOverrides.clear();
  localCreatedIncidents.clear();
  noteSequence = 0;
  incidentSequence = 42;
  fieldUpdateSequence = 0;
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
  createdAt = new Date(),
): ImsIncident {
  if (!canEditIncident(context)) {
    throw new Error("Only IC operators and IC leads may create incidents.");
  }

  const title = validatedTitle(form.title);
  const timestamp = createdAt.toISOString();
  const id = `local-incident-${++incidentSequence}`;
  const incident: ImsIncident = Object.freeze({
    id,
    eventId: context?.eventId ?? LOCAL_IMS_EVENT_ID,
    incidentNumber: `INC-2027-${String(incidentSequence).padStart(6, "0")}`,
    title,
    status: form.status,
    priorityLabel: validatedPriorityLabel(form.priorityLabel),
    incidentTypeNames: Object.freeze(normalizedStringList(form.incidentTypeNames)),
    responders: Object.freeze(respondersForStaffIds(form.responderStaffIds)),
    startedAt: fromDatetimeLocalValue(form.startedAt, createdAt),
    locationName: nullableText(form.locationName),
    locationAddress: nullableText(form.locationAddress),
    locationDetails: nullableText(form.locationDetails),
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
    title: validatedTitle(form.title),
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

function incidentWithLocalTimeline(incident: ImsIncident): ImsIncident {
  const timelineEntries = [
    ...incident.timelineEntries,
    ...(localTimelineEntries.get(incident.id) ?? []),
  ].sort((left, right) => left.createdAt.localeCompare(right.createdAt));

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

function normalizeIncidentSearch(search: string): string {
  const trimmed = search.trim();

  return trimmed.startsWith("@") || trimmed.startsWith("#")
    ? trimmed.slice(1).toLowerCase()
    : trimmed.toLowerCase();
}

function incidentMatchesSearch(
  incident: ImsIncident,
  normalizedSearch: string,
): boolean {
  if (normalizedSearch.length === 0) {
    return true;
  }

  const searchableText = [
    incident.incidentNumber,
    incident.title,
    incident.locationName ?? "",
    incident.locationAddress ?? "",
    incident.locationDetails ?? "",
    ...incident.incidentTypeNames,
    ...incident.responders.map((responder) => responder.displayName),
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
    ...timelineEntries.map((entry) => entry.body ?? ""),
  ].flatMap(parseNameReferences);
}

function extractIncidentTags(
  incident: ImsIncident,
  timelineEntries: readonly IncidentTimelineEntry[],
): IncidentTagChip[] {
  return [
    incident.title,
    incident.locationName ?? "",
    incident.locationAddress ?? "",
    incident.locationDetails ?? "",
    ...incident.incidentTypeNames,
    ...incident.responders.map((responder) => responder.displayName),
    ...timelineEntries.map((entry) => entry.body ?? ""),
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

function validatedTitle(value: string): string {
  const title = value.trim();

  if (title.length === 0) {
    throw new Error("Incident title is required.");
  }

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
