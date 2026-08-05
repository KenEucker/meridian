// The two kiosk widgets the device answers for itself (M18.28; UI contract 13.6).
//
// `kiosk.node_status` and `kiosk.switch_user` are catalogued by the node and
// deliberately not compiled by it. Whether the local node is reachable and who
// is standing at this workstation are facts about the machine somebody is in
// front of; a node asked the first could only answer for a request that already
// arrived, which is the one case where the answer is never in doubt.
//
// They are built in the same shape as every other widget so the kiosk home
// screen renders one kind of card. What is not shared is where the truth comes
// from, and that is why they live in their own module rather than being folded
// into the read.

import type { DashboardWidget } from "@/dashboard/dashboardModel";
import type { NodeConnectionStatus } from "@/offline/syncStatus";

/**
 * Local Node Status (UI contract 13.6).
 *
 * The quiet state is "Local node reachable", so this widget goes quiet exactly
 * when the node is answering and speaks up when it is not — the inverse of a
 * status light that is always on. Contract 16.1A's own labels and sentences are
 * used unchanged: a Kiosk saying something different about connectivity from the
 * shell beside it would be two answers to one question.
 */
export function kioskNodeStatusWidget(
  status: NodeConnectionStatus,
): DashboardWidget {
  const reachable = status.tone === "connected";

  return {
    id: "kiosk.node_status",
    group: "kiosk",
    title: "Local Node Status",
    scope: "kiosk/event",
    attention: reachable ? "routine" : "warning",
    attentionLabel: reachable ? "Routine" : "Warning",
    quiet: reachable,
    quietState: "Local node reachable",
    summary: reachable ? null : status.meaning,
    items: reachable ? [] : [{ label: status.label, detail: null, status: null }],
    metric: null,
    actionLabel: "View status if permitted",
    actionSurface: "readiness",
  };
}

/**
 * Current User (UI contract 13.6).
 *
 * Never quiet while a session is live. Technical spec 13.3 requires the active
 * user to be shown prominently on a shared workstation, and a card that went
 * quiet once somebody was signed in would remove the one thing it exists to
 * state. The workstation is named beside them as pinned context, which grants
 * nothing.
 *
 * Its action opens `kiosk.switch-user`, which is M18.32. Until that screen
 * exists the destination resolves to nothing and the card renders without a
 * link rather than with a dead one.
 */
export function kioskCurrentUserWidget(
  userName: string | null,
  workstationName: string | null,
): DashboardWidget {
  return {
    id: "kiosk.switch_user",
    group: "kiosk",
    title: "Current User",
    scope: "kiosk",
    attention: "routine",
    attentionLabel: "Routine",
    quiet: false,
    quietState: "User visible",
    summary:
      userName === null
        ? "Nobody is signed in at this workstation."
        : `${userName} is signed in at this workstation.`,
    items:
      workstationName === null
        ? []
        : [{ label: workstationName, detail: "Pinned workstation", status: null }],
    metric: null,
    actionLabel: "Switch user",
    actionSurface: "kiosk.switch-user",
  };
}
