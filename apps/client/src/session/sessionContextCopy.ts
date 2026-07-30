// What the context screens say when there is nothing to choose (M16.7;
// CLIENT-013; technical spec 11A.3; UI operating guide 8.3A).
//
// Shared by both context screens and by the shell, because all three answer the
// same question from the same reason code and three copies of it would drift.
// The strings say what is true of the client rather than what the user did
// wrong: a locked node and a lost network are both ordinary states of a working
// install, and neither is a failure to apologise for.

import type { SessionSwitchUnavailableReason } from "@/session/sessionContext";

export function describeSwitchUnavailable(
  reason: SessionSwitchUnavailableReason,
  nodeLockedEventLabel: string | null,
): { readonly label: string; readonly meaning: string } {
  switch (reason) {
    case "no_session":
      return {
        label: "No session",
        meaning:
          "Sign in to see the organizations and events you are associated with.",
      };
    case "node_locked":
      return {
        label: "Set by this node",
        meaning: nodeLockedEventLabel
          ? `This node runs ${nodeLockedEventLabel} and holds no other event's records, so its organization and event cannot be changed from here.`
          : "This node is locked to one event and holds no other event's records, so its organization and event cannot be changed from here.",
      };
    case "disconnected":
      return {
        label: "Locked to this node's context",
        meaning:
          "Switching organization or event needs the node. This device is working from what it already holds until it can reach the node again.",
      };
    case "single_context":
      return {
        label: "One context",
        meaning:
          "You are associated with one organization and one event, so there is nothing to switch between.",
      };
  }
}

/** What a switch that did not land should say (CLIENT-014). */
export function describeSwitchFailure(
  outcome: "unavailable" | "unreachable" | "unauthenticated" | "unusable",
): string {
  switch (outcome) {
    case "unreachable":
      return "The node could not be reached, so nothing changed. This device is still working in its previous context.";
    case "unauthenticated":
      return "The node refused this device's credential. Sign in again to continue.";
    case "unusable":
      return "The node answered with something this client could not read, so nothing changed.";
    case "unavailable":
      return "That context is not available from this node.";
  }
}
