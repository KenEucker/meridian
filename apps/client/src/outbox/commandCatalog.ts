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
// The connected-only entries below start with the four families technical spec
// 11A.5 names by hand and now include the rest of the department operations
// desk (M16.21). Each carries the reason the refusal states, so a person who
// issues one where it cannot be sent is told what happened in words about their
// work rather than a generic failure. The first of them were registered before
// the surfaces that issue them existed, so the rule was under test before there
// was a screen that could break it; the IMS surfaces bound in M16.20 and the
// Logistics Window bound in M16.21 issue theirs through `sendConnectedCommand`
// and are refused from here.

/** Every command this client can submit today. */
export type MeridianCommandType =
  // Alpha 1 offline writes (data/API 7.2).
  | "submit-field-report"
  | "check-in-staff"
  | "check-out-staff"
  | "mark-no-show"
  // The rest of the department operations desk (M16.21). Attendance is the only
  // part of it data/API 7.2 lists as an offline write.
  | "mark-staff-on-site"
  | "mark-staff-off-site"
  | "add-staff-to-shift"
  | "correct-hours"
  // Event credential administration (M18.5).
  | "revoke-credential"
  // Staff self-service on their own schedule (M18.2).
  | "sign-up-for-shift"
  | "withdraw-from-shift"
  | "set-current-deployment"
  | "checkout-equipment"
  | "return-equipment"
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
  | "create-incident-type"
  | "rename-incident-type"
  | "archive-incident-type"
  | "restore-incident-type"
  | "acknowledge-document"
  // Acknowledgment requirement administration (M18.6).
  | "create-document-acknowledgment-requirement"
  | "set-document-acknowledgment-requirement-active"
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
    /*
     * The Logistics Window's other writes (M16.21).
     *
     * Attendance is offline-writable and everything around it is not, which
     * looks inconsistent until you read what each one turns on. Check-in,
     * check-out, and no-show are recorded against a shift the device already
     * holds, and data/API 7.2 lists exactly those. Presence is refused on the
     * strength of every shift and every equipment checkout in the department,
     * an unscheduled addition weighs trainings, waivers, and organization
     * status, and equipment handoff turns on whether an item is still where the
     * node last saw it. None of those are questions a device can answer for
     * itself, so each is sent now or refused now rather than queued against an
     * answer that may already be wrong.
     */
    "mark-staff-on-site": connectedOnly(
      "mark-staff-on-site",
      "/api/commands/mark-staff-on-site",
      "On-site",
      "Marking someone on-site needs a connection to the node. It cannot be held on this device for later.",
    ),
    "mark-staff-off-site": connectedOnly(
      "mark-staff-off-site",
      "/api/commands/mark-staff-off-site",
      "Off-site",
      "Marking someone off-site needs a connection to the node. It cannot be held on this device for later.",
    ),
    "add-staff-to-shift": connectedOnly(
      "add-staff-to-shift",
      "/api/commands/add-staff-to-shift",
      "Shift addition",
      "Adding someone to a shift needs a connection to the node. It cannot be held on this device for later.",
    ),
    /*
     * Hours correction (M18.4; SLB-007, SLB-031; HOURS-007, HOURS-008).
     *
     * The one attendance-family command that is not an offline write, and the
     * reason is the grace period. Whether a correction is allowed at all is
     * measured against a window the node's clock owns and freezes on its own
     * (HOURS-008), so one held on a device is one that may be delivered into a
     * closed period and refused hours after the operator walked away from the
     * desk believing the total was fixed. Check-in and check-out queue because
     * a device knows what it saw; nothing on a device knows whether the
     * organization has finished with these hours.
     */
    "correct-hours": connectedOnly(
      "correct-hours",
      "/api/commands/correct-hours",
      "Hours correction",
      "Correcting hours needs a connection to the node. It cannot be held on this device for later.",
    ),
    /*
     * Credential revocation (M18.5; CRED-011 through CRED-013).
     *
     * Connected-only, and of everything in this catalog it is the least
     * queueable. Revoking removes the future shifts somebody was on, which is a
     * roster other people are being scheduled around — a revocation held on a
     * device is an event still planning around somebody who was removed from it
     * hours ago. Nothing about the decision is local either: the node weighs it
     * against the credential and the assignments as they stand when it arrives,
     * not as this device last saw them.
     */
    "revoke-credential": connectedOnly(
      "revoke-credential",
      "/api/commands/revoke-credential",
      "Credential revocation",
      "Revoking a credential needs a connection to the node. It cannot be held on this device for later.",
    ),
    /*
     * The shift board's two writes (M18.2; SHIFT-011, SHIFT-013).
     *
     * Connected-only for the same reason the unscheduled addition above is.
     * Whether a shift will take somebody turns on trainings, waivers, department
     * status, and a capacity that other people are filling while this device is
     * away; a signup queued against yesterday's board is a shift somebody
     * believes they hold and nobody has them down for. Withdrawal is here too,
     * because the cutoff it is measured against is the node's clock, and a
     * withdrawal delivered after the schedule locks is a shift somebody stopped
     * planning to work and is still on the roster for.
     */
    "sign-up-for-shift": connectedOnly(
      "sign-up-for-shift",
      "/api/commands/sign-up-for-shift",
      "Shift signup",
      "Signing up for a shift needs a connection to the node. It cannot be held on this device for later.",
    ),
    "withdraw-from-shift": connectedOnly(
      "withdraw-from-shift",
      "/api/commands/withdraw-from-shift",
      "Shift withdrawal",
      "Withdrawing from a shift needs a connection to the node. It cannot be held on this device for later.",
    ),
    "set-current-deployment": connectedOnly(
      "set-current-deployment",
      "/api/commands/set-current-deployment",
      "Deployment",
      "Moving someone between deployments needs a connection to the node. It cannot be held on this device for later.",
    ),
    "checkout-equipment": connectedOnly(
      "checkout-equipment",
      "/api/commands/checkout-equipment",
      "Equipment handoff",
      "Handing out equipment needs a connection to the node. It cannot be held on this device for later.",
    ),
    "return-equipment": connectedOnly(
      "return-equipment",
      "/api/commands/return-equipment",
      "Equipment return",
      "Taking equipment back needs a connection to the node. It cannot be held on this device for later.",
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
    /*
     * Incident type administration (M18.14A). Organization configuration is
     * central's to hold, and an organizer editing the list is at a desk with a
     * connection rather than in a field with none, so there is nothing here
     * worth holding on a device.
     */
    "create-incident-type": connectedOnly(
      "create-incident-type",
      "/api/commands/create-incident-type",
      "Incident type",
      "Adding an incident type needs a connection to the node. It cannot be held on this device for later.",
    ),
    "rename-incident-type": connectedOnly(
      "rename-incident-type",
      "/api/commands/rename-incident-type",
      "Incident type",
      "Renaming an incident type needs a connection to the node. It cannot be held on this device for later.",
    ),
    "archive-incident-type": connectedOnly(
      "archive-incident-type",
      "/api/commands/archive-incident-type",
      "Incident type",
      "Archiving an incident type needs a connection to the node. It cannot be held on this device for later.",
    ),
    "restore-incident-type": connectedOnly(
      "restore-incident-type",
      "/api/commands/restore-incident-type",
      "Incident type",
      "Restoring an incident type needs a connection to the node. It cannot be held on this device for later.",
    ),
    /*
     * The acknowledgment path's three writes (M18.6; POL-023, POL-043,
     * POL-046, POL-047).
     *
     * Acceptance is connected-only for a reason peculiar to it: the record has
     * to name the version of the document the person actually read (POL-043),
     * and a device holding one for later would be holding an acceptance of
     * whichever version it last cached. It would arrive claiming agreement to
     * text that may have moved on — which is worse than not arriving, because
     * the record would look complete.
     *
     * The two requirement commands are connected-only for the ordinary reason:
     * they are organizer administration, done at a desk, and there is no case
     * where deciding what everybody must read is urgent enough to queue.
     */
    "acknowledge-document": connectedOnly(
      "acknowledge-document",
      "/api/commands/acknowledge-document",
      "Acknowledgment",
      "Acknowledging a policy or procedure needs a connection to the node. It cannot be held on this device for later.",
    ),
    "create-document-acknowledgment-requirement": connectedOnly(
      "create-document-acknowledgment-requirement",
      "/api/commands/create-document-acknowledgment-requirement",
      "Acknowledgment requirement",
      "Requiring a document needs a connection to the node. It cannot be held on this device for later.",
    ),
    "set-document-acknowledgment-requirement-active": connectedOnly(
      "set-document-acknowledgment-requirement-active",
      "/api/commands/set-document-acknowledgment-requirement-active",
      "Acknowledgment requirement",
      "Changing an acknowledgment requirement needs a connection to the node. It cannot be held on this device for later.",
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
