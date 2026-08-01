// What a Meridian command is, and whether it may be queued (M16.10;
// CLIENT-015, CLIENT-018; technical spec 11A.5; data/API 5.2, 5.3, 5.6, 7.2).
//
// The outbox holds commands. This module is the only place that says what a
// command *is*: the endpoint it is submitted to, the label a surface shows for
// it, and — the part that matters — whether it can be written offline at all.
//
// Availability is a property of the command, never of the caller. Data/API 7.2
// lists the Alpha 1 offline writes and closes the list: "Offline-writable
// commands remain those listed in 7.2" (5.6). Everything else needs the node,
// so this catalog is default-deny — a command is connected-only unless it is
// registered as an offline write here. That is deliberate: the failure mode of
// guessing wrong in the other direction is telling a person their work is
// captured when it can never send, which is the outcome CLIENT-018 exists to
// prevent.
//
// The connected-only entries below are the four families technical spec 11A.5
// names by hand. Each carries the reason the refusal states, so a person who
// issues one where it cannot be sent is told what happened in words about their
// work rather than a generic failure. They were registered before the surfaces
// that issue them existed, so the rule was under test before there was a screen
// that could break it; the IMS surfaces bound in M16.20 issue theirs through
// `sendConnectedCommand` and are refused from here.

/** Every command this client can submit today. */
export type MeridianCommandType =
  // Alpha 1 offline writes (data/API 7.2).
  | "submit-field-report"
  | "check-in-staff"
  | "check-out-staff"
  | "mark-no-show"
  // Connected-only families named by technical spec 11A.5.
  | "create-incident"
  | "update-incident"
  | "append-incident-note"
  | "strike-incident-note"
  | "link-incident"
  | "unlink-incident"
  | "link-field-report"
  | "unlink-field-report"
  | "strike-incident-attachment"
  | "save-incident-list-preset"
  | "delete-incident-list-preset"
  | "acknowledge-document"
  | "submit-application"
  | "publish-event-map"
  | "archive-event-map"
  | "override-locked-map-data"
  | "designate-placement-department";

export interface CommandDescriptor {
  readonly type: MeridianCommandType;
  /** The command endpoint from data/API 5.2. */
  readonly path: string;
  /**
   * What a surface calls this command when it reports its state. Kept with the
   * command rather than with the caller so the same work reads the same way in
   * the outbox list, the Kiosk timeout screen, and device diagnostics.
   */
  readonly label: string;
  /** Whether data/API 7.2 lists this command as an Alpha 1 offline write. */
  readonly offlineWritable: boolean;
  /**
   * Why a connected-only command cannot be queued, in the words the person who
   * issued it is shown. `null` for an offline write, which has nothing to
   * explain (UI operating guide 17.7).
   */
  readonly connectedOnlyReason: string | null;
}

export class UnknownCommandError extends Error {
  constructor(commandType: string) {
    super(`\`${commandType}\` is not a registered Meridian command.`);
    this.name = "UnknownCommandError";
  }
}

function offlineWrite(
  type: MeridianCommandType,
  path: string,
  label: string,
): CommandDescriptor {
  return Object.freeze({
    type,
    path,
    label,
    offlineWritable: true,
    connectedOnlyReason: null,
  });
}

function connectedOnly(
  type: MeridianCommandType,
  path: string,
  label: string,
  connectedOnlyReason: string,
): CommandDescriptor {
  return Object.freeze({
    type,
    path,
    label,
    offlineWritable: false,
    connectedOnlyReason,
  });
}

const CATALOG: Readonly<Record<MeridianCommandType, CommandDescriptor>> =
  Object.freeze({
    "submit-field-report": offlineWrite(
      "submit-field-report",
      "/api/commands/submit-field-report",
      "Field Report",
    ),
    "check-in-staff": offlineWrite(
      "check-in-staff",
      "/api/commands/check-in-staff",
      "Check-in",
    ),
    "check-out-staff": offlineWrite(
      "check-out-staff",
      "/api/commands/check-out-staff",
      "Check-out",
    ),
    "mark-no-show": offlineWrite(
      "mark-no-show",
      "/api/commands/mark-no-show",
      "No-show",
    ),
    "create-incident": connectedOnly(
      "create-incident",
      "/api/commands/create-incident",
      "Incident",
      "Creating an incident needs a connection to the node. It cannot be held on this device for later.",
    ),
    /*
     * The rest of the incident family (M16.20). Technical spec 19.2 requires an
     * active server connection for incident mutations, so every one of these is
     * refused where it stands rather than held: an incident note written at a
     * dead camp and delivered four hours later is worse than one the writer was
     * told did not land while they could still say it on the radio.
     *
     * Saved list presets are here for a different reason. They are personal view
     * state and nothing operational turns on them, but they live on the node and
     * there is no local copy to reconcile, so queueing one would mean holding
     * work whose only effect is a name in a dropdown.
     */
    "update-incident": connectedOnly(
      "update-incident",
      "/api/commands/update-incident",
      "Incident edit",
      "Editing an incident needs a connection to the node. It cannot be held on this device for later.",
    ),
    "append-incident-note": connectedOnly(
      "append-incident-note",
      "/api/commands/append-incident-note",
      "Incident note",
      "Adding an incident note needs a connection to the node. It cannot be held on this device for later.",
    ),
    "strike-incident-note": connectedOnly(
      "strike-incident-note",
      "/api/commands/strike-incident-note",
      "Incident note strike",
      "Striking an incident note needs a connection to the node. It cannot be held on this device for later.",
    ),
    "link-incident": connectedOnly(
      "link-incident",
      "/api/commands/link-incident",
      "Incident link",
      "Linking incidents needs a connection to the node. It cannot be held on this device for later.",
    ),
    "unlink-incident": connectedOnly(
      "unlink-incident",
      "/api/commands/unlink-incident",
      "Incident unlink",
      "Unlinking incidents needs a connection to the node. It cannot be held on this device for later.",
    ),
    "link-field-report": connectedOnly(
      "link-field-report",
      "/api/commands/link-field-report",
      "Field Report link",
      "Attaching a Field Report needs a connection to the node. It cannot be held on this device for later.",
    ),
    "unlink-field-report": connectedOnly(
      "unlink-field-report",
      "/api/commands/unlink-field-report",
      "Field Report unlink",
      "Removing a Field Report needs a connection to the node. It cannot be held on this device for later.",
    ),
    "strike-incident-attachment": connectedOnly(
      "strike-incident-attachment",
      "/api/commands/strike-incident-attachment",
      "Attachment strike",
      "Striking an incident attachment needs a connection to the node. It cannot be held on this device for later.",
    ),
    "save-incident-list-preset": connectedOnly(
      "save-incident-list-preset",
      "/api/commands/save-incident-list-preset",
      "Saved incident list preset",
      "Saving a list preset needs a connection to the node. It cannot be held on this device for later.",
    ),
    "delete-incident-list-preset": connectedOnly(
      "delete-incident-list-preset",
      "/api/commands/delete-incident-list-preset",
      "Saved incident list preset",
      "Deleting a list preset needs a connection to the node. It cannot be held on this device for later.",
    ),
    "acknowledge-document": connectedOnly(
      "acknowledge-document",
      "/api/commands/acknowledge-document",
      "Acknowledgment",
      "Acknowledging a policy or procedure needs a connection to the node. It cannot be held on this device for later.",
    ),
    "submit-application": connectedOnly(
      "submit-application",
      "/api/commands/submit-application",
      "Event application",
      "Submitting an event application needs a connection to the node. It cannot be held on this device for later.",
    ),
    "publish-event-map": connectedOnly(
      "publish-event-map",
      "/api/commands/publish-event-map",
      "Map publish",
      "Editing a map needs a connection to the node. It cannot be held on this device for later.",
    ),
    "archive-event-map": connectedOnly(
      "archive-event-map",
      "/api/commands/archive-event-map",
      "Map archive",
      "Editing a map needs a connection to the node. It cannot be held on this device for later.",
    ),
    "override-locked-map-data": connectedOnly(
      "override-locked-map-data",
      "/api/commands/override-locked-map-data",
      "Map override",
      "Editing a map needs a connection to the node. It cannot be held on this device for later.",
    ),
    "designate-placement-department": connectedOnly(
      "designate-placement-department",
      "/api/commands/designate-placement-department",
      "Placement department",
      "Editing a map needs a connection to the node. It cannot be held on this device for later.",
    ),
  });

/** Every registered command, in catalog order. */
export const COMMAND_CATALOG: readonly CommandDescriptor[] =
  Object.freeze(Object.values(CATALOG));

export function isCommandType(value: unknown): value is MeridianCommandType {
  return typeof value === "string" && value in CATALOG;
}

/** Resolve a command, or refuse a type this client does not know how to send. */
export function describeCommand(
  commandType: MeridianCommandType,
): CommandDescriptor {
  const descriptor = CATALOG[commandType];

  if (descriptor === undefined) {
    throw new UnknownCommandError(commandType);
  }

  return descriptor;
}
