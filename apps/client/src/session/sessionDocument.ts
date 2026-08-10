// The session document a client reads to learn who its user is and what that
// user may do (M16.4, M16.5; CLIENT-001 through CLIENT-003, CLIENT-007;
// technical spec 11A.2; data/API 5.5).
//
// These types mirror the payload `GET /api/me` returns. They are declared here
// rather than inferred at the call site because the same shape has to survive a
// round trip through durable storage: a document read back from the cache after
// a restart, possibly written by an earlier build of this client, is untrusted
// input in a way a fresh HTTP response is not.
//
// That is what `isSessionDocument` is for. A half-document must not establish
// permissions — a cache entry that lost its `capabilities` array would otherwise
// boot the client as a user with no authority and look like a permission
// problem, and one that lost its `context` would leave staleness unbounded. A
// document that does not validate is discarded, which puts the client in the
// same position as a device that has never been online: it refreshes or it waits.
//
// The document carries codes and associations only. There is no screen list,
// menu structure, or precomputed surface availability in it, and nothing here
// adds one; a client answers "may I render this" from the capabilities it holds
// (technical spec 11A.2, UI implementation contract 19A.1).

export interface SessionUser {
  readonly id: string;
  readonly name: string;
  readonly email: string;
  /** The staff records this login speaks for. */
  readonly staff_ids: readonly string[];
}

/**
 * One effective role, with the scope it resolved at and the capability codes it
 * alone brings.
 *
 * Authority in Meridian is scoped, so the per-role list is not redundant with
 * the flat one: a person may run logistics for one department and be ordinary
 * staff in another, and the client holds no copy of the role-to-capability
 * mapping to work that out for itself.
 */
export interface SessionRole {
  readonly role_code: string;
  readonly role_name: string;
  readonly scope_type: string | null;
  readonly organization_id: string | null;
  readonly department_id: string | null;
  readonly team_id: string | null;
  readonly team_name: string | null;
  readonly event_id: string | null;
  readonly team_grant_id: string | null;
  /** Why the user holds it, shown to elevated users on a denied surface. */
  readonly reason: string | null;
  readonly capabilities: readonly string[];
}

export interface SessionOrganization {
  readonly id: string;
  readonly name: string;
  readonly slug: string | null;
  readonly status: string | null;
  readonly archived_at: string | null;
}

export interface SessionEvent {
  readonly id: string;
  readonly organization_id: string;
  readonly name: string;
  readonly slug: string | null;
  readonly status: string | null;
  readonly timezone: string | null;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  /**
   * The active event window (data/API "Enforcing event authority"). This is the
   * window a cached session's staleness is bounded by (CLIENT-008).
   */
  readonly active_event_window_starts_at: string | null;
  readonly active_event_window_ends_at: string | null;
  readonly is_node_locked: boolean;
}

export interface SessionDepartment {
  readonly id: string;
  readonly organization_id: string;
  readonly name: string;
  readonly code: string | null;
  readonly membership_status: string | null;
  readonly archived_at: string | null;
}

export interface SessionTeam {
  readonly id: string;
  readonly department_id: string;
  readonly organization_id: string | null;
  readonly name: string;
  readonly code: string | null;
  readonly is_default: boolean;
  readonly is_lead: boolean;
  readonly archived_at: string | null;
}

export interface SessionContext {
  readonly organization_id: string | null;
  readonly event_id: string | null;
  readonly department_id: string | null;
  readonly node_locked: boolean;
  readonly node_locked_event_id: string | null;
  readonly switching_available: boolean;
}

/** Why the node does not hold this device as trusted, when it does not. */
export type SessionDeviceTrustState =
  | "trusted"
  | "untrusted"
  | "expired"
  | "revoked";

/**
 * The device this session's credential is bound to, and its trust for this user
 * (AUTH-021, AUTH-024; technical spec 12.2, 14; data/API 12.2).
 *
 * Absent when the credential names no device — a shared-workstation session key
 * is a machine's credential, and the readiness checklist answers for a
 * workstation from the pinned-context read instead.
 */
export interface SessionDevice {
  readonly id: string;
  readonly label: string | null;
  readonly trusted: boolean;
  readonly trust_state: SessionDeviceTrustState;
  /** When the six-week trust window closes, when there is a trust at all. */
  readonly trusted_until: string | null;
}

/**
 * What this user has asked not to be shown (M18.69).
 *
 * Page keys, not routes. The node stores the reader's own answer and hands it
 * back; which routes a key covers is this client's business, and a page named
 * here is still reachable by address — a preference is not a permission
 * (CLIENT-006).
 */
export interface SessionPreferences {
  readonly hidden_pages: readonly string[];
}

export interface SessionDocument {
  readonly user: SessionUser;
  readonly roles: readonly SessionRole[];
  readonly capabilities: readonly string[];
  readonly organizations: readonly SessionOrganization[];
  readonly events: readonly SessionEvent[];
  readonly departments: readonly SessionDepartment[];
  readonly teams: readonly SessionTeam[];
  readonly context: SessionContext;
  /**
   * Optional, and validated no further than its own shape.
   *
   * A document stored by a build from before this field existed is still a
   * usable document, and throwing one away would cost a device its permissions
   * to gain nothing. A client that finds no device block reports the item as
   * unanswered rather than as a device that failed a check.
   */
  readonly device?: SessionDevice | null;
  /**
   * Optional on the same terms as `device`, and for the same reason: a
   * document written by a build from before this field existed is still a
   * usable document. A client that finds none falls back to the defaults it
   * knows, which is the same answer the node would have given a user who has
   * decided nothing.
   */
  readonly preferences?: SessionPreferences | null;
  /** Server time of resolution, shown as the last refresh (CLIENT-009). */
  readonly refreshed_at: string;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isStringArray(value: unknown): value is string[] {
  return Array.isArray(value) && value.every((entry) => typeof entry === "string");
}

/** A field that is either a string or explicitly absent. */
function isNullableString(value: unknown): boolean {
  return value === null || value === undefined || typeof value === "string";
}

function isSessionUser(value: unknown): boolean {
  if (!isRecord(value)) {
    return false;
  }

  return (
    typeof value.id === "string" &&
    typeof value.name === "string" &&
    typeof value.email === "string" &&
    isStringArray(value.staff_ids)
  );
}

/**
 * A role entry is validated down to its capability list and no further.
 *
 * `role_code` and `capabilities` are the two fields anything is decided from;
 * the scope fields are labels a surface prints. Requiring every one of them to
 * be present would mean a document written before a scope field existed is
 * thrown away, which costs a device its permissions to gain nothing.
 */
function isSessionRole(value: unknown): boolean {
  return (
    isRecord(value) &&
    typeof value.role_code === "string" &&
    isStringArray(value.capabilities)
  );
}

function isSessionContext(value: unknown): boolean {
  if (!isRecord(value)) {
    return false;
  }

  return (
    isNullableString(value.organization_id) &&
    isNullableString(value.event_id) &&
    isNullableString(value.department_id) &&
    isNullableString(value.node_locked_event_id)
  );
}

/**
 * An event entry needs an id and has to carry its window fields as timestamps
 * or as nothing, because those are what bound a cached session (CLIENT-008). A
 * window field holding something that is not a timestamp is a document this
 * client cannot reason about, and guessing at it would extend a cached
 * session's life on a malformed value.
 */
function isSessionEvent(value: unknown): boolean {
  if (!isRecord(value)) {
    return false;
  }

  return (
    typeof value.id === "string" &&
    isNullableString(value.ends_at) &&
    isNullableString(value.active_event_window_ends_at)
  );
}

function isIdentified(value: unknown): boolean {
  return isRecord(value) && typeof value.id === "string";
}

/**
 * The device block, which a document may carry, may carry as null, or may not
 * carry at all.
 *
 * All three are valid documents. What is not valid is a block that is present
 * and malformed: readiness reports "device trusted" from `trusted`, and a
 * client that read a missing or non-boolean field as false would show a
 * technician a failing trust check on a device the node has no complaint about.
 */
function isSessionDevice(value: unknown): boolean {
  if (value === null || value === undefined) {
    return true;
  }

  return (
    isRecord(value) &&
    typeof value.id === "string" &&
    typeof value.trusted === "boolean" &&
    typeof value.trust_state === "string" &&
    isNullableString(value.trusted_until)
  );
}

/**
 * The preferences block, which a document may carry, may carry as null, or may
 * not carry at all — the same three valid shapes the device block has.
 *
 * A malformed one is tolerated rather than rejected, which is where this
 * differs from `device`. Trust is a claim about a credential and a client that
 * guessed at it would show a technician a failing check; a hidden-page list is
 * a display preference, and throwing away a whole session's permissions over an
 * unreadable one would be a device signed out to protect a menu.
 */
function isSessionPreferences(value: unknown): boolean {
  if (value === null || value === undefined) {
    return true;
  }

  return isRecord(value);
}

/**
 * Whether a value read back from durable storage is a session document this
 * client can establish permissions from.
 *
 * Structure only. Whether the document is still *usable* is a separate question
 * answered by {@link evaluateSessionDocument} in `sessionStaleness`, because a
 * perfectly well-formed document from a finished event must not grant access.
 */
export function isSessionDocument(value: unknown): value is SessionDocument {
  if (!isRecord(value)) {
    return false;
  }

  return (
    isSessionUser(value.user) &&
    Array.isArray(value.roles) &&
    value.roles.every(isSessionRole) &&
    isStringArray(value.capabilities) &&
    Array.isArray(value.organizations) &&
    value.organizations.every(isIdentified) &&
    Array.isArray(value.events) &&
    value.events.every(isSessionEvent) &&
    Array.isArray(value.departments) &&
    value.departments.every(isIdentified) &&
    Array.isArray(value.teams) &&
    value.teams.every(isIdentified) &&
    isSessionContext(value.context) &&
    isSessionDevice(value.device) &&
    isSessionPreferences(value.preferences) &&
    typeof value.refreshed_at === "string"
  );
}

/**
 * The event the document's context resolved to, when the document also carries
 * that event.
 *
 * A locked node answers for its own event whether or not the caller holds an
 * association with it, so `context.event_id` can name an event that is not in
 * `events`. That case returns null rather than throwing: the client has a
 * context but no window for it, which is a state `sessionStaleness` decides on.
 */
export function sessionContextEvent(
  document: SessionDocument,
): SessionEvent | null {
  const eventId = document.context.event_id;

  if (eventId === null) {
    return null;
  }

  return document.events.find((event) => event.id === eventId) ?? null;
}
