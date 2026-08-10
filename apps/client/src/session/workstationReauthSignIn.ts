// The Kiosk's half of re-authentication by scan (M18.62; AUTH-036; technical
// spec 13.4; UI contract 12.8 `kiosk.reauth`, 18.2).
//
// The same shape as `workstationSignIn`, for the other purpose: a workstation
// holding a live session opens a request bound to that session, presents it as
// a QR beside the typed code field, and polls for the grant with the pickup
// secret only it holds. Only the session's own user's grant confirms — anybody
// else's is refused on their own phone and hands nothing over — and a
// collected grant stamps `reauthenticated_at` exactly as a typed code does.
//
// No session key moves. The collect response is the same session document the
// typed confirmation returns, applied to the session this machine already
// holds. The same three rules as the sign-in presentation apply: an expired
// request is replaced, an unreachable node falls back to the typed field with
// a plain statement (M18.53), and the pickup secret stays in a module-local
// binding.

import { reactive, readonly } from "vue";

import { MeridianApiError, meridianJson } from "@/api/meridianApi";
import {
  applyWorkstationSessionDocument,
  workstationSessionState,
} from "@/session/workstationSession";
import { buildWorkstationSignInQrText } from "@/support/workstationSignInQr";

/** How often the confirmation screen asks whether anybody granted. */
export const REAUTH_POLL_INTERVAL_MS = 3_000;

/** How many ticks an unavailable presentation waits before asking again. */
const RETRY_TICKS = 5;

/** How many consecutive transient failures back the polling off. */
const TRANSIENT_BACKOFF_TICKS = 4;

/**
 * Whether a refusal is the node's verdict about *this request* rather than
 * about the moment. Same reasoning as the sign-in presentation's: replacing a
 * live request over a rate limit turns one refused poll into a refused open.
 */
function isVerdictAboutRequest(status: number): boolean {
  return status === 404 || status === 410;
}

export type WorkstationReauthPresentationStatus =
  | "idle"
  | "opening"
  | "presenting"
  | "unavailable";

interface WorkstationReauthPresentationState {
  status: WorkstationReauthPresentationStatus;
  requestId: string | null;
  expiresAt: string | null;
  qrText: string | null;
  unavailableReason: string | null;
}

/** Module-local for the same reason the sign-in presentation's is (AUTH-037). */
let pickupSecret: string | null = null;

let ticksUntilRetry = 0;

let polling = false;

let transientFailures = 0;

let ticksUntilPoll = 0;

const state = reactive<WorkstationReauthPresentationState>({
  status: "idle",
  requestId: null,
  expiresAt: null,
  qrText: null,
  unavailableReason: null,
});

export const workstationReauthState = readonly(state);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
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
 * Open a re-authentication request for the live session and put its QR on
 * screen. With no live session there is nothing to confirm and nothing is
 * presented.
 */
export async function presentWorkstationReauth(): Promise<void> {
  if (workstationSessionState.status !== "active") {
    resetWorkstationReauth();

    return;
  }

  if (state.status === "opening") {
    return;
  }

  state.status = "opening";
  state.unavailableReason = null;

  try {
    const payload = await meridianJson<unknown>(
      "/api/auth/shared-workstation-session/reauthentication-requests",
      { method: "POST" },
    );

    if (!isRecord(payload) || !isRecord(payload.sign_in_request)) {
      becomeUnavailable(
        "This workstation could not open a scannable confirmation. Enter a login code instead.",
      );

      return;
    }

    const requestId = asString(payload.sign_in_request.id);
    const secret = asString(payload.pickup_secret);
    const workstation = isRecord(payload.shared_workstation) ? payload.shared_workstation : null;
    const node = isRecord(payload.node) ? payload.node : null;
    const workstationId = workstation === null ? null : asString(workstation.id);

    if (requestId === null || secret === null || workstationId === null) {
      becomeUnavailable(
        "This workstation could not open a scannable confirmation. Enter a login code instead.",
      );

      return;
    }

    pickupSecret = secret;
    ticksUntilRetry = 0;

    state.status = "presenting";
    state.requestId = requestId;
    state.expiresAt = asString(payload.sign_in_request.expires_at);
    state.qrText = buildWorkstationSignInQrText({
      workstationId,
      nodeId: node === null ? null : asString(node.id),
      nodeName: node === null ? null : asString(node.name),
      requestId,
    });
  } catch (error) {
    becomeUnavailable(
      error instanceof MeridianApiError
        ? `This workstation cannot offer scan-to-confirm: ${error.message} Enter a login code instead.`
        : "This workstation could not reach the node, so scan-to-confirm is unavailable. Enter a login code instead.",
    );
  }
}

export type WorkstationReauthTickOutcome =
  /** A collected grant confirmed the session. */
  | "confirmed"
  /** Still waiting, or nothing to do. */
  | "waiting";

/**
 * One beat of the confirmation screen's clock: replace an expired request,
 * retry an unavailable presentation, or poll for the grant.
 */
export async function tickWorkstationReauth(
  now: Date = new Date(),
): Promise<WorkstationReauthTickOutcome> {
  if (workstationSessionState.status !== "active") {
    resetWorkstationReauth();

    return "waiting";
  }

  if (state.status === "unavailable") {
    ticksUntilRetry -= 1;

    if (ticksUntilRetry <= 0) {
      await presentWorkstationReauth();
    }

    return "waiting";
  }

  if (state.status !== "presenting" || state.requestId === null || pickupSecret === null) {
    return "waiting";
  }

  const expiresAt = state.expiresAt === null ? Number.NaN : Date.parse(state.expiresAt);

  if (!Number.isNaN(expiresAt) && expiresAt <= now.getTime()) {
    await presentWorkstationReauth();

    return "waiting";
  }

  if (polling) {
    return "waiting";
  }

  if (ticksUntilPoll > 0) {
    ticksUntilPoll -= 1;

    return "waiting";
  }

  polling = true;

  try {
    const payload = await meridianJson<unknown>(
      `/api/auth/shared-workstation-session/reauthentication-requests/${state.requestId}/collect`,
      {
        method: "POST",
        body: JSON.stringify({ pickup_secret: pickupSecret }),
      },
    );

    transientFailures = 0;

    if (isRecord(payload) && payload.status === "collected") {
      const applied = applyWorkstationSessionDocument(payload);

      resetWorkstationReauth();

      return applied ? "confirmed" : "waiting";
    }

    return "waiting";
  } catch (error) {
    if (error instanceof MeridianApiError && isVerdictAboutRequest(error.status)) {
      transientFailures = 0;
      await presentWorkstationReauth();

      return "waiting";
    }

    transientFailures += 1;
    ticksUntilPoll = Math.min(transientFailures, TRANSIENT_BACKOFF_TICKS);

    return "waiting";
  } finally {
    polling = false;
  }
}

/** Drop the presentation and the secret. */
export function resetWorkstationReauth(): void {
  pickupSecret = null;
  ticksUntilRetry = 0;
  polling = false;
  transientFailures = 0;
  ticksUntilPoll = 0;

  state.status = "idle";
  state.requestId = null;
  state.expiresAt = null;
  state.qrText = null;
  state.unavailableReason = null;
}
