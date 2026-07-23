import { shallowRef } from "vue";

import {
  FIXTURE_DPW_BIKES_TEAM_ID,
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  fixtureDepartmentById,
  fixtureDepartmentHasOrganizerDepartmentAccess,
  selectedFixtureDepartment,
  type FixtureDepartmentAccess,
} from "@/department-teams/fixtureDepartmentAccess";
import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

export type TrainingDelivery = "in_person" | "online";

export interface ProductTraining {
  readonly id: string;
  readonly departmentId: string;
  readonly teamId: string | null;
  readonly name: string;
  readonly description: string | null;
  readonly expiresAfterDays: number | null;
  readonly delivery: TrainingDelivery;
  readonly onlineUrl: string | null;
  readonly scheduledStartAt: string | null;
  readonly scheduledEndAt: string | null;
  readonly location: string | null;
  readonly capacity: number | null;
  readonly timeCommitment: string | null;
  readonly afterTraining: string | null;
  readonly provisions: string | null;
  readonly prerequisiteIds: readonly string[];
  readonly archivedAt: string | null;
}

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

export interface TrainingSignupRecord {
  readonly trainingId: string;
  readonly staffId: string;
  readonly signedUpAt: string;
  readonly cancelledAt: string | null;
}

export interface TrainingCompletionRecord {
  readonly id: string;
  readonly trainingId: string;
  readonly staffId: string;
  readonly completedAt: string;
  readonly expiresAt: string | null;
  readonly recordedByLabel: string;
}

export interface TrainingStaffMember {
  readonly staffId: string;
  readonly displayName: string;
  readonly email: string;
  readonly teamIds: readonly string[];
}

export interface TrainingSession {
  readonly organizationId: string;
  readonly organizationLabel: string;
  readonly eventId: string;
  readonly eventLabel: string;
  readonly department: FixtureDepartmentAccess;
  readonly viewerStaffId: string;
}

export interface TrainingImportRowResult {
  readonly line: number;
  readonly email: string;
  readonly status: "imported" | "skipped";
  readonly reason: string | null;
}

export interface TrainingImportResult {
  readonly imported: number;
  readonly skipped: number;
  readonly rows: readonly TrainingImportRowResult[];
}

const organizationId = "11111111-1111-4111-8111-111111111111";
const organizationLabel = "Signal Camp (development)";

const TRAINING_RANGER_ORIENTATION_ID = "88888888-8888-4888-8888-888888888801";
const TRAINING_RADIO_CERTIFICATION_ID = "88888888-8888-4888-8888-888888888802";
const TRAINING_GATE_BRIEFING_ID = "88888888-8888-4888-8888-888888888803";
const TRAINING_BIKE_REPAIR_ID = "88888888-8888-4888-8888-888888888804";
const TRAINING_RADIO_THEORY_ONLINE_ID = "88888888-8888-4888-8888-888888888805";

const INITIAL_TRAININGS: ProductTraining[] = [
  {
    id: TRAINING_RANGER_ORIENTATION_ID,
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    teamId: null,
    name: "Ranger Orientation",
    description: "Required before field work.",
    expiresAfterDays: null,
    delivery: "in_person",
    onlineUrl: null,
    scheduledStartAt: null,
    scheduledEndAt: null,
    location: null,
    capacity: null,
    timeCommitment: "Half a day, once.",
    afterTraining:
      "Completing orientation makes you eligible for the Radio Certification session and department shift signup.",
    provisions: null,
    prerequisiteIds: [],
    archivedAt: null,
  },
  {
    id: TRAINING_RADIO_CERTIFICATION_ID,
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    teamId: null,
    name: "Radio Certification",
    description: "Annual radio protocol certification with a scheduled session.",
    expiresAfterDays: 365,
    delivery: "in_person",
    onlineUrl: null,
    scheduledStartAt: "2026-07-30T17:00:00.000Z",
    scheduledEndAt: "2026-07-30T20:00:00.000Z",
    location: "HQ Tent",
    capacity: 2,
    timeCommitment: "One three-hour session, renewed annually.",
    afterTraining:
      "Certified staff unlock radio-equipped patrol shifts and receive a radio checkout card.",
    provisions: "A department radio and holster are issued at checkout.",
    prerequisiteIds: [TRAINING_RANGER_ORIENTATION_ID],
    archivedAt: null,
  },
  {
    id: TRAINING_RADIO_THEORY_ONLINE_ID,
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    teamId: null,
    name: "Radio Theory Online",
    description: "Self-paced online radio theory refresher.",
    expiresAfterDays: null,
    delivery: "online",
    onlineUrl: "https://training.signalcamp.dev/radio-theory",
    scheduledStartAt: null,
    scheduledEndAt: null,
    location: null,
    capacity: null,
    timeCommitment: "About 45 minutes, self paced.",
    afterTraining:
      "No signup needed. A trainer records your completion after the follow-up quiz.",
    provisions: null,
    prerequisiteIds: [],
    archivedAt: null,
  },
  {
    id: TRAINING_GATE_BRIEFING_ID,
    departmentId: FIXTURE_GATE_DEPARTMENT_ID,
    teamId: null,
    name: "Gate Shift Briefing",
    description: "Scheduled arrival-lane walkthrough before gate shifts.",
    expiresAfterDays: null,
    delivery: "in_person",
    onlineUrl: null,
    scheduledStartAt: "2026-07-28T16:00:00.000Z",
    scheduledEndAt: "2026-07-28T17:00:00.000Z",
    location: "Gate House",
    capacity: 10,
    timeCommitment: "One hour before your first gate shift.",
    afterTraining: "Unlocks arrival-lane gate shifts for the event.",
    provisions: "High-visibility vest provided at the Gate House.",
    prerequisiteIds: [],
    archivedAt: null,
  },
  {
    id: TRAINING_BIKE_REPAIR_ID,
    departmentId: FIXTURE_DPW_DEPARTMENT_ID,
    teamId: FIXTURE_DPW_BIKES_TEAM_ID,
    name: "Bike Repair Basics",
    description: "Team qualification for field bike repair.",
    expiresAfterDays: 730,
    delivery: "in_person",
    onlineUrl: null,
    scheduledStartAt: null,
    scheduledEndAt: null,
    location: null,
    capacity: null,
    timeCommitment: "Two evenings with the Bikes team.",
    afterTraining: "Keeps you on the Bikes team roster for repair shifts.",
    provisions: "A field toolkit is checked out to you for the event.",
    prerequisiteIds: [],
    archivedAt: null,
  },
];

/**
 * Fixture shift-eligibility links so the training webpage can say which
 * shifts a completed training unlocks (mirrors `shift_training_requirements`).
 */
const FIXTURE_SHIFTS_REQUIRING_TRAINING: readonly {
  readonly trainingId: string;
  readonly shiftTitle: string;
  readonly startsAt: string;
}[] = [
  {
    trainingId: TRAINING_RADIO_CERTIFICATION_ID,
    shiftTitle: "Dirt patrol (Friday night)",
    startsAt: "2026-08-01T02:00:00.000Z",
  },
  {
    trainingId: TRAINING_RADIO_CERTIFICATION_ID,
    shiftTitle: "Dirt patrol (Saturday day)",
    startsAt: "2026-08-01T17:00:00.000Z",
  },
  {
    trainingId: TRAINING_GATE_BRIEFING_ID,
    shiftTitle: "Arrival lane (Wednesday)",
    startsAt: "2026-07-29T15:00:00.000Z",
  },
];

const RANGERS_VERA_STAFF_ID = "33333333-3333-4333-8333-333333333334";
const RANGERS_SAM_STAFF_ID = "33333333-3333-4333-8333-333333333335";

const INITIAL_SIGNUPS: TrainingSignupRecord[] = [
  {
    trainingId: TRAINING_RADIO_CERTIFICATION_ID,
    staffId: RANGERS_SAM_STAFF_ID,
    signedUpAt: "2026-07-20T18:00:00.000Z",
    cancelledAt: null,
  },
];

const INITIAL_COMPLETIONS: TrainingCompletionRecord[] = [
  {
    id: "99999999-9999-4999-8999-999999999901",
    trainingId: TRAINING_RANGER_ORIENTATION_ID,
    staffId: RANGERS_VERA_STAFF_ID,
    completedAt: "2026-06-15T00:00:00.000Z",
    expiresAt: null,
    recordedByLabel: "Local Field Author",
  },
  {
    id: "99999999-9999-4999-8999-999999999902",
    trainingId: TRAINING_RANGER_ORIENTATION_ID,
    staffId: RANGERS_SAM_STAFF_ID,
    completedAt: "2026-06-15T00:00:00.000Z",
    expiresAt: null,
    recordedByLabel: "Local Field Author",
  },
];

const trainings = shallowRef<ProductTraining[]>([...INITIAL_TRAININGS]);
const signups = shallowRef<TrainingSignupRecord[]>([...INITIAL_SIGNUPS]);
const completions = shallowRef<TrainingCompletionRecord[]>([
  ...INITIAL_COMPLETIONS,
]);

export function resolveTrainingSession(
  departmentId?: string | null,
): TrainingSession {
  const department =
    fixtureDepartmentById(departmentId) ?? selectedFixtureDepartment.value;

  return {
    organizationId,
    organizationLabel,
    eventId: department.eventId,
    eventLabel: department.eventLabel,
    department,
    viewerStaffId: LOCAL_FIELD_FIXTURE.staffId,
  };
}

export function canAccessTrainings(session: TrainingSession): boolean {
  return (
    canManageTrainings(session) ||
    session.department.teams.some((team) => team.isMember || team.isTeamLead)
  );
}

/**
 * Department leads and organizers create and maintain trainings (M11.16).
 */
export function canManageTrainings(session: TrainingSession): boolean {
  return (
    session.department.isDepartmentLead ||
    fixtureDepartmentHasOrganizerDepartmentAccess(session.department)
  );
}

/**
 * Authorized trainers/leads: managers plus team leads for their team's
 * trainings (TRAIN-005).
 */
export function canRecordCompletions(
  session: TrainingSession,
  training: ProductTraining,
): boolean {
  if (canManageTrainings(session)) {
    return true;
  }

  if (training.teamId === null) {
    return false;
  }

  return session.department.teams.some(
    (team) => team.teamId === training.teamId && team.isTeamLead,
  );
}

export function departmentStaff(
  session: TrainingSession,
): TrainingStaffMember[] {
  const byStaffId = new Map<string, { displayName: string; handle: string | null; teamIds: string[] }>();

  for (const team of session.department.teams) {
    for (const member of team.staff) {
      const existing = byStaffId.get(member.staffId);
      if (existing) {
        existing.teamIds.push(team.teamId);
      } else {
        byStaffId.set(member.staffId, {
          displayName: member.displayName,
          handle: member.handle,
          teamIds: [team.teamId],
        });
      }
    }
  }

  return [...byStaffId.entries()]
    .map(([staffId, member]) => ({
      staffId,
      displayName: member.displayName,
      email: `${member.handle ?? staffId}@signalcamp.dev`,
      teamIds: member.teamIds,
    }))
    .sort((left, right) => left.displayName.localeCompare(right.displayName));
}

export function visibleTrainings(session: TrainingSession): ProductTraining[] {
  return trainings.value
    .filter((training) => training.departmentId === session.department.departmentId)
    .filter(
      (training) => canManageTrainings(session) || training.archivedAt === null,
    )
    .slice()
    .sort((left, right) => left.name.localeCompare(right.name));
}

export function getTraining(
  session: TrainingSession,
  id: string,
): ProductTraining | null {
  return (
    visibleTrainings(session).find((training) => training.id === id) ?? null
  );
}

export function prerequisiteOptions(
  session: TrainingSession,
  trainingId: string | null,
): ProductTraining[] {
  return visibleTrainings(session).filter(
    (candidate) =>
      candidate.id !== trainingId &&
      candidate.archivedAt === null &&
      (trainingId === null || !dependsOn(candidate, trainingId)),
  );
}

export function saveTraining(
  session: TrainingSession,
  trainingId: string | null,
  draft: TrainingDraft,
): ProductTraining {
  assertCanManage(session);

  const name = draft.name.trim();
  if (name === "") {
    throw new Error("Training name is required.");
  }

  if (
    draft.teamId !== null &&
    !session.department.teams.some((team) => team.teamId === draft.teamId)
  ) {
    throw new Error("Team must belong to the department.");
  }

  const onlineUrl = draft.onlineUrl.trim();

  if (draft.delivery === "online") {
    if (onlineUrl === "") {
      throw new Error("Online trainings need a training URL.");
    }

    if (!onlineUrl.startsWith("https://") && !onlineUrl.startsWith("http://")) {
      throw new Error("The training URL must start with http:// or https://.");
    }

    if (draft.capacity !== null) {
      throw new Error(
        "Online trainings do not take signups, so capacity does not apply.",
      );
    }
  } else if (onlineUrl !== "") {
    throw new Error("Only online trainings carry a training URL.");
  }

  if (draft.scheduledStartAt === null && draft.scheduledEndAt !== null) {
    throw new Error("A scheduled end requires a scheduled start.");
  }

  if (
    draft.scheduledStartAt !== null &&
    draft.scheduledEndAt !== null &&
    Date.parse(draft.scheduledEndAt) <= Date.parse(draft.scheduledStartAt)
  ) {
    throw new Error("The scheduled end must be after the scheduled start.");
  }

  if (draft.capacity !== null && draft.capacity < 1) {
    throw new Error("Capacity must be at least 1 when set.");
  }

  if (draft.capacity !== null && draft.scheduledStartAt === null) {
    throw new Error("Capacity applies only to scheduled trainings.");
  }

  if (draft.expiresAfterDays !== null && draft.expiresAfterDays < 1) {
    throw new Error("Expiration must be at least 1 day when set.");
  }

  const id = trainingId ?? crypto.randomUUID();

  for (const prerequisiteId of draft.prerequisiteIds) {
    if (prerequisiteId === id) {
      throw new Error("A training cannot be its own prerequisite.");
    }

    const prerequisite = trainings.value.find(
      (candidate) => candidate.id === prerequisiteId,
    );
    if (!prerequisite) {
      throw new Error("Prerequisite training not found.");
    }

    if (trainingId !== null && dependsOn(prerequisite, trainingId)) {
      throw new Error(
        `Prerequisite "${prerequisite.name}" would create a prerequisite cycle.`,
      );
    }
  }

  const existing =
    trainingId === null
      ? null
      : (trainings.value.find((training) => training.id === trainingId) ?? null);

  if (trainingId !== null && existing === null) {
    throw new Error("Training not found.");
  }

  const saved: ProductTraining = {
    id,
    departmentId: session.department.departmentId,
    teamId: draft.teamId,
    name,
    description: draft.description.trim() === "" ? null : draft.description.trim(),
    expiresAfterDays: draft.expiresAfterDays,
    delivery: draft.delivery,
    onlineUrl: draft.delivery === "online" ? onlineUrl : null,
    scheduledStartAt: draft.scheduledStartAt,
    scheduledEndAt: draft.scheduledEndAt,
    location: draft.location.trim() === "" ? null : draft.location.trim(),
    capacity: draft.capacity,
    timeCommitment:
      draft.timeCommitment.trim() === "" ? null : draft.timeCommitment.trim(),
    afterTraining:
      draft.afterTraining.trim() === "" ? null : draft.afterTraining.trim(),
    provisions: draft.provisions.trim() === "" ? null : draft.provisions.trim(),
    prerequisiteIds: [...draft.prerequisiteIds],
    archivedAt: existing?.archivedAt ?? null,
  };

  trainings.value =
    existing === null
      ? [...trainings.value, saved]
      : trainings.value.map((training) =>
          training.id === saved.id ? saved : training,
        );

  return saved;
}

export function archiveTraining(
  session: TrainingSession,
  trainingId: string,
): ProductTraining {
  return setArchived(session, trainingId, new Date().toISOString());
}

export function restoreTraining(
  session: TrainingSession,
  trainingId: string,
): ProductTraining {
  return setArchived(session, trainingId, null);
}

export function activeSignupsFor(training: ProductTraining): TrainingSignupRecord[] {
  return signups.value.filter(
    (signup) => signup.trainingId === training.id && signup.cancelledAt === null,
  );
}

export function completionsFor(
  training: ProductTraining,
): TrainingCompletionRecord[] {
  return completions.value
    .filter((completion) => completion.trainingId === training.id)
    .slice()
    .sort((left, right) => right.completedAt.localeCompare(left.completedAt));
}

export function hasCurrentCompletion(
  trainingId: string,
  staffId: string,
  moment: Date = new Date(),
): boolean {
  return completions.value.some(
    (completion) =>
      completion.trainingId === trainingId &&
      completion.staffId === staffId &&
      (completion.expiresAt === null ||
        Date.parse(completion.expiresAt) > moment.getTime()),
  );
}

export function viewerStatus(
  session: TrainingSession,
  training: ProductTraining,
): { signedUp: boolean; completed: boolean } {
  return {
    signedUp: activeSignupsFor(training).some(
      (signup) => signup.staffId === session.viewerStaffId,
    ),
    completed: hasCurrentCompletion(training.id, session.viewerStaffId),
  };
}

export function signUpForTraining(
  session: TrainingSession,
  trainingId: string,
  staffId?: string,
): TrainingSignupRecord {
  const training = requireTraining(trainingId);
  const targetStaffId = staffId ?? session.viewerStaffId;

  if (staffId !== undefined && !canRecordCompletions(session, training)) {
    throw new Error(
      "Only authorized trainers/leads may manage the roster for other staff.",
    );
  }

  if (training.archivedAt !== null) {
    throw new Error("Archived trainings do not take signups.");
  }

  if (training.delivery === "online") {
    throw new Error(
      "Online trainings do not require signup. Visit the training page instead.",
    );
  }

  if (training.scheduledStartAt === null) {
    throw new Error("This training has no scheduled session to sign up for.");
  }

  assertStaffEligible(session, training, targetStaffId);

  const missing = training.prerequisiteIds.filter(
    (prerequisiteId) => !hasCurrentCompletion(prerequisiteId, targetStaffId),
  );
  if (missing.length > 0) {
    const names = missing
      .map(
        (prerequisiteId) =>
          trainings.value.find((candidate) => candidate.id === prerequisiteId)
            ?.name ?? "Unknown training",
      )
      .join(", ");
    throw new Error(`Prerequisite training incomplete: ${names}.`);
  }

  if (
    activeSignupsFor(training).some((signup) => signup.staffId === targetStaffId)
  ) {
    throw new Error("Already signed up for this training.");
  }

  if (
    training.capacity !== null &&
    activeSignupsFor(training).length >= training.capacity
  ) {
    throw new Error("This training session is full.");
  }

  const signup: TrainingSignupRecord = {
    trainingId,
    staffId: targetStaffId,
    signedUpAt: new Date().toISOString(),
    cancelledAt: null,
  };

  signups.value = [
    ...signups.value.filter(
      (existing) =>
        !(existing.trainingId === trainingId && existing.staffId === targetStaffId),
    ),
    signup,
  ];

  return signup;
}

export function cancelTrainingSignup(
  session: TrainingSession,
  trainingId: string,
  staffId?: string,
): void {
  const training = requireTraining(trainingId);
  const targetStaffId = staffId ?? session.viewerStaffId;

  if (staffId !== undefined && !canRecordCompletions(session, training)) {
    throw new Error(
      "Only authorized trainers/leads may manage the roster for other staff.",
    );
  }

  const active = activeSignupsFor(training).find(
    (signup) => signup.staffId === targetStaffId,
  );
  if (!active) {
    throw new Error("Not signed up for this training.");
  }

  signups.value = signups.value.map((signup) =>
    signup.trainingId === trainingId && signup.staffId === targetStaffId
      ? { ...signup, cancelledAt: new Date().toISOString() }
      : signup,
  );
}

export function recordTrainingCompletion(
  session: TrainingSession,
  trainingId: string,
  staffId: string,
  completedAt?: string,
): TrainingCompletionRecord {
  const training = requireTraining(trainingId);

  if (!canRecordCompletions(session, training)) {
    throw new Error(
      "Only authorized trainers/leads may record training completion.",
    );
  }

  if (training.archivedAt !== null) {
    throw new Error("Archived trainings cannot receive completions.");
  }

  const completedDate =
    completedAt !== undefined && completedAt !== ""
      ? new Date(completedAt)
      : new Date();

  if (Number.isNaN(completedDate.getTime())) {
    throw new Error("The completion date is unreadable.");
  }

  const expiresAt =
    training.expiresAfterDays !== null
      ? new Date(
          completedDate.getTime() +
            training.expiresAfterDays * 24 * 60 * 60 * 1000,
        ).toISOString()
      : null;

  const completion: TrainingCompletionRecord = {
    id: crypto.randomUUID(),
    trainingId,
    staffId,
    completedAt: completedDate.toISOString(),
    expiresAt,
    recordedByLabel: "Local Field Author",
  };

  completions.value = [...completions.value, completion];

  return completion;
}

/**
 * Spreadsheet completion import entry point (TRAIN-006). Expects an `email`
 * header column with an optional `completed_at` column; other columns are
 * ignored and each row is processed independently.
 */
export function importTrainingCompletionsCsv(
  session: TrainingSession,
  trainingId: string,
  csvText: string,
): TrainingImportResult {
  const training = requireTraining(trainingId);

  if (!canManageTrainings(session)) {
    throw new Error("Only training managers may import completions.");
  }

  if (training.archivedAt !== null) {
    throw new Error("Archived trainings cannot receive completions.");
  }

  const lines = csvText
    .split(/\r\n|\r|\n/)
    .map((line) => line.trim());

  if (lines.length === 0 || lines[0] === "") {
    throw new Error("The CSV is empty.");
  }

  const header = lines[0]!.split(",").map((column) => column.trim().toLowerCase());
  const emailIndex = header.indexOf("email");
  if (emailIndex === -1) {
    throw new Error('The CSV must include an "email" header column.');
  }

  const completedAtIndex = header.indexOf("completed_at");
  const staff = departmentStaff(session);
  const rows: TrainingImportRowResult[] = [];
  let imported = 0;

  for (let index = 1; index < lines.length; index++) {
    const line = lines[index]!;
    if (line === "") {
      continue;
    }

    const lineNumber = index + 1;
    const columns = line.split(",").map((column) => column.trim());
    const email = (columns[emailIndex] ?? "").toLowerCase();

    if (email === "") {
      rows.push({ line: lineNumber, email, status: "skipped", reason: "Missing email." });
      continue;
    }

    const member = staff.find(
      (candidate) => candidate.email.toLowerCase() === email,
    );
    if (!member) {
      rows.push({
        line: lineNumber,
        email,
        status: "skipped",
        reason: "No staff record with this email.",
      });
      continue;
    }

    const completedAtRaw =
      completedAtIndex === -1 ? "" : (columns[completedAtIndex] ?? "");
    if (completedAtRaw !== "" && Number.isNaN(Date.parse(completedAtRaw))) {
      rows.push({
        line: lineNumber,
        email,
        status: "skipped",
        reason: "Unreadable completed_at date.",
      });
      continue;
    }

    recordTrainingCompletion(
      session,
      trainingId,
      member.staffId,
      completedAtRaw === "" ? undefined : completedAtRaw,
    );
    imported++;
    rows.push({ line: lineNumber, email, status: "imported", reason: null });
  }

  return { imported, skipped: rows.length - imported, rows };
}

export function deliveryLabel(training: ProductTraining): string {
  return training.delivery === "online" ? "Online" : "In person";
}

/**
 * Whether the training takes signups at all: in-person with a scheduled
 * session. Online trainings are visited, not signed up for.
 */
export function takesSignups(training: ProductTraining): boolean {
  return training.delivery === "in_person" && training.scheduledStartAt !== null;
}

/**
 * Shifts a completed training unlocks (fixture mirror of the server's
 * shift training requirement derivation).
 */
export function unlockedShiftsFor(
  training: ProductTraining,
): { shiftTitle: string; startsAt: string }[] {
  return FIXTURE_SHIFTS_REQUIRING_TRAINING.filter(
    (link) => link.trainingId === training.id,
  ).map((link) => ({ shiftTitle: link.shiftTitle, startsAt: link.startsAt }));
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
  return training.prerequisiteIds.map(
    (prerequisiteId) =>
      trainings.value.find((candidate) => candidate.id === prerequisiteId)
        ?.name ?? "Unknown training",
  );
}

export function staffDisplayName(
  session: TrainingSession,
  staffId: string,
): string {
  return (
    departmentStaff(session).find((member) => member.staffId === staffId)
      ?.displayName ?? staffId
  );
}

export function resetTrainingFixtures(): void {
  trainings.value = INITIAL_TRAININGS.map((training) => ({ ...training }));
  signups.value = INITIAL_SIGNUPS.map((signup) => ({ ...signup }));
  completions.value = INITIAL_COMPLETIONS.map((completion) => ({
    ...completion,
  }));
}

function dependsOn(training: ProductTraining, targetId: string): boolean {
  const visited = new Set<string>();
  let frontier = [...training.prerequisiteIds];

  while (frontier.length > 0) {
    if (frontier.includes(targetId)) {
      return true;
    }

    for (const id of frontier) {
      visited.add(id);
    }

    frontier = frontier
      .flatMap(
        (id) =>
          trainings.value.find((candidate) => candidate.id === id)
            ?.prerequisiteIds ?? [],
      )
      .filter((id) => !visited.has(id));
  }

  return false;
}

function requireTraining(trainingId: string): ProductTraining {
  const training = trainings.value.find(
    (candidate) => candidate.id === trainingId,
  );

  if (!training) {
    throw new Error("Training not found.");
  }

  return training;
}

function setArchived(
  session: TrainingSession,
  trainingId: string,
  archivedAt: string | null,
): ProductTraining {
  assertCanManage(session);
  const training = requireTraining(trainingId);

  const updated: ProductTraining = { ...training, archivedAt };
  trainings.value = trainings.value.map((candidate) =>
    candidate.id === trainingId ? updated : candidate,
  );

  return updated;
}

function assertCanManage(session: TrainingSession): void {
  if (!canManageTrainings(session)) {
    throw new Error(
      "Only department leads and organizers may manage trainings.",
    );
  }
}

function assertStaffEligible(
  session: TrainingSession,
  training: ProductTraining,
  staffId: string,
): void {
  const member = departmentStaff(session).find(
    (candidate) => candidate.staffId === staffId,
  );

  if (!member) {
    throw new Error("Staff must belong to the department to sign up.");
  }

  if (training.teamId !== null && !member.teamIds.includes(training.teamId)) {
    throw new Error("Staff must belong to the training team to sign up.");
  }
}

export const FIXTURE_TRAINING_IDS = {
  rangerOrientation: TRAINING_RANGER_ORIENTATION_ID,
  radioCertification: TRAINING_RADIO_CERTIFICATION_ID,
  radioTheoryOnline: TRAINING_RADIO_THEORY_ONLINE_ID,
  gateBriefing: TRAINING_GATE_BRIEFING_ID,
  bikeRepair: TRAINING_BIKE_REPAIR_ID,
} as const;
