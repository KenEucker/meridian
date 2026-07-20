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
  readonly entryType: "incident_opened" | "operational_note";
  readonly body: string | null;
  readonly createdAt: string;
}

export interface NameReferenceChip {
  readonly token: string;
  readonly normalizedToken: string;
}

export interface ImsIncident {
  readonly id: string;
  readonly eventId: string;
  readonly incidentNumber: string;
  readonly title: string;
  readonly status: "open" | "on_scene" | "monitoring" | "on_hold" | "closed";
  readonly priorityLabel: string | null;
  readonly startedAt: string;
  readonly locationName: string | null;
  readonly locationAddress: string | null;
  readonly locationDetails: string | null;
  readonly createdByName: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly nameReferenceChips: readonly NameReferenceChip[];
  readonly timelineEntries: readonly IncidentTimelineEntry[];
}

export const LOCAL_IMS_EVENT_ID = "event-ims-local";

const IC_ROLES: readonly IncidentRole[] = [
  "ic_viewer",
  "ic_operator",
  "ic_lead",
];

const LOCAL_SESSION: IncidentSessionContext = Object.freeze({
  eventId: LOCAL_IMS_EVENT_ID,
  eventLabel: "Idaho Decompression 2026",
  organizationLabel: "Idaho Burners",
  icDepartmentLabel: "Rangers",
  role: "ic_viewer",
  roleLabel: "Incident Command Viewer",
});

const LOCAL_INCIDENTS: readonly ImsIncident[] = Object.freeze([
  Object.freeze({
    id: "incident-gate-medical",
    eventId: LOCAL_IMS_EVENT_ID,
    incidentNumber: "INC-2027-000042",
    title: "Medical assist near Gate A",
    status: "on_scene",
    priorityLabel: "Serious",
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
    timelineEntries: Object.freeze([
      Object.freeze({
        id: "timeline-gate-opened",
        incidentId: "incident-gate-medical",
        actorName: "Ingrid ICLead",
        entryType: "incident_opened",
        body: null,
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
    priorityLabel: null,
    startedAt: "2027-07-04T19:40:00.000Z",
    locationName: "Ranger HQ",
    locationAddress: null,
    locationDetails: "Monitoring signal reports from the west side.",
    createdByName: "Omar ICOperator",
    createdAt: "2027-07-04T19:45:00.000Z",
    updatedAt: "2027-07-04T19:56:00.000Z",
    nameReferenceChips: Object.freeze([]),
    timelineEntries: Object.freeze([
      Object.freeze({
        id: "timeline-radio-opened",
        incidentId: "incident-radio-check",
        actorName: "Omar ICOperator",
        entryType: "incident_opened",
        body: null,
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

const localTimelineEntries = new Map<string, IncidentTimelineEntry[]>();
const localIncidentUpdatedAt = new Map<string, string>();

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
  noteSequence = 0;
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

export function listIncidentsForSession(
  context: IncidentSessionContext | null,
  search = "",
): ImsIncident[] {
  if (!hasIncidentCommandAccess(context)) {
    return [];
  }

  const normalizedSearch = normalizeNameReferenceSearch(search);

  return [...LOCAL_INCIDENTS]
    .filter((incident) => incident.eventId === context?.eventId)
    .map((incident) => incidentWithLocalTimeline(incident))
    .filter((incident) => incidentMatchesNameReferenceSearch(incident, normalizedSearch))
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

  const incident = findIncidentForSession(context, incidentId);
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

export function statusLabel(status: ImsIncident["status"]): string {
  return {
    open: "Open",
    on_scene: "On Scene",
    monitoring: "Monitoring",
    on_hold: "On Hold",
    closed: "Closed",
  }[status];
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
        ...timelineEntries.flatMap((entry) => parseNameReferences(entry.body ?? "")),
      ]),
    ),
    timelineEntries: Object.freeze(timelineEntries),
  });
}

function normalizeNameReferenceSearch(search: string): string {
  const trimmed = search.trim();

  return trimmed.startsWith("@")
    ? trimmed.slice(1).toLowerCase()
    : trimmed.toLowerCase();
}

function incidentMatchesNameReferenceSearch(
  incident: ImsIncident,
  normalizedSearch: string,
): boolean {
  if (normalizedSearch.length === 0) {
    return true;
  }

  return incident.nameReferenceChips.some(
    (chip) => chip.normalizedToken === normalizedSearch,
  );
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
