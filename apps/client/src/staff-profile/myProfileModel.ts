// The staff profile surface's data layer (M18.20; VOL-009, VOL-014 through
// VOL-016; data/API 10.4; UI contract 12.3).
//
// One read and one command. `GET /api/me/profile` answers with the staff
// records the caller's login speaks for — usually one — and
// `update-my-profile` writes the VOL-015 fields to one of them, applying
// immediately and without review.
//
// The read is cached like every other read surface (technical spec 9.3), so a
// staff member opens their own profile offline and sees what this device last
// knew. The command is connected-only: data/API 7.2 closes the offline write
// list and a profile edit is not in it, so the edit form is where the outbox
// refusal surfaces rather than a queue.
//
// Which fields are editable arrives with the read (`self_editable_fields`), so
// the form renders the same boundary the server enforces instead of a copy of
// it (CLIENT-006). Legal name, email, and date of birth are shown and not
// written; a submission naming them is refused by the node in words this
// module passes through.

import { meridianCachedJson, meridianJson } from "@/api/meridianApi";
import type { ReadFreshness } from "@/offline/readCache";
import { describeCommand } from "@/outbox/commandCatalog";
import { sendConnectedCommand } from "@/outbox/submitCommand";

/**
 * How this organization decides handle and picture changes (VOL-027).
 *
 * The value is the node's answer, not this client's guess: the surface words
 * its controls from whichever policy the read carried, and the node enforces
 * the same rule when the command arrives (CLIENT-006).
 */
export type ProfileChangePolicy =
  | "organizer_only"
  | "organizer_sets_first"
  | "auto_approved"
  | "staff_sets_first";

/**
 * A handle or picture change and what became of it (VOL-021, VOL-024,
 * VOL-029).
 *
 * The most recent of each kind stays on the surface whatever state it reached,
 * so a rejection and its reason are readable until the staff member clears
 * them.
 */
export interface ProfileChangeRequest {
  readonly id: string;
  readonly kind: "handle" | "profile_picture";
  readonly status: "pending" | "approved" | "rejected" | "withdrawn";
  readonly previousHandle: string | null;
  readonly requestedHandle: string | null;
  /** Applied without review under the organization's policy (VOL-027). */
  readonly selfService: boolean;
  readonly decisionReason: string | null;
  readonly decidedAt: string | null;
  readonly createdAt: string | null;
  /** Short-lived URL for the submitted image, readable only by its submitter and reviewers. */
  readonly submittedPictureUrl: string | null;
}

/** The VOL-009 field set for one of the caller's own staff records. */
export interface MyStaffProfile {
  readonly id: string;
  readonly legalName: string;
  readonly preferredName: string | null;
  readonly handle: string | null;
  readonly formerlyKnownAs: string | null;
  readonly email: string;
  readonly phone: string | null;
  readonly city: string | null;
  readonly state: string | null;
  /** ISO date, or null while the record is incomplete. */
  readonly dateOfBirth: string | null;
  readonly emergencyContactName: string | null;
  readonly emergencyContactPhone: string | null;
  readonly profilePictureUrl: string | null;
  /** The fields the node will accept from `update-my-profile` (VOL-015). */
  readonly selfEditableFields: readonly string[];
  /**
   * Whether this staff member may submit a picture at all: active somewhere
   * (18A.1), and not held back by a policy that reserves the first one for an
   * organizer (VOL-027).
   */
  readonly canSubmitPicture: boolean;
  /**
   * Handle changes left before one needs review (VOL-017, VOL-018, VOL-028).
   * Zero under any policy that reviews every change, so this is never a
   * promise the next save will break.
   */
  readonly remainingSelfServiceHandleChanges: number;
  readonly handleChangePolicy: ProfileChangePolicy;
  readonly profilePictureChangePolicy: ProfileChangePolicy;
  /** The most recent request of each kind, in whatever state (VOL-029). */
  readonly latestHandleRequest: ProfileChangeRequest | null;
  readonly latestPictureRequest: ProfileChangeRequest | null;
}

export interface MyProfileRead {
  readonly profiles: readonly MyStaffProfile[];
  readonly freshness: ReadFreshness;
}

interface ProfilePayload {
  readonly id: string;
  readonly legal_name?: string;
  readonly preferred_name?: string | null;
  readonly handle?: string | null;
  readonly formerly_known_as?: string | null;
  readonly email?: string;
  readonly phone?: string | null;
  readonly city?: string | null;
  readonly state?: string | null;
  readonly date_of_birth?: string | null;
  readonly emergency_contact_name?: string | null;
  readonly emergency_contact_phone?: string | null;
  readonly profile_picture_url?: string | null;
  readonly self_editable_fields?: readonly string[];
  readonly can_submit_picture?: boolean;
  readonly remaining_self_service_handle_changes?: number;
  readonly handle_change_policy?: string;
  readonly profile_picture_change_policy?: string;
  readonly latest_handle_request?: RequestPayload | null;
  readonly latest_picture_request?: RequestPayload | null;
}

interface RequestPayload {
  readonly id: string;
  readonly kind?: string;
  readonly status?: string;
  readonly previous_handle?: string | null;
  readonly requested_handle?: string | null;
  readonly self_service?: boolean;
  readonly decision_reason?: string | null;
  readonly decided_at?: string | null;
  readonly created_at?: string | null;
  readonly submitted_picture_url?: string | null;
}

interface MyProfilePayload {
  readonly profiles?: readonly ProfilePayload[];
}

function toChangeRequest(
  payload: RequestPayload | null | undefined,
): ProfileChangeRequest | null {
  if (payload == null) {
    return null;
  }

  return {
    id: payload.id,
    kind: payload.kind === "handle" ? "handle" : "profile_picture",
    status: (payload.status ?? "pending") as ProfileChangeRequest["status"],
    previousHandle: payload.previous_handle ?? null,
    requestedHandle: payload.requested_handle ?? null,
    selfService: payload.self_service ?? false,
    decisionReason: payload.decision_reason ?? null,
    decidedAt: payload.decided_at ?? null,
    createdAt: payload.created_at ?? null,
    submittedPictureUrl: payload.submitted_picture_url ?? null,
  };
}

function toProfile(payload: ProfilePayload): MyStaffProfile {
  return {
    id: payload.id,
    legalName: payload.legal_name ?? "",
    preferredName: payload.preferred_name ?? null,
    handle: payload.handle ?? null,
    formerlyKnownAs: payload.formerly_known_as ?? null,
    email: payload.email ?? "",
    phone: payload.phone ?? null,
    city: payload.city ?? null,
    state: payload.state ?? null,
    dateOfBirth: payload.date_of_birth ?? null,
    emergencyContactName: payload.emergency_contact_name ?? null,
    emergencyContactPhone: payload.emergency_contact_phone ?? null,
    profilePictureUrl: payload.profile_picture_url ?? null,
    selfEditableFields: payload.self_editable_fields ?? [
      "preferred_name",
      "phone",
      "city",
      "state",
    ],
    canSubmitPicture: payload.can_submit_picture ?? false,
    remainingSelfServiceHandleChanges:
      payload.remaining_self_service_handle_changes ?? 0,
    handleChangePolicy: toPolicy(payload.handle_change_policy),
    profilePictureChangePolicy: toPolicy(payload.profile_picture_change_policy),
    latestHandleRequest: toChangeRequest(payload.latest_handle_request),
    latestPictureRequest: toChangeRequest(payload.latest_picture_request),
  };
}

/**
 * A policy the node named, or the documented default for a node that named
 * none — the same fallback the server applies, so an older node and this
 * client agree about what is in force.
 */
function toPolicy(value: string | undefined): ProfileChangePolicy {
  const known: readonly ProfileChangePolicy[] = [
    "organizer_only",
    "organizer_sets_first",
    "auto_approved",
    "staff_sets_first",
  ];

  return known.find((policy) => policy === value) ?? "organizer_only";
}

export async function getMyProfile(): Promise<MyProfileRead> {
  const read = await meridianCachedJson<MyProfilePayload>("/api/me/profile");

  return {
    freshness: read.freshness,
    profiles: (read.data.profiles ?? []).map(toProfile),
  };
}

/**
 * The VOL-015 edit. Every field is sent, so clearing one is expressible: null
 * clears, and the node treats a blank as null the same way.
 */
export interface MyProfileEditInput {
  readonly staffId: string;
  readonly preferredName: string | null;
  readonly phone: string | null;
  readonly city: string | null;
  readonly state: string | null;
}

export async function updateMyProfile(
  input: MyProfileEditInput,
): Promise<MyStaffProfile | null> {
  const result = (await sendConnectedCommand({
    commandType: "update-my-profile",
    idempotencyKey: commandIdempotencyKey("update-my-profile"),
    payload: {
      staff_id: input.staffId,
      preferred_name: input.preferredName,
      phone: input.phone,
      city: input.city,
      state: input.state,
    },
  })) as { readonly profile?: ProfilePayload | null } | null;

  const profile = result?.profile;

  return profile == null ? null : toProfile(profile);
}

/**
 * Submit a picture for review (VOL-021).
 *
 * Multipart rather than JSON, so this posts directly instead of going through
 * `sendConnectedCommand`. The catalog still decides the endpoint and still
 * decides that this may not be queued — the descriptor is read here for both —
 * so registering a picture submission as an offline write would break this
 * call rather than silently queue an image.
 */
export async function submitProfilePicture(
  staffId: string,
  file: File,
): Promise<ProfileChangeRequest | null> {
  const descriptor = describeCommand("submit-profile-picture");

  if (descriptor.offlineWritable) {
    throw new Error(
      "`submit-profile-picture` is registered as an offline write, which a multipart upload cannot be.",
    );
  }

  const body = new FormData();
  body.append("staff_id", staffId);
  body.append("picture", file);

  const result = (await meridianJson<{ readonly request?: RequestPayload }>(
    descriptor.path,
    { method: "POST", body },
  )) as { readonly request?: RequestPayload } | null;

  return toChangeRequest(result?.request);
}

/** Remove the current picture, immediately and with no review (VOL-023). */
export async function removeProfilePicture(
  staffId: string,
): Promise<MyStaffProfile | null> {
  const result = (await sendConnectedCommand({
    commandType: "remove-profile-picture",
    idempotencyKey: commandIdempotencyKey("remove-profile-picture"),
    payload: { staff_id: staffId },
  })) as { readonly profile?: ProfilePayload | null } | null;

  return result?.profile == null ? null : toProfile(result.profile);
}

/**
 * Ask for a handle (VOL-017).
 *
 * The answer says which happened: an applied change inside the allowance comes
 * back `approved` with `selfService` true, and one beyond it comes back
 * `pending`. The surface reports what the node decided rather than predicting
 * it from the count it was last shown.
 */
export async function requestHandleChange(
  staffId: string,
  handle: string,
): Promise<ProfileChangeRequest | null> {
  const result = (await sendConnectedCommand({
    commandType: "request-handle-change",
    idempotencyKey: commandIdempotencyKey("request-handle-change"),
    payload: { staff_id: staffId, handle },
  })) as { readonly request?: RequestPayload | null } | null;

  return toChangeRequest(result?.request);
}

/** Withdraw one's own pending request of either kind (VOL-024). */
export async function withdrawProfileChangeRequest(
  requestId: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "withdraw-profile-change-request",
    idempotencyKey: commandIdempotencyKey("withdraw-profile-change-request"),
    payload: { request_id: requestId },
  });
}

/**
 * Clear a decided request from your own surface (VOL-029).
 *
 * Withdrawal's counterpart, for a request nobody is deciding any more. The row
 * survives on the node; what this removes is the notice.
 */
export async function dismissProfileChangeRequest(
  requestId: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "dismiss-profile-change-request",
    idempotencyKey: commandIdempotencyKey("dismiss-profile-change-request"),
    payload: { request_id: requestId },
  });
}

/**
 * The name a profile is shown under (VOL-010).
 *
 * Handle first, because that is how people at an event know each other: a
 * radio call, a shift board, and a desk all use the operational handle, and
 * the legal name on the record is frequently one nobody present would
 * recognise. Preferred name is the fallback for somebody with no handle yet,
 * and the legal name the last resort, because every record has one.
 *
 * The same order the node applies in `Staff::displayName()`. Nothing is hidden
 * by this — surfaces showing legal or preferred name alongside keep doing so.
 */
export function profileDisplayName(profile: MyStaffProfile): string {
  return profile.handle ?? profile.preferredName ?? profile.legalName;
}

function commandIdempotencyKey(commandType: string): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `${commandType}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
