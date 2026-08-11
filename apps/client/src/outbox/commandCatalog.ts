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
// 11A.5 names by hand and include most of the department operations desk
// (M16.21). Each carries the reason the refusal states, so a person who issues
// one where it cannot be sent is told what happened in words about their work
// rather than a generic failure. The first of them were registered before the
// surfaces that issue them existed, so the rule was under test before there was
// a screen that could break it; the IMS surfaces bound in M16.20 and the
// Logistics Window bound in M16.21 issue theirs through `sendConnectedCommand`
// and are refused from here.
//
// The list of offline writes is closed by 7.2 and has been opened twice, both
// times by an amendment to the specification rather than by a judgement made
// here: data/API 5.8A added the Event Horizon's two preference commands
// (M18.44), and M18.54 added the Logistics unscheduled addition and the on-site
// mark it depends on. That is the only way onto this list. M18.55's override is
// the third and does not widen it: it is the addition's own resolution path
// under a different authority, and a command that resolves an offline write
// that cannot itself be held offline would be a resolution path that only works
// where the refusal it resolves would not have happened.
//
// A descriptor also says how a refusal of its command may be *resolved*
// (M18.55). For all but one the answer is "it cannot be, beyond dismissal",
// which is CLIENT-017's floor; `add-staff-to-shift` carries an override
// descriptor naming the command, the capability, and the short list of refusal
// reasons an authority may set aside.

/** Every command this client can submit today. */
export type MeridianCommandType =
  // Alpha 1 offline writes (data/API 7.2).
  | "submit-field-report"
  | "check-in-staff"
  | "check-out-staff"
  | "mark-no-show"
  // The Logistics addition and the on-site mark it depends on (M18.54).
  | "mark-staff-on-site"
  | "add-staff-to-shift"
  // Resolving a refused addition on an authority's own decision (M18.55).
  | "override-shift-addition"
  // The rest of the department operations desk (M16.21).
  | "mark-staff-off-site"
  | "correct-hours"
  // Event credential administration (M18.5).
  | "revoke-credential"
  // Staff self-service on their own schedule (M18.2).
  | "sign-up-for-shift"
  | "withdraw-from-shift"
  // The Event Horizon's own view state (M18.44; HORIZON-012; data/API 5.8A).
  | "hide-event-horizon"
  | "show-event-horizon"
  // Hiding a page from your own navigation, and keeping one out of your own
  // menus (M18.69).
  | "set-page-visibility"
  | "set-menu-page-visibility"
  // Staff self-service on their own profile (M18.20, M18.20B, M18.20C).
  | "update-my-profile"
  | "request-handle-change"
  | "submit-profile-picture"
  | "remove-profile-picture"
  | "withdraw-profile-change-request"
  | "dismiss-profile-change-request"
  // Profile change request review (M18.20A, M18.20D).
  | "approve-profile-change-request"
  | "reject-profile-change-request"
  // Application review (M18.21A).
  | "approve-application"
  | "reject-application"
  | "defer-application"
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
  // Credit policy administration (M18.16).
  | "create-credit-policy"
  | "update-credit-policy"
  | "archive-credit-policy"
  | "restore-credit-policy"
  | "acknowledge-document"
  // Acknowledgment requirement administration (M18.6).
  | "create-document-acknowledgment-requirement"
  | "set-document-acknowledgment-requirement-active"
  // Waiver administration (M18.18).
  | "create-waiver"
  | "update-waiver"
  | "archive-waiver"
  | "restore-waiver"
  | "record-waiver-completion"
  | "submit-application"
  | "publish-event-map"
  | "archive-event-map"
  | "override-locked-map-data"
  | "designate-placement-department";

/**
 * How a refusal of one command may be resolved by issuing another (M18.55;
 * CLIENT-017A; technical spec 11A.5).
 *
 * A refused command's only outcome used to be dismissal. Where a descriptor
 * carries this, it has a second: a caller holding `capability` may re-issue it
 * as `commandType`, naming the refusal being overridden.
 *
 * **The two lists are the guard rails and both are deliberately narrow.**
 * `overridableReasonCodes` is the refusal reasons that may be overridden at all,
 * and `capability` is who may do it. Neither is inferred: a command with no
 * `override` here has no resolution path beyond dismissal, which is the default
 * and is what every command in this catalog but one still has.
 *
 * **This copy decides what to render, not what happens.** The node holds the
 * same allowlist and the same capability rule and applies them to the request;
 * this one exists so a control is not offered where it can only be refused. The
 * two can only disagree in the direction of a person being offered an override
 * the node then declines, which is a poor experience rather than an authority
 * failure — the reverse, a client that could grant something, is not possible,
 * because nothing here is sent to the node as a claim about authority.
 */
export interface CommandOverrideDescriptor {
  /** The distinct command that carries the override. */
  readonly commandType: MeridianCommandType;
  /** The capability code the session must hold for the control to be offered. */
  readonly capability: string;
  /** The refusal reason codes this command's refusals may be overridden for. */
  readonly overridableReasonCodes: readonly string[];
  /** What the control says, in the words of the work rather than the mechanism. */
  readonly actionLabel: string;
}

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
  /**
   * How a refusal of this command may be resolved besides dismissal (M18.55).
   * `null` for every command that has no such path, which is all but one.
   */
  readonly override: CommandOverrideDescriptor | null;
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
  override: CommandOverrideDescriptor | null = null,
): CommandDescriptor {
  return Object.freeze({
    type,
    path,
    label,
    offlineWritable: true,
    connectedOnlyReason: null,
    override,
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
    override: null,
  });
}

/**
 * The capability an override of a Logistics addition answers to.
 *
 * Held by department leads, department administration, and organizers, and
 * deliberately not by the Logistics role that issues the addition in the first
 * place — a refusal the same desk that hit it may wave away is a confirmation
 * dialog rather than a rule.
 */
export const CAPABILITY_SHIFT_ADDITIONS_OVERRIDE =
  "department.shift_additions.override";

/**
 * Which refusals of a Logistics addition an authority may override.
 *
 * The node's `ShiftAdditionRefusalReason` is authoritative and this is a copy of
 * its overridable subset. Three are on it and the reasoning is the node's to
 * state; what matters here is what is *not*, because a client that offered a
 * control for one of them would be inviting a person to make a decision that is
 * not theirs. `do_not_staff` is off it at every authority — an organization's
 * exclusion decision is not overturned from a desk — and so are
 * `missing_required_waiver`, which nobody can execute on somebody else's behalf,
 * and `no_department_membership`, which is the boundary the overriding authority
 * is itself scoped by and has an ordinary fix.
 */
export const OVERRIDABLE_SHIFT_ADDITION_REASONS: readonly string[] =
  Object.freeze([
    // In the node's own check order, so this list and
    // `ShiftAdditionRefusalReason::overridableCodes()` read the same.
    "staff_not_on_site",
    "missing_required_training",
    "not_eligible_team_member",
  ]);

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
     * The Logistics Window's other writes (M16.21; M18.54).
     *
     * The desk's line between queued and connected-only used to fall between
     * attendance and everything else. M18.54 moved it, and the two commands that
     * moved with it are the unscheduled addition and the on-site mark.
     *
     * **The addition** (SLB-008) is the work an operator loses to paper when the
     * node is unreachable: somebody turns up at two in the morning, is put on a
     * running shift, and the desk cannot record it. What made it look
     * unqueueable is eligibility — Do Not Staff, department membership, team
     * eligibility, required trainings and waivers — and none of that is
     * answerable on a device. But it does not have to be: the node decides it on
     * arrival and refuses in its own words, and a refusal the operator reads is
     * better than work that was never captured. Capacity is not the obstacle it
     * looks like either, because the domain's only capacity guard is
     * `assertCapacityForSelfSignup` and a Logistics addition over capacity is
     * already permitted online.
     *
     * **The on-site mark** is the addition's precondition, and queueing one
     * without the other would have left an offline addition for anybody not
     * already marked on-site refused every time. It qualifies on its own
     * footing: it records what the operator saw, every rule behind it is the
     * node's to check on arrival, and marking somebody on-site twice changes
     * nothing — so a replayed delivery is safe without a key of its own.
     *
     * **Off-site did not move**, and the asymmetry is the point. SLB-018 refuses
     * it on the strength of every checked-in shift and every open equipment
     * checkout in the department, which is a whole the device does not hold; one
     * held here would be an operator told their work was captured and the node
     * refusing it hours later. Equipment handoff stays for the same kind of
     * reason: it turns on whether an item is still where the node last saw it.
     */
    "mark-staff-on-site": offlineWrite(
      "mark-staff-on-site",
      "/api/commands/mark-staff-on-site",
      "On-site",
    ),
    "add-staff-to-shift": offlineWrite(
      "add-staff-to-shift",
      "/api/commands/add-staff-to-shift",
      "Shift addition",
      {
        commandType: "override-shift-addition",
        capability: CAPABILITY_SHIFT_ADDITIONS_OVERRIDE,
        overridableReasonCodes: OVERRIDABLE_SHIFT_ADDITION_REASONS,
        actionLabel: "Add anyway",
      },
    ),
    /*
     * The override (M18.55; CLIENT-017A).
     *
     * An offline write, on the same footing as the addition it resolves. The
     * argument is the same one M18.54 made and it applies with more force here:
     * the refusal is already known, the decision to proceed has already been
     * made by somebody standing at the desk, and the alternative to holding it
     * is that a lead who overrode a refusal at two in the morning finds out at
     * six that the device never sent it. Every rule is still the node's — the
     * allowlist, the capability, and every eligibility check but the one named
     * — and all of them are re-decided when the queue drains, so an override
     * queued by somebody whose grant was withdrawn in between comes back
     * refused rather than applied.
     *
     * It carries its own key like any other queued command. That key is not the
     * refused command's, and the difference is the whole design: two keys, two
     * commands, and a record that says the node refused and a named person then
     * chose to proceed.
     */
    "override-shift-addition": offlineWrite(
      "override-shift-addition",
      "/api/commands/override-shift-addition",
      "Shift addition override",
    ),
    "mark-staff-off-site": connectedOnly(
      "mark-staff-off-site",
      "/api/commands/mark-staff-off-site",
      "Off-site",
      "Marking someone off-site needs a connection to the node. It cannot be held on this device for later.",
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
     * Connected-only, and M18.54 left them that way while moving the Logistics
     * addition. The two look alike and are not: capacity is guarded for
     * self-signup alone (`ShiftEligibilityService::assertCapacityForSelfSignup`),
     * and it is a number other people are filling while this device is away. A
     * signup queued against yesterday's board is somebody believing they hold a
     * shift that filled up overnight — where the Logistics addition is an
     * operator recording, in front of the person, work that is already
     * happening. Withdrawal is here too,
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
    /*
     * The Event Horizon's two preference commands (M18.44; HORIZON-012 through
     * HORIZON-015).
     *
     * Offline writes by the specification's own word: data/API 5.8A says "the
     * two preference commands may be queued offline like any other command" —
     * the one addition to the 7.2 list a later section makes explicitly. They
     * qualify for the same reason attendance does: each records a decision the
     * person has already made about their own state, and the node re-checks
     * the HORIZON-013 guard when the queue drains, so a hide held past a new
     * outstanding item is refused there rather than mishandled here.
     */
    "hide-event-horizon": offlineWrite(
      "hide-event-horizon",
      "/api/commands/hide-event-horizon",
      "Hide Event Horizon",
    ),
    "show-event-horizon": offlineWrite(
      "show-event-horizon",
      "/api/commands/show-event-horizon",
      "Restore Event Horizon",
    ),
    /*
     * Hiding a page from your own navigation (M18.69).
     *
     * Connected-only, unlike the two preference commands above it, and the
     * difference is where the preference lives rather than how important it
     * is. An Event Horizon dismissal is about one event on one screen and
     * data/API 5.8A names it queueable outright. This one is stored on the
     * account so it follows the reader to their next device — and a queued
     * change is a menu that disagrees with itself everywhere else until the
     * queue drains, which is a worse answer than being told to try again with
     * signal. Settings is a connected surface in every mode that offers it.
     */
    "set-page-visibility": connectedOnly(
      "set-page-visibility",
      "/api/commands/set-page-visibility",
      "Page visibility",
      "Changing which pages appear needs a connection to the node, because the setting is stored on your account rather than on this device.",
    ),
    /*
     * Keeping a page out of your own menus (M18.69). Connected-only on exactly
     * the reasoning above, and for the same reason: the two preferences live in
     * the same place and travel the same way, and it is where they are stored
     * rather than how far-reaching they are that decides this.
     */
    "set-menu-page-visibility": connectedOnly(
      "set-menu-page-visibility",
      "/api/commands/set-menu-page-visibility",
      "Menu contents",
      "Changing which pages appear in your menus needs a connection to the node, because the setting is stored on your account rather than on this device.",
    ),
    /*
     * The staff member's own profile (M18.20; VOL-015). Connected-only for the
     * ordinary reason: data/API 7.2 closes the offline write list and a
     * profile edit is not in it. The stakes are low — preferred name, phone,
     * city/state — but a queued edit is still a person told their record says
     * one thing while every roster the node prints says another.
     */
    "update-my-profile": connectedOnly(
      "update-my-profile",
      "/api/commands/update-my-profile",
      "Profile update",
      "Updating your profile needs a connection to the node. It cannot be held on this device for later.",
    ),
    /*
     * The handle and picture paths (M18.20B, M18.20C; VOL-014, VOL-017,
     * VOL-021, VOL-023).
     *
     * Picture submission, replacement, and removal are online-only by
     * requirement rather than by inference — VOL-014 says so outright, and
     * technical spec 18A.3 repeats it — which is the rare case where the
     * catalog's default-deny agrees with a rule written down elsewhere.
     *
     * The handle commands are here for the ordinary reason. Whether a change
     * applies now or waits for review turns on how many the staff record has
     * already spent, which is a count the node holds; a request queued against
     * this device's guess would be a person told their handle changed when it
     * is sitting in somebody's review queue, or the reverse.
     */
    "request-handle-change": connectedOnly(
      "request-handle-change",
      "/api/commands/request-handle-change",
      "Handle change",
      "Changing your handle needs a connection to the node. It cannot be held on this device for later.",
    ),
    "submit-profile-picture": connectedOnly(
      "submit-profile-picture",
      "/api/commands/submit-profile-picture",
      "Profile picture",
      "Submitting a profile picture needs a connection to the node. It cannot be held on this device for later.",
    ),
    "remove-profile-picture": connectedOnly(
      "remove-profile-picture",
      "/api/commands/remove-profile-picture",
      "Profile picture removal",
      "Removing your profile picture needs a connection to the node. It cannot be held on this device for later.",
    ),
    "withdraw-profile-change-request": connectedOnly(
      "withdraw-profile-change-request",
      "/api/commands/withdraw-profile-change-request",
      "Change request withdrawal",
      "Withdrawing a request needs a connection to the node. It cannot be held on this device for later.",
    ),
    "dismiss-profile-change-request": connectedOnly(
      "dismiss-profile-change-request",
      "/api/commands/dismiss-profile-change-request",
      "Change request dismissal",
      "Clearing a decided request needs a connection to the node. It cannot be held on this device for later.",
    ),
    /*
     * The reviewer's two decisions (M18.20D; VOL-019, VOL-022). Desk work with
     * no urgent case, and a decision held on a device is a staff member left
     * waiting on an answer that has already been given.
     */
    "approve-profile-change-request": connectedOnly(
      "approve-profile-change-request",
      "/api/commands/approve-profile-change-request",
      "Change request approval",
      "Approving a request needs a connection to the node. It cannot be held on this device for later.",
    ),
    "reject-profile-change-request": connectedOnly(
      "reject-profile-change-request",
      "/api/commands/reject-profile-change-request",
      "Change request rejection",
      "Rejecting a request needs a connection to the node. It cannot be held on this device for later.",
    ),
    /*
     * Application review (M18.21A; APP-005, APP-019). Connected-only: an
     * approval creates a staff record and an organization status, and holding
     * that on a device would let two reviewers decide the same application
     * offline with no way to reconcile which decision stood.
     */
    "approve-application": connectedOnly(
      "approve-application",
      "/api/commands/approve-application",
      "Application approval",
      "Approving an application needs a connection to the node. It cannot be held on this device for later.",
    ),
    "reject-application": connectedOnly(
      "reject-application",
      "/api/commands/reject-application",
      "Application rejection",
      "Rejecting an application needs a connection to the node. It cannot be held on this device for later.",
    ),
    "defer-application": connectedOnly(
      "defer-application",
      "/api/commands/defer-application",
      "Application deferral",
      "Deferring an application needs a connection to the node. It cannot be held on this device for later.",
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
     * Credit policy administration (M18.16; ORG-009, ORG-020). Governance
     * data central is authoritative for, edited from a desk with a
     * connection; an on-site node refuses these outright (ORG-021), so a
     * device holding one for later would be holding a write nobody may make.
     */
    "create-credit-policy": connectedOnly(
      "create-credit-policy",
      "/api/commands/create-credit-policy",
      "Credit policy",
      "Adding a credit policy needs a connection to the node. It cannot be held on this device for later.",
    ),
    "update-credit-policy": connectedOnly(
      "update-credit-policy",
      "/api/commands/update-credit-policy",
      "Credit policy",
      "Editing a credit policy needs a connection to the node. It cannot be held on this device for later.",
    ),
    "archive-credit-policy": connectedOnly(
      "archive-credit-policy",
      "/api/commands/archive-credit-policy",
      "Credit policy",
      "Archiving a credit policy needs a connection to the node. It cannot be held on this device for later.",
    ),
    "restore-credit-policy": connectedOnly(
      "restore-credit-policy",
      "/api/commands/restore-credit-policy",
      "Credit policy",
      "Restoring a credit policy needs a connection to the node. It cannot be held on this device for later.",
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
    /*
     * Waiver administration (M18.18; WAIVER-001 through WAIVER-006,
     * WAIVER-010).
     *
     * All connected-only, and completion recording most of all: a completion
     * of a document-backed waiver names the document version the person was
     * shown (WAIVER-008), and one held on a device would name whichever
     * version the device last cached — the same reason acceptance above does
     * not queue. The four administration commands are desk work with no urgent
     * case.
     */
    "create-waiver": connectedOnly(
      "create-waiver",
      "/api/commands/create-waiver",
      "Waiver",
      "Creating a waiver needs a connection to the node. It cannot be held on this device for later.",
    ),
    "update-waiver": connectedOnly(
      "update-waiver",
      "/api/commands/update-waiver",
      "Waiver",
      "Changing a waiver needs a connection to the node. It cannot be held on this device for later.",
    ),
    "archive-waiver": connectedOnly(
      "archive-waiver",
      "/api/commands/archive-waiver",
      "Waiver",
      "Archiving a waiver needs a connection to the node. It cannot be held on this device for later.",
    ),
    "restore-waiver": connectedOnly(
      "restore-waiver",
      "/api/commands/restore-waiver",
      "Waiver",
      "Restoring a waiver needs a connection to the node. It cannot be held on this device for later.",
    ),
    "record-waiver-completion": connectedOnly(
      "record-waiver-completion",
      "/api/commands/record-waiver-completion",
      "Waiver completion",
      "Recording a waiver completion needs a connection to the node. It cannot be held on this device for later.",
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
