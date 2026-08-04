// The organization and event a client is working in, and how it moves between
// them (M16.7; CLIENT-011 through CLIENT-014; technical spec 11A.3;
// UI implementation contract 19A.3; UI operating guide 8.3A).
//
// Context is resolved, not chosen. The node decides first — an on-site node
// locked to an event holds that event's records and no others, so its lock is
// the context and there is nothing to pick between — and the session response
// narrows what is left to the user's own associations. This module reads that
// answer out of the document; it never computes a context of its own, and there
// is no client-side default anywhere in it.
//
// Four rules follow from that, and each is a requirement rather than a
// preference:
//
//  1. **The node's lock wins** (CLIENT-011). `context.event_id` already carries
//     it; `sessionNodeLock` is only how a surface *says* the choice is absent
//     rather than appearing to have lost it.
//  2. **Only real associations are offered** (CLIENT-012). Every organization
//     and event a switcher can show comes out of `organizations` and `events` on
//     the document, which are the caller's own by construction.
//  3. **Offline means locked** (CLIENT-013). Switching needs the node to answer
//     `/api/me` at the new event, so a client that cannot reach it is not
//     offered a control that cannot work. Both halves are checked — the device
//     reporting a network, and the session actually being live rather than
//     cached — because a device can hold a network and still not reach its node.
//  4. **A switch replaces, never merges** (CLIENT-014). Permissions and
//     navigation come from the replaced document, branding follows the
//     organization the new document names, and anything else the previous
//     context populated is dropped through the reset registry below.
//
// None of it is enforcement. Server-side authorization remains the boundary: the
// node resolves the requested event for itself and refuses one the caller holds
// no association with, whatever this module offered.

import { computed } from "vue";

import { deviceConnectivity } from "@/offline/useConnectivity";
import {
  clientSessionState,
  refreshClientSession,
  sessionAccessGranted,
} from "@/session/clientSession";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import type { SessionDocument } from "@/session/sessionDocument";

/** One event the user holds an association with. */
export interface SessionContextEvent {
  readonly eventId: string;
  readonly organizationId: string;
  readonly eventLabel: string;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  /** Whether this is the event the node is locked to. */
  readonly isNodeLocked: boolean;
  /** Whether this is the event the session currently resolved to. */
  readonly isCurrent: boolean;
}

/** One organization the user holds an association with, and its events. */
export interface SessionContextOrganization {
  readonly organizationId: string;
  readonly organizationLabel: string;
  readonly isCurrent: boolean;
  readonly events: readonly SessionContextEvent[];
}

/** The event a locked node answers for, whatever the caller would prefer. */
export interface SessionNodeLock {
  readonly eventId: string;
  /**
   * Null when the locked event is not one of the caller's own associations. A
   * locked node answers for its event regardless of who is standing at it, so
   * the document can name an event it does not otherwise carry.
   */
  readonly eventLabel: string | null;
}

/**
 * Why a client is not offering a switcher, or null when it is.
 *
 * A reason rather than a boolean because the four cases are not the same
 * message: a node lock is permanent for this install, a disconnection is
 * temporary, a single association is nothing to say sorry for, and no session at
 * all is a sign-in state. Operating guide 8.3A asks for the switcher to be
 * absent rather than disabled, which means the explanation has to come from
 * somewhere other than the control.
 */
export type SessionSwitchUnavailableReason =
  | "no_session"
  | "node_locked"
  | "disconnected"
  | "single_context";

export type SessionSwitchOutcome =
  /** The node answered at the new event and the client is now working in it. */
  | "switched"
  /** Switching is not on offer, or the target is not one of the user's own. */
  | "unavailable"
  /** The node could not be reached. The client stays where it was. */
  | "unreachable"
  /** The node refused the credential. The session and its cache are dropped. */
  | "unauthenticated"
  /** The node answered with something this client cannot establish a session from. */
  | "unusable";

/** The context a switch has just landed in, handed to each registered reset. */
export interface SessionContextChange {
  readonly organizationId: string | null;
  readonly eventId: string | null;
}

/**
 * Something a switch has to drop because it belonged to the context being left.
 *
 * A registry rather than a list of imports here, because the things that need
 * dropping belong to the features that populated them and this module has no
 * business knowing what those are. It also keeps the rule in one place as the
 * surfaces in M16.14 through M16.22 bind: whatever a feature caches for a
 * context, it registers here, and CLIENT-014 stays true without anyone having to
 * remember to extend a switch function.
 */
export type SessionContextReset = (context: SessionContextChange) => void;

const contextResets = new Set<SessionContextReset>();

/**
 * Register data to drop on a context switch. Returns the unregister function.
 */
export function registerSessionContextReset(
  reset: SessionContextReset,
): () => void {
  contextResets.add(reset);

  return () => {
    contextResets.delete(reset);
  };
}

/** The document context may be read from, or null while access is refused. */
const grantedDocument = computed<SessionDocument | null>(() =>
  sessionAccessGranted.value ? clientSessionState.document : null,
);

/**
 * The organization the client is operating in (CLIENT-011).
 *
 * Resolved by the server from the context event, so on a locked node it is the
 * locked event's organization and nobody had to choose it. This is what branding
 * follows, which is how a switch re-resolves branding without a second rule.
 */
export const sessionOrganizationId = computed<string | null>(
  () => grantedDocument.value?.context.organization_id ?? null,
);

/**
 * The resolved organization's slug, for the addresses that are built from one
 * rather than from an identifier (APP-017).
 *
 * The participation link is meant to be read, said aloud, and pasted into a
 * message somebody else will act on, so it carries the slug an organization
 * chose rather than the UUID Meridian minted.
 */
export const sessionOrganizationSlug = computed<string | null>(() => {
  const document = grantedDocument.value;
  const organizationId = document?.context.organization_id ?? null;

  if (document === null || organizationId === null) {
    return null;
  }

  return (
    document.organizations.find(
      (organization) => organization.id === organizationId,
    )?.slug ?? null
  );
});

export const sessionOrganizationLabel = computed<string | null>(() => {
  const document = grantedDocument.value;
  const organizationId = document?.context.organization_id ?? null;

  if (document === null || organizationId === null) {
    return null;
  }

  return (
    document.organizations.find(
      (organization) => organization.id === organizationId,
    )?.name ?? null
  );
});

/**
 * The event this node is locked to, when it is locked to one.
 *
 * Present so a surface can state why there is no switcher. A locked node reports
 * the lock rather than a choice, and a client that simply showed nothing would
 * look like it had lost a control it never had here (technical spec 11A.3).
 */
export const sessionNodeLock = computed<SessionNodeLock | null>(() => {
  const document = grantedDocument.value;
  const lockedEventId = document?.context.node_locked_event_id ?? null;

  if (document === null || lockedEventId === null) {
    return null;
  }

  return {
    eventId: lockedEventId,
    eventLabel:
      document.events.find((event) => event.id === lockedEventId)?.name ?? null,
  };
});

/**
 * Every organization the user holds an association with, each carrying its own
 * events (CLIENT-012).
 *
 * Grouped rather than flat because that is the shape the two context screens
 * take — organization selection, then event selection inside it (UI contract
 * 12.2) — and because two events with the same name in different organizations
 * are otherwise indistinguishable in a list.
 *
 * An organization with no events is still listed. It is a real association, and
 * saying so is more useful than silently dropping it; the screen offers nothing
 * to enter it with, because an event is what a context resolves at.
 */
export const sessionContextOrganizations = computed<
  readonly SessionContextOrganization[]
>(() => {
  const document = grantedDocument.value;

  if (document === null) {
    return [];
  }

  const currentOrganizationId = document.context.organization_id;
  const currentEventId = document.context.event_id;
  const lockedEventId = document.context.node_locked_event_id;

  return document.organizations.map((organization) => ({
    organizationId: organization.id,
    organizationLabel: organization.name,
    isCurrent: organization.id === currentOrganizationId,
    events: document.events
      .filter((event) => event.organization_id === organization.id)
      .map((event) => ({
        eventId: event.id,
        organizationId: event.organization_id,
        eventLabel: event.name,
        startsAt: event.starts_at,
        endsAt: event.ends_at,
        isNodeLocked: event.id === lockedEventId,
        isCurrent: event.id === currentEventId,
      })),
  }));
});

/** One organization from {@link sessionContextOrganizations}, by id. */
export function sessionContextOrganization(
  organizationId: string,
): SessionContextOrganization | null {
  return (
    sessionContextOrganizations.value.find(
      (organization) => organization.organizationId === organizationId,
    ) ?? null
  );
}

/**
 * Why the client is not offering a switcher, or null when it is.
 *
 * The order is the order the reasons override each other. A node lock is checked
 * before connectivity because a locked install will not offer switching however
 * good its network is, and "reconnect to switch" would be a promise it cannot
 * keep.
 */
export const sessionSwitchingUnavailableReason =
  computed<SessionSwitchUnavailableReason | null>(() => {
    const document = grantedDocument.value;

    if (document === null) {
      return "no_session";
    }

    if (document.context.node_locked) {
      return "node_locked";
    }

    /*
     * Both halves, because they are different failures with the same
     * consequence (CLIENT-013). `deviceConnectivity` catches a device that knows
     * it has no network; a session that is running from cache catches a device
     * that has a network and still could not reach its node, which on an event
     * site is the more common of the two.
     */
    if (
      deviceConnectivity.value !== "online" ||
      clientSessionState.status !== "live"
    ) {
      return "disconnected";
    }

    // The server's own answer, and the last word: it is what knows whether this
    // node can serve any context other than the one it is already in.
    if (!document.context.switching_available) {
      return "single_context";
    }

    return null;
  });

/** Whether a switcher may be presented at all (CLIENT-012, CLIENT-013). */
export const sessionSwitchingAvailable = computed(
  () => sessionSwitchingUnavailableReason.value === null,
);

/**
 * Work in another event, and in the organization that event belongs to
 * (CLIENT-012, CLIENT-014).
 *
 * Organization switching is event switching. The node resolves the organization
 * from the event it resolves the session at, so naming an event in another
 * organization is how a client arrives in that organization — there is no
 * separate organization request, and inventing one on the client would be a
 * second answer to a question the server already answers.
 *
 * The previous context is discarded after the node answers, not before. A switch
 * that cannot reach the node leaves the client exactly where it was, which is
 * better than clearing the screen and then having nothing to put on it; and
 * because the discard runs in the same synchronous step as the new document
 * being installed, there is no frame in which the old data is on screen beside
 * the new context.
 */
export async function switchSessionContext(
  eventId: string,
): Promise<SessionSwitchOutcome> {
  if (!sessionSwitchingAvailable.value) {
    return "unavailable";
  }

  const isAssociated =
    grantedDocument.value?.events.some((event) => event.id === eventId) ?? false;

  if (!isAssociated) {
    return "unavailable";
  }

  const outcome = await refreshClientSession({ eventId });

  if (outcome !== "refreshed") {
    return outcome === "skipped" ? "unavailable" : outcome;
  }

  discardPreviousContext();

  return "switched";
}

/**
 * Drop everything the context just left had populated.
 *
 * The department selection goes first and explicitly, rather than being left to
 * expire on its own: a stored department id that happens to exist in the new
 * context would otherwise carry a choice across a switch that was never made
 * about the new event.
 */
function discardPreviousContext(): void {
  const context = clientSessionState.document?.context ?? null;

  runContextResets({
    organizationId: context?.organization_id ?? null,
    eventId: context?.event_id ?? null,
  });
}

/**
 * Drop every context-scoped cache because the client now holds no context at all.
 *
 * What a shared-workstation session end wipes (M16.9; technical spec 13.3). It
 * runs the same registry a switch runs, so a feature that registered what does
 * not survive a switch does not also have to remember to register what does not
 * survive a sign-out.
 */
export function discardSessionContextData(): void {
  runContextResets({ organizationId: null, eventId: null });
}

function runContextResets(change: SessionContextChange): void {
  resetSelectedSessionDepartment();

  for (const reset of contextResets) {
    reset(change);
  }
}
