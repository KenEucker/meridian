// The training management surfaces' data layer (M16.16; CLIENT-023, TRAIN
// requirements; data/API 10.8).
//
// Until this task the training surfaces read five trainings compiled into the
// client, decided from a fixture role whether the person looking at them could
// manage or record anything, and enforced prerequisites, capacity, expiry, and
// CSV parsing in the browser against that local copy. None of it reached a
// server, so a signup it accepted was not a signup and a prerequisite it
// refused was not the node's refusal.
//
// This module is now a translation of the endpoints in data/API 10.8. Four
// choices in it are deliberate:
//
//  1. **One read per surface.** `GET /api/departments/{id}/trainings` answers
//     with the caller's authority over the surface, the department's teams and
//     roster for the forms, and every training with its prerequisites, linked
//     shift, unlocked shifts, and the viewer's own signup and completion
//     state. The list page renders off that one response rather than several
//     requests that could disagree about which trainings exist.
//  2. **Authority comes from the response.** `access` on the index and `viewer`
//     on each training are the server's own answer to what this caller may do,
//     and it is the same answer the command endpoints enforce. The old
//     client-side role predicates are gone; all they could do was drift from
//     the server (CLIENT-006).
//  3. **No cache and no client-side rules.** Nothing is held between calls and
//     every write is followed by a re-read. Prerequisites, capacity, team
//     eligibility, expiry, and CSV parsing are the server's to decide, and its
//     refusals are shown as it worded them rather than reimplemented here.
//  4. **Prerequisites are reconciled, not replaced.** No endpoint takes a whole
//     prerequisite set, so a save diffs the edited set against the one the save
//     itself just answered with and issues one add or remove per difference.
//     Cycles, self-reference, and cross-department links stay refusals from the
//     node (TRAIN-004).
//
// These are connected-only surfaces. Training administration is not in the
// closed set of offline-writable work (data/API 7.2), so a request made with no
// node reachable fails and says so rather than queueing.

import { meridianJson } from "@/api/meridianApi";

export type TrainingDelivery = "in_person" | "online";

/**
 * A training that must be completed first, with whether the person reading the
 * page has completed it.
 *
 * The page lists what to do before starting, so it has to say which of those
 * are already done (TRAIN-010) — and an incomplete one is what signup is
 * refused on (TRAIN-004).
 */
export interface TrainingPrerequisite {
  readonly id: string;
  readonly name: string;
  readonly viewerCompleted: boolean;
}

/** The shift an event-bound in-person training materializes (TRAIN-009). */
export interface TrainingLinkedShift {
  readonly id: string;
  readonly title: string;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly capacity: number | null;
}

/** A shift that requires this training, so completing it opens the shift up. */
export interface TrainingUnlockedShift {
  readonly id: string;
  readonly title: string;
  readonly startsAt: string | null;
}

export interface TrainingViewerCompletion {
  readonly completedAt: string | null;
  readonly expiresAt: string | null;
}

/**
 * What the caller may do with one training, and where they stand in it.
 *
 * `canRecordCompletions` is per-training rather than per-department: a team
 * lead is an authorized trainer for their own team's trainings without managing
 * the department's (TRAIN-005).
 */
export interface TrainingViewerStatus {
  readonly canManage: boolean;
  readonly canRecordCompletions: boolean;
  readonly isSignedUp: boolean;
  readonly completion: TrainingViewerCompletion | null;
}

export interface ProductTraining {
  readonly id: string;
  readonly organizationId: string;
  readonly departmentId: string | null;
  readonly teamId: string | null;
  readonly teamName: string | null;
  readonly eventId: string | null;
  readonly eventName: string | null;
  readonly name: string;
  readonly description: string | null;
  readonly expiresAfterDays: number | null;
  readonly delivery: TrainingDelivery;
  readonly onlineUrl: string | null;
  /**
   * Whether this training has a session to attend, and therefore takes
   * signups. The server decides it; online trainings are visited, not signed
   * up for.
   */
  readonly requiresScheduledAttendance: boolean;
  readonly scheduledStartAt: string | null;
  readonly scheduledEndAt: string | null;
  readonly location: string | null;
  readonly capacity: number | null;
  readonly timeCommitment: string | null;
  readonly afterTraining: string | null;
  readonly provisions: string | null;
  readonly archivedAt: string | null;
  readonly activeSignupCount: number;
  readonly linkedShift: TrainingLinkedShift | null;
  readonly unlockedShifts: readonly TrainingUnlockedShift[];
  readonly prerequisites: readonly TrainingPrerequisite[];
  readonly viewer: TrainingViewerStatus;
}

/** One person on the roster of a training that takes signups. */
export interface TrainingRosterEntry {
  readonly staffId: string;
  readonly displayName: string | null;
  readonly email: string | null;
  readonly signedUpAt: string | null;
  readonly completed: boolean;
}

export interface TrainingCompletionRecord {
  readonly id: string;
  readonly staffId: string;
  readonly displayName: string | null;
  readonly email: string | null;
  readonly completedAt: string | null;
  readonly expiresAt: string | null;
  readonly expired: boolean;
  readonly recordedBy: string | null;
}

/**
 * One training with its roster and completion history.
 *
 * Both lists arrive only for a caller the node accepts as an authorized trainer
 * or lead, so an ordinary member's read of the same training carries neither.
 */
export interface ProductTrainingDetail extends ProductTraining {
  readonly roster: readonly TrainingRosterEntry[];
  readonly completions: readonly TrainingCompletionRecord[];
}

/** A team offered for the team-scope field, archived ones marked. */
export interface TrainingTeamOption {
  readonly id: string;
  readonly name: string;
  readonly isDefault: boolean;
  readonly archivedAt: string | null;
}

/** A department member offered to the roster and completion fields. */
export interface TrainingStaffOption {
  readonly staffId: string;
  readonly displayName: string | null;
  readonly email: string | null;
}

/**
 * What the caller may do on this surface as a whole, as the node decided it.
 *
 * A department with no trainings yet still has to decide whether the page
 * offers to create one, which no individual training's `viewer` block could
 * answer.
 */
export interface TrainingWorkspaceAccess {
  readonly canManage: boolean;
  readonly canRecordCompletions: boolean;
}

export interface TrainingWorkspace {
  readonly departmentId: string;
  readonly organizationId: string;
  readonly access: TrainingWorkspaceAccess;
  readonly teams: readonly TrainingTeamOption[];
  readonly departmentStaff: readonly TrainingStaffOption[];
  readonly trainings: readonly ProductTraining[];
}

/** The training form, as edited. */
export interface TrainingDraft {
  name: string;
  description: string;
  teamId: string | null;
  expiresAfterDays: number | null;
  delivery: TrainingDelivery;
  onlineUrl: string;
  scheduledStartAt: string | null;
  scheduledEndAt: string | null;
  location: string;
  capacity: number | null;
  timeCommitment: string;
  afterTraining: string;
  provisions: string;
  prerequisiteIds: string[];
}

export interface TrainingImportRowResult {
  readonly line: number;
  readonly email: string;
  readonly status: string;
  readonly reason: string | null;
}

export interface TrainingImportResult {
  readonly imported: number;
  readonly skipped: number;
  readonly rows: readonly TrainingImportRowResult[];
}

interface PrerequisitePayload {
  readonly id: string;
  readonly name: string;
  readonly viewer_completed?: boolean;
}

interface LinkedShiftPayload {
  readonly id: string;
  readonly title: string;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  readonly capacity: number | null;
}

interface UnlockedShiftPayload {
  readonly id: string;
  readonly title: string;
  readonly starts_at: string | null;
}

interface ViewerPayload {
  readonly can_manage?: boolean;
  readonly can_record_completions?: boolean;
  readonly is_signed_up?: boolean;
  readonly completion?: {
    readonly completed_at: string | null;
    readonly expires_at: string | null;
  } | null;
}

interface RosterPayload {
  readonly staff_id: string;
  readonly display_name: string | null;
  readonly email: string | null;
  readonly signed_up_at: string | null;
  readonly completed?: boolean;
}

interface CompletionPayload {
  readonly id: string;
  readonly staff_id: string;
  readonly display_name: string | null;
  readonly email: string | null;
  readonly completed_at: string | null;
  readonly expires_at: string | null;
  readonly expired?: boolean;
  readonly recorded_by: string | null;
}

interface TrainingPayload {
  readonly id: string;
  readonly organization_id: string;
  readonly department_id: string | null;
  readonly team_id: string | null;
  readonly team_name: string | null;
  readonly event_id: string | null;
  readonly event_name: string | null;
  readonly name: string;
  readonly description: string | null;
  readonly expires_after_days: number | null;
  readonly delivery: string;
  readonly online_url: string | null;
  readonly requires_scheduled_attendance?: boolean;
  readonly scheduled_start_at: string | null;
  readonly scheduled_end_at: string | null;
  readonly location: string | null;
  readonly capacity: number | null;
  readonly time_commitment: string | null;
  readonly after_training: string | null;
  readonly provisions: string | null;
  readonly archived_at: string | null;
  readonly active_signup_count?: number;
  readonly linked_shift?: LinkedShiftPayload | null;
  readonly unlocked_shifts?: UnlockedShiftPayload[];
  readonly prerequisites?: PrerequisitePayload[];
  readonly viewer?: ViewerPayload;
  readonly roster?: RosterPayload[];
  readonly completions?: CompletionPayload[];
}

interface TrainingIndexPayload {
  readonly department_id?: string;
  readonly organization_id?: string;
  readonly access?: {
    readonly can_manage?: boolean;
    readonly can_record_completions?: boolean;
  };
  readonly teams?: {
    readonly id: string;
    readonly name: string;
    readonly is_default?: boolean;
    readonly archived_at: string | null;
  }[];
  readonly department_staff?: {
    readonly staff_id: string;
    readonly display_name: string | null;
    readonly email: string | null;
  }[];
  readonly trainings?: TrainingPayload[];
}

function toTraining(payload: TrainingPayload): ProductTraining {
  return {
    id: payload.id,
    organizationId: payload.organization_id,
    departmentId: payload.department_id,
    teamId: payload.team_id,
    teamName: payload.team_name,
    eventId: payload.event_id,
    eventName: payload.event_name,
    name: payload.name,
    description: payload.description,
    expiresAfterDays: payload.expires_after_days,
    delivery: payload.delivery === "online" ? "online" : "in_person",
    onlineUrl: payload.online_url,
    requiresScheduledAttendance: payload.requires_scheduled_attendance ?? false,
    scheduledStartAt: payload.scheduled_start_at,
    scheduledEndAt: payload.scheduled_end_at,
    location: payload.location,
    capacity: payload.capacity,
    timeCommitment: payload.time_commitment,
    afterTraining: payload.after_training,
    provisions: payload.provisions,
    archivedAt: payload.archived_at,
    activeSignupCount: payload.active_signup_count ?? 0,
    linkedShift: payload.linked_shift
      ? {
          id: payload.linked_shift.id,
          title: payload.linked_shift.title,
          startsAt: payload.linked_shift.starts_at,
          endsAt: payload.linked_shift.ends_at,
          capacity: payload.linked_shift.capacity,
        }
      : null,
    unlockedShifts: (payload.unlocked_shifts ?? []).map((shift) => ({
      id: shift.id,
      title: shift.title,
      startsAt: shift.starts_at,
    })),
    prerequisites: (payload.prerequisites ?? []).map((prerequisite) => ({
      id: prerequisite.id,
      name: prerequisite.name,
      viewerCompleted: prerequisite.viewer_completed ?? false,
    })),
    viewer: {
      canManage: payload.viewer?.can_manage ?? false,
      canRecordCompletions: payload.viewer?.can_record_completions ?? false,
      isSignedUp: payload.viewer?.is_signed_up ?? false,
      completion: payload.viewer?.completion
        ? {
            completedAt: payload.viewer.completion.completed_at,
            expiresAt: payload.viewer.completion.expires_at,
          }
        : null,
    },
  };
}

function toTrainingDetail(payload: TrainingPayload): ProductTrainingDetail {
  return {
    ...toTraining(payload),
    roster: (payload.roster ?? []).map((entry) => ({
      staffId: entry.staff_id,
      displayName: entry.display_name,
      email: entry.email,
      signedUpAt: entry.signed_up_at,
      completed: entry.completed ?? false,
    })),
    completions: (payload.completions ?? []).map((completion) => ({
      id: completion.id,
      staffId: completion.staff_id,
      displayName: completion.display_name,
      email: completion.email,
      completedAt: completion.completed_at,
      expiresAt: completion.expires_at,
      expired: completion.expired ?? false,
      recordedBy: completion.recorded_by,
    })),
  };
}

/**
 * A cleared number input reads as an empty string through `v-model.number`,
 * which the server would refuse as a non-integer rather than read as "unset".
 */
function numberOrNull(value: number | null): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

/**
 * The submitted form, trimmed.
 *
 * The server trims too, so this changes nothing it stores. It changes what a
 * whitespace-only entry does: sent as typed it passes `required` and comes back
 * as a domain refusal, which reads oddly next to a field that visibly has
 * something in it.
 */
function toAttributes(draft: TrainingDraft): Record<string, unknown> {
  return {
    name: draft.name.trim(),
    description: emptyToNull(draft.description),
    team_id: draft.teamId,
    expires_after_days: numberOrNull(draft.expiresAfterDays),
    delivery: draft.delivery,
    online_url: emptyToNull(draft.onlineUrl),
    scheduled_start_at: draft.scheduledStartAt,
    scheduled_end_at: draft.scheduledEndAt,
    location: emptyToNull(draft.location),
    capacity: numberOrNull(draft.capacity),
    time_commitment: emptyToNull(draft.timeCommitment),
    after_training: emptyToNull(draft.afterTraining),
    provisions: emptyToNull(draft.provisions),
  };
}

function emptyToNull(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === "" ? null : trimmed;
}

/**
 * Read the whole training surface for one department.
 *
 * Archived trainings arrive for a caller who may manage them and are withheld
 * from everyone else; that is the node's decision, so the surface asks once and
 * shows what it is given.
 */
export async function getDepartmentTrainings(
  departmentId: string,
): Promise<TrainingWorkspace> {
  const result = await meridianJson<TrainingIndexPayload>(
    `/api/departments/${departmentId}/trainings`,
  );

  return {
    departmentId: result.department_id ?? departmentId,
    organizationId: result.organization_id ?? "",
    access: {
      canManage: result.access?.can_manage ?? false,
      canRecordCompletions: result.access?.can_record_completions ?? false,
    },
    teams: (result.teams ?? []).map((team) => ({
      id: team.id,
      name: team.name,
      isDefault: team.is_default ?? false,
      archivedAt: team.archived_at,
    })),
    departmentStaff: (result.department_staff ?? []).map((member) => ({
      staffId: member.staff_id,
      displayName: member.display_name,
      email: member.email,
    })),
    trainings: (result.trainings ?? []).map(toTraining),
  };
}

/**
 * Read one training, with the roster and completion history when the caller may
 * see them.
 */
export async function getTraining(
  departmentId: string,
  trainingId: string,
): Promise<ProductTrainingDetail> {
  return toTrainingDetail(
    await meridianJson<TrainingPayload>(
      `/api/departments/${departmentId}/trainings/${trainingId}`,
    ),
  );
}

/**
 * Create or replace a training, then bring its prerequisites to what the form
 * asked for.
 *
 * The set is diffed against the prerequisites the save itself answered with
 * rather than against whatever the form was opened on, so a prerequisite added
 * elsewhere while the form was open is not silently removed.
 */
export async function saveTraining(
  departmentId: string,
  trainingId: string | null,
  draft: TrainingDraft,
): Promise<ProductTraining> {
  const saved = toTraining(
    trainingId === null
      ? await meridianJson<TrainingPayload>("/api/commands/create-training", {
          method: "POST",
          body: JSON.stringify({
            department_id: departmentId,
            ...toAttributes(draft),
          }),
        })
      : await meridianJson<TrainingPayload>("/api/commands/update-training", {
          method: "POST",
          body: JSON.stringify({
            training_id: trainingId,
            ...toAttributes(draft),
          }),
        }),
  );

  return reconcilePrerequisites(saved, draft.prerequisiteIds);
}

async function reconcilePrerequisites(
  saved: ProductTraining,
  wanted: readonly string[],
): Promise<ProductTraining> {
  const held = saved.prerequisites.map((prerequisite) => prerequisite.id);
  let latest = saved;

  for (const prerequisiteId of wanted) {
    if (!held.includes(prerequisiteId)) {
      latest = toTraining(
        await meridianJson<TrainingPayload>(
          "/api/commands/add-training-prerequisite",
          {
            method: "POST",
            body: JSON.stringify({
              training_id: saved.id,
              prerequisite_training_id: prerequisiteId,
            }),
          },
        ),
      );
    }
  }

  for (const prerequisiteId of held) {
    if (!wanted.includes(prerequisiteId)) {
      latest = toTraining(
        await meridianJson<TrainingPayload>(
          "/api/commands/remove-training-prerequisite",
          {
            method: "POST",
            body: JSON.stringify({
              training_id: saved.id,
              prerequisite_training_id: prerequisiteId,
            }),
          },
        ),
      );
    }
  }

  return latest;
}

export async function archiveTraining(trainingId: string): Promise<void> {
  await meridianJson("/api/commands/archive-training", {
    method: "POST",
    body: JSON.stringify({ training_id: trainingId }),
  });
}

export async function restoreTraining(trainingId: string): Promise<void> {
  await meridianJson("/api/commands/restore-training", {
    method: "POST",
    body: JSON.stringify({ training_id: trainingId }),
  });
}

/**
 * Sign up for a training, or put someone else on its roster.
 *
 * `staffId` left out is the caller signing themselves up; naming a staff member
 * is roster maintenance, which the node allows only to an authorized trainer or
 * lead.
 */
export async function signUpForTraining(
  trainingId: string,
  staffId?: string,
): Promise<void> {
  await meridianJson("/api/commands/sign-up-for-training", {
    method: "POST",
    body: JSON.stringify(
      staffId === undefined
        ? { training_id: trainingId }
        : { training_id: trainingId, staff_id: staffId },
    ),
  });
}

export async function cancelTrainingSignup(
  trainingId: string,
  staffId?: string,
): Promise<void> {
  await meridianJson("/api/commands/cancel-training-signup", {
    method: "POST",
    body: JSON.stringify(
      staffId === undefined
        ? { training_id: trainingId }
        : { training_id: trainingId, staff_id: staffId },
    ),
  });
}

export async function recordTrainingCompletion(
  trainingId: string,
  staffId: string,
  completedAt?: string,
): Promise<void> {
  await meridianJson("/api/commands/record-training-completion", {
    method: "POST",
    body: JSON.stringify(
      completedAt === undefined || completedAt === ""
        ? { training_id: trainingId, staff_id: staffId }
        : {
            training_id: trainingId,
            staff_id: staffId,
            completed_at: completedAt,
          },
    ),
  });
}

/**
 * Hand a completion spreadsheet to the node (TRAIN-006).
 *
 * The CSV is sent as typed. Which column is the email, which row named nobody,
 * and which date was unreadable are the server's findings, and its per-row
 * results are what the screen reports.
 */
export async function importTrainingCompletions(
  trainingId: string,
  csv: string,
): Promise<TrainingImportResult> {
  const result = await meridianJson<{
    imported?: number;
    skipped?: number;
    rows?: TrainingImportRowResult[];
  }>("/api/commands/import-training-completions", {
    method: "POST",
    body: JSON.stringify({ training_id: trainingId, csv }),
  });

  return {
    imported: result.imported ?? 0,
    skipped: result.skipped ?? 0,
    rows: result.rows ?? [],
  };
}

export function deliveryLabel(training: ProductTraining): string {
  return training.delivery === "online" ? "Online" : "In person";
}

export function scheduleLabel(training: ProductTraining): string {
  if (training.delivery === "online") {
    return "Online, self paced";
  }

  if (training.scheduledStartAt === null) {
    return "No scheduled session";
  }

  const start = new Date(training.scheduledStartAt);
  const formatted = start.toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  });

  return training.location !== null
    ? `${formatted} at ${training.location}`
    : formatted;
}

export function expirationLabel(training: ProductTraining): string {
  if (training.expiresAfterDays === null) {
    return "Does not expire";
  }

  if (training.expiresAfterDays === 365) {
    return "Annual (365 days)";
  }

  return `Expires after ${training.expiresAfterDays} days`;
}

export function prerequisiteNames(training: ProductTraining): string[] {
  return training.prerequisites.map((prerequisite) => prerequisite.name);
}
