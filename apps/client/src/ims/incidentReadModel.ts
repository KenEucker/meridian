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
  }),
]);

let session: IncidentSessionContext | null = null;

export function installDevelopmentIncidentSession(): void {
  session = LOCAL_SESSION;
}

export function installIncidentSession(context: IncidentSessionContext): void {
  session = Object.freeze({ ...context });
}

export function clearIncidentSession(): void {
  session = null;
}

export function resolveIncidentSession(): IncidentSessionContext | null {
  return session;
}

export function hasIncidentCommandAccess(
  context: IncidentSessionContext | null,
): boolean {
  return context !== null && IC_ROLES.includes(context.role);
}

export function listIncidentsForSession(
  context: IncidentSessionContext | null,
): ImsIncident[] {
  if (!hasIncidentCommandAccess(context)) {
    return [];
  }

  return [...LOCAL_INCIDENTS]
    .filter((incident) => incident.eventId === context?.eventId)
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

export function statusLabel(status: ImsIncident["status"]): string {
  return {
    open: "Open",
    on_scene: "On Scene",
    monitoring: "Monitoring",
    on_hold: "On Hold",
    closed: "Closed",
  }[status];
}
