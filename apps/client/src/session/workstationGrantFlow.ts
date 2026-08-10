// The phone's half of the scan path (M18.61; AUTH-033, AUTH-034; technical
// spec 13.4; UI contract 12.3 `staff.workstation-code`).
//
// A device holding a session scans a locked workstation's QR, confirms the
// named workstation and event, and grants the sign-in request for its own
// user. Two refusals live here rather than on the node, because they are about
// which node the *device* is pointed at:
//
//  - a scanned code that is not a workstation sign-in code at all is named as
//    such rather than sent anywhere;
//  - a QR naming a different node is refused locally with both node identities
//    named (AUTH-034) — the grant would otherwise be issued into the wrong
//    database and surface as a baffling failure at the kiosk keyboard.
//
// Everything else — expiry, single grant, own-user scope — is the node's
// verdict, reported as it arrives.

import { reactive, readonly } from "vue";

import { MeridianApiError, meridianJson } from "@/api/meridianApi";
import { resolveOwnNodeIdentity } from "@/session/nodeIdentity";
import {
  parseWorkstationSignInQrText,
  type WorkstationSignInQrPayload,
} from "@/support/workstationSignInQr";

export type WorkstationGrantStep =
  /** Nothing scanned. */
  | "idle"
  /** A scanned request is waiting for the person to confirm and grant. */
  | "confirming"
  /** The node accepted the grant; the workstation is signing them in. */
  | "granted";

interface WorkstationGrantState {
  step: WorkstationGrantStep;
  /** The scanned request, while one is being confirmed. */
  scanned: WorkstationSignInQrPayload | null;
  /** The named workstation and event, resolved from this device's node. */
  workstationName: string | null;
  eventName: string | null;
  granting: boolean;
  /** What to tell the person when a scan or a grant is refused. */
  error: string | null;
}

const state = reactive<WorkstationGrantState>({
  step: "idle",
  scanned: null,
  workstationName: null,
  eventName: null,
  granting: false,
  error: null,
});

export const workstationGrantState = readonly(state);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

/**
 * Take a scanned text and either put the named workstation up for
 * confirmation, or say why not.
 */
export async function acceptScannedWorkstationCode(text: string): Promise<boolean> {
  state.error = null;

  const scanned = parseWorkstationSignInQrText(text);

  if (scanned === null) {
    state.error =
      "That is not a Meridian workstation sign-in code. Scan the code on the workstation's locked screen, or enter its workstation code below.";

    return false;
  }

  // AUTH-034, the device's half: a QR naming a node this device is not
  // pointed at is refused before anything is issued, with both identities
  // named so the person can tell which device is pointed at the wrong place.
  const ownNode = await resolveOwnNodeIdentity();

  if (
    scanned.nodeId !== null &&
    ownNode !== null &&
    ownNode.id !== null &&
    scanned.nodeId !== ownNode.id
  ) {
    const wanted = scanned.nodeName ?? scanned.nodeId;
    const using = ownNode.name ?? ownNode.id;

    state.error =
      `That workstation signs in through the node "${wanted}", but this device is using "${using}". ` +
      "Nothing was issued. Connect this device to the workstation's node, or use a device that already is.";

    return false;
  }

  // The named workstation and its pinned event, from the same unauthenticated
  // read the Kiosk resolves its own context with. A workstation this node
  // does not hold is most likely the same foreign-node mistake without the
  // identities to prove it.
  try {
    const payload = await meridianJson<unknown>(
      `/api/kiosk/workstations/${scanned.workstationId}`,
    );

    const workstation = isRecord(payload) && isRecord(payload.shared_workstation)
      ? payload.shared_workstation
      : null;
    const event = isRecord(payload) && isRecord(payload.event) ? payload.event : null;

    state.step = "confirming";
    state.scanned = scanned;
    state.workstationName = workstation === null ? null : asString(workstation.name);
    state.eventName = event === null ? null : asString(event.name);

    return true;
  } catch (error) {
    state.error =
      error instanceof MeridianApiError
        ? "This device's node does not hold that workstation. It may belong to a different node than the one this device is using."
        : "This device could not reach the node to confirm the workstation. Try again, or enter a login code at the workstation instead.";

    return false;
  }
}

/**
 * Grant the confirmed request for the signed-in user (AUTH-033). The grant
 * carries no user field — whose session grants is whose sign-in it is.
 */
export async function grantScannedWorkstationSignIn(): Promise<boolean> {
  const scanned = state.scanned;

  if (state.step !== "confirming" || scanned === null || state.granting) {
    return false;
  }

  state.granting = true;
  state.error = null;

  try {
    await meridianJson<unknown>(
      `/api/auth/workstation-sign-in-requests/${scanned.requestId}/grant`,
      {
        method: "POST",
        body: JSON.stringify({ node_id: scanned.nodeId }),
      },
    );

    state.step = "granted";

    return true;
  } catch (error) {
    state.error =
      error instanceof MeridianApiError
        ? error.message
        : "This device could not reach the node to grant the sign-in. Try again, or enter a login code at the workstation instead.";

    return false;
  } finally {
    state.granting = false;
  }
}

/** Back to nothing scanned: after a grant, a cancel, or leaving the surface. */
export function resetWorkstationGrantFlow(): void {
  state.step = "idle";
  state.scanned = null;
  state.workstationName = null;
  state.eventName = null;
  state.granting = false;
  state.error = null;
}
