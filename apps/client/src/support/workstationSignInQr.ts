// The text a workstation sign-in QR carries, built on the Kiosk and parsed on
// the phone (M18.60, M18.61; AUTH-032, AUTH-034; technical spec 13.4).
//
// One module owns the format so the two ends cannot drift: the locked Kiosk
// builds this string into its QR, and `staff.workstation-code` reads it back
// off the camera. Everything in it is public — the workstation id, the issuing
// node's identity, and the request id grant nothing, which is what makes a
// photograph of the locked screen harmless (the pickup secret never travels
// here).

const SCHEME = "meridian:workstation-sign-in";

export interface WorkstationSignInQrPayload {
  readonly workstationId: string;
  /** The issuing node's id, for the AUTH-034 foreign-node refusal. */
  readonly nodeId: string | null;
  /** The issuing node's name, so the refusal can say it in words. */
  readonly nodeName: string | null;
  readonly requestId: string;
}

export function buildWorkstationSignInQrText(payload: WorkstationSignInQrPayload): string {
  const parts = [
    `${SCHEME}?v=1`,
    `&w=${encodeURIComponent(payload.workstationId)}`,
    `&n=${encodeURIComponent(payload.nodeId ?? "")}`,
    `&r=${encodeURIComponent(payload.requestId)}`,
  ];

  if (payload.nodeName !== null && payload.nodeName !== "") {
    parts.push(`&nn=${encodeURIComponent(payload.nodeName.slice(0, 24))}`);
  }

  return parts.join("");
}

/**
 * Read a scanned QR back, or null for anything that is not a workstation
 * sign-in code — a boarding pass, a menu, a sticker on the desk. Refusing to
 * parse is the answer there; what to *say* belongs to the surface.
 */
export function parseWorkstationSignInQrText(text: string): WorkstationSignInQrPayload | null {
  const trimmed = text.trim();

  if (!trimmed.startsWith(`${SCHEME}?`)) {
    return null;
  }

  const params = new URLSearchParams(trimmed.slice(SCHEME.length + 1));

  if (params.get("v") !== "1") {
    return null;
  }

  const workstationId = params.get("w") ?? "";
  const requestId = params.get("r") ?? "";

  if (workstationId === "" || requestId === "") {
    return null;
  }

  const nodeId = params.get("n") ?? "";
  const nodeName = params.get("nn") ?? "";

  return {
    workstationId,
    nodeId: nodeId === "" ? null : nodeId,
    nodeName: nodeName === "" ? null : nodeName,
    requestId,
  };
}
