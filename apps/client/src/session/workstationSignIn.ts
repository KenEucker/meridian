// The Kiosk's half of the scan path (M18.60; AUTH-032, AUTH-035; technical
// spec 13.4; UI contract 12.8; kiosk guide 4.1A).
//
// A locked workstation opens a sign-in request against its node and presents
// it as a QR beside its own name and `short_code`. A phone holding a session
// scans it and grants; this module polls for the grant with the pickup secret
// only this machine holds, and opens the session on collection.
//
// Three rules govern the lifecycle, and each is a requirement rather than a
// preference:
//
//  1. **An expired request is replaced, not left rendered** (M18.60). The QR
//     carries a request id with a two-minute life; a stale square on screen is
//     a code that scans and then fails, which teaches people the scanner is
//     broken.
//  2. **An unreachable node falls back to the typed field with a plain
//     statement** (M18.53). No spinner that never resolves, no blank square.
//     The typed login code is the path that still works, and the surface says
//     so in words.
//  3. **The secrets stay in module-local bindings.** The pickup secret and the
//     collected session key are never in reactive state, never rendered, and
//     never written to storage — the same discipline `workstationSession`
//     applies to the session key, and the reason a restarted Kiosk holds
//     nothing (technical spec 13.3).

import { reactive, readonly } from "vue";

import { MeridianApiError, meridianJson } from "@/api/meridianApi";
import { sharedWorkstationId } from "@/session/workstationIdentity";
import {
  installCollectedWorkstationSession,
  workstationSessionState,
} from "@/session/workstationSession";

/** How often the locked Kiosk asks whether anybody granted its request. */
export const SIGN_IN_POLL_INTERVAL_MS = 3_000;

/** How many ticks an unavailable presentation waits before asking again. */
const RETRY_TICKS = 5;

export type WorkstationSignInStatus =
  /** Nothing presented: no workstation identity, or a session is live. */
  | "idle"
  /** An open call is in flight. */
  | "opening"
  /** A request is on screen, waiting to be scanned and granted. */
  | "presenting"
  /** The node refused or could not be reached; the typed field is the path. */
  | "unavailable";

interface WorkstationSignInState {
  status: WorkstationSignInStatus;
  /** The public half: the request id the QR carries. */
  requestId: string | null;
  /** The node's expiry for the presented request. */
  expiresAt: string | null;
  /** The text the QR encodes. Public identifiers only. */
  qrText: string | null;
  workstationName: string | null;
  /** The typed fallback identifier displayed beside the QR. */
  shortCode: string | null;
  nodeName: string | null;
  /** What to tell the person when scan-to-sign-in is not available. */
  unavailableReason: string | null;
}

/**
 * The pickup secret, deliberately outside the reactive state. Nothing renders
 * it, nothing serializes it, and no store persists it (AUTH-037).
 */
let pickupSecret: string | null = null;

let ticksUntilRetry = 0;

let polling = false;

const state = reactive<WorkstationSignInState>({
  status: "idle",
  requestId: null,
  expiresAt: null,
  qrText: null,
  workstationName: null,
  shortCode: null,
  nodeName: null,
  unavailableReason: null,
});

export const workstationSignInState = readonly(state);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

/**
 * The text a phone's camera reads: workstation id, issuing node identity, and
 * request id (technical spec 13.4). All three are public — the QR grants
 * nothing, which is what makes a photograph of the screen harmless.
 */
function buildQrText(input: {
  readonly workstationId: string;
  readonly nodeId: string | null;
  readonly nodeName: string | null;
  readonly requestId: string;
}): string {
  const parts = [
    "meridian:workstation-sign-in?v=1",
    `&w=${encodeURIComponent(input.workstationId)}`,
    `&n=${encodeURIComponent(input.nodeId ?? "")}`,
    `&r=${encodeURIComponent(input.requestId)}`,
  ];

  if (input.nodeName !== null) {
    parts.push(`&nn=${encodeURIComponent(input.nodeName.slice(0, 24))}`);
  }

  return parts.join("");
}

function becomeUnavailable(reason: string): void {
  pickupSecret = null;
  ticksUntilRetry = RETRY_TICKS;

  state.status = "unavailable";
  state.requestId = null;
  state.expiresAt = null;
  state.qrText = null;
  state.unavailableReason = reason;
}

/**
 * Open a sign-in request and put its QR on screen.
 *
 * A node that refuses or cannot be reached leaves the surface on the typed
 * field with a statement of what is unavailable — never a spinner and never a
 * blank square (M18.53, M18.60).
 */
export async function presentWorkstationSignIn(): Promise<void> {
  const workstationId = sharedWorkstationId.value;

  if (workstationId === null || workstationSessionState.status === "active") {
    resetWorkstationSignIn();

    return;
  }

  if (state.status === "opening") {
    return;
  }

  state.status = "opening";
  state.unavailableReason = null;

  try {
    const payload = await meridianJson<unknown>(
      `/api/kiosk/workstations/${workstationId}/sign-in-requests`,
      { method: "POST" },
    );

    if (!isRecord(payload) || !isRecord(payload.sign_in_request)) {
      becomeUnavailable(
        "This workstation could not open a scannable sign-in code. Enter a login code instead.",
      );

      return;
    }

    const requestId = asString(payload.sign_in_request.id);
    const secret = asString(payload.pickup_secret);

    if (requestId === null || secret === null) {
      becomeUnavailable(
        "This workstation could not open a scannable sign-in code. Enter a login code instead.",
      );

      return;
    }

    const workstation = isRecord(payload.shared_workstation) ? payload.shared_workstation : null;
    const node = isRecord(payload.node) ? payload.node : null;

    pickupSecret = secret;
    ticksUntilRetry = 0;

    state.status = "presenting";
    state.requestId = requestId;
    state.expiresAt = asString(payload.sign_in_request.expires_at);
    state.workstationName = workstation === null ? null : asString(workstation.name);
    state.shortCode = workstation === null ? null : asString(workstation.short_code);
    state.nodeName = node === null ? null : asString(node.name);
    state.qrText = buildQrText({
      workstationId,
      nodeId: node === null ? null : asString(node.id),
      nodeName: node === null ? null : asString(node.name),
      requestId,
    });
  } catch (error) {
    becomeUnavailable(
      error instanceof MeridianApiError
        ? `This workstation cannot offer scan-to-sign-in: ${error.message} Enter a login code instead.`
        : "This workstation could not reach the node, so scan-to-sign-in is unavailable. Enter a login code instead.",
    );
  }
}

export type WorkstationSignInTickOutcome =
  /** A collected grant signed somebody in. */
  | "signed_in"
  /** Still waiting, or nothing to do. */
  | "waiting";

/**
 * One beat of the locked screen's clock: replace an expired request, retry an
 * unavailable presentation, or poll for the grant.
 */
export async function tickWorkstationSignIn(
  now: Date = new Date(),
): Promise<WorkstationSignInTickOutcome> {
  if (workstationSessionState.status === "active") {
    resetWorkstationSignIn();

    return "waiting";
  }

  if (state.status === "unavailable") {
    ticksUntilRetry -= 1;

    if (ticksUntilRetry <= 0) {
      await presentWorkstationSignIn();
    }

    return "waiting";
  }

  if (state.status !== "presenting" || state.requestId === null || pickupSecret === null) {
    return "waiting";
  }

  // An expired request is replaced rather than left rendered (M18.60): the
  // fresh open also refreshes the QR, so the square on screen always scans.
  const expiresAt = state.expiresAt === null ? Number.NaN : Date.parse(state.expiresAt);

  if (!Number.isNaN(expiresAt) && expiresAt <= now.getTime()) {
    await presentWorkstationSignIn();

    return "waiting";
  }

  if (polling) {
    return "waiting";
  }

  polling = true;

  try {
    const workstationId = sharedWorkstationId.value;

    if (workstationId === null) {
      resetWorkstationSignIn();

      return "waiting";
    }

    const payload = await meridianJson<unknown>(
      `/api/kiosk/workstations/${workstationId}/sign-in-requests/${state.requestId}/collect`,
      {
        method: "POST",
        body: JSON.stringify({ pickup_secret: pickupSecret }),
      },
    );

    if (isRecord(payload) && payload.status === "collected") {
      const installed = await installCollectedWorkstationSession(payload);

      resetWorkstationSignIn();

      return installed ? "signed_in" : "waiting";
    }

    return "waiting";
  } catch (error) {
    if (error instanceof MeridianApiError) {
      // The node's verdict on this request — spent, expired, or unknown. A
      // fresh one replaces it.
      await presentWorkstationSignIn();
    }

    // An unreachable node during a poll is not a reason to tear the QR down:
    // the request may still be granted, and the next poll may get through.
    return "waiting";
  } finally {
    polling = false;
  }
}

/** Drop the presentation and both secrets. */
export function resetWorkstationSignIn(): void {
  pickupSecret = null;
  ticksUntilRetry = 0;
  polling = false;

  state.status = "idle";
  state.requestId = null;
  state.expiresAt = null;
  state.qrText = null;
  state.workstationName = null;
  state.shortCode = null;
  state.nodeName = null;
  state.unavailableReason = null;
}
