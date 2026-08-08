import { createApp } from "vue";

import App from "@/App.vue";
import { followSessionBranding } from "@/branding/brandingContext";
import { resetEventHorizonPresence } from "@/event-horizon/eventHorizonModel";
import { installOfflineReadSetRefreshTriggers } from "@/offline/offlineReadSetRefresh";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { clearReadCache } from "@/offline/readCache";
import { discardFieldReportsOutsideEvent } from "@/field-reports/fieldReportRuntime";
import { redirectWhenSignedOut, requiresSignIn, router } from "@/router";
// Imported for its side effect: adopting the token this device already holds and
// registering it as the credential every request carries (M16.11).
import "@/session/apiLogin";
import { loadClientSession } from "@/session/clientSession";
import { registerSessionContextReset } from "@/session/sessionContext";
import "@meridian/ui-tokens/tokens.css";
import "@/assets/base.css";

/*
 * Resolve the organization's branding before anything renders (BRAND-002,
 * BRAND-022).
 *
 * Deliberately not awaited. A cached profile is applied synchronously inside
 * `loadBrandingProfile`, so a device that has been here before paints its
 * organization's identity immediately; a device that has not shows Meridian's
 * until the network answers. Blocking the mount on a network call would mean a
 * blank screen on a bad connection, which is the wrong trade for chrome.
 *
 * Which organization that is comes from the session's context and nowhere else
 * (M16.7, CLIENT-011). Following it as a watch rather than resolving it once
 * means the same rule covers booting from the durable cache, the node's answer
 * replacing it, and a context switch (CLIENT-014).
 */
followSessionBranding();

/*
 * What a context switch drops (M16.7, CLIENT-014).
 *
 * Registered here rather than inside each feature so the list of what does not
 * survive a switch is readable in one place, and eager rather than on first use
 * so a switch cannot miss a registration that had not been imported yet.
 */
registerSessionContextReset((context) => {
  discardFieldReportsOutsideEvent(context.eventId);
  /*
   * Every stored read goes, whole (M18.9; technical spec 9.3, CLIENT-014). The
   * cache holds one user's authorized reads for one context, and there is no
   * version of a department roster or a planning aggregate that belongs to the
   * context being entered. The same registry runs on sign-out and on a
   * shared-workstation session end, which is what keeps one person's department
   * off the workstation the next person signs in to (technical spec 13.3).
   */
  clearReadCache();
  /*
   * And the offline read set, whole (M18.48; technical spec 9.3, 13.3;
   * CLIENT-014, CLIENT-022). It is the composed answer to "what may this user
   * read", and the person entering this context is not the person it was
   * composed for — on a shared workstation, quite literally. Dropped in memory
   * synchronously, so no surface can render a departed session's roster in the
   * tick after it ended.
   */
  clearOfflineReadSet();
  /*
   * The Event Horizon's menu summary goes with it (M18.43; HORIZON-014). It
   * summarizes one person's answer for one event; carried across a switch it
   * would offer — or withhold — the entry on the strength of somebody else's
   * readiness.
   */
  resetEventHorizonPresence();
});

/*
 * What fills the offline read set, and when (M18.49; CLIENT-001; technical spec
 * 9.3, 11A.4).
 *
 * Sign-in, a context switch, and regaining connectivity, watched for the life of
 * the application rather than for the life of a screen: a device holds its
 * user's authorized data because it signed in, not because it visited the right
 * surfaces first. Installed before the session resolves so the cached document a
 * device boots with counts as the sign-in, and before the mount so no screen has
 * to be showing for the set to arrive.
 */
installOfflineReadSetRefreshTriggers();

/*
 * A client that loses its session goes to sign in, wherever it was standing
 * (M16.11; AUTH-023). Installed before the first resolution so a refusal that
 * lands during boot is covered too.
 */
redirectWhenSignedOut();

/*
 * Establish the session the same way, and for the same reason (CLIENT-007,
 * CLIENT-010).
 *
 * The durable copy is installed synchronously inside `loadClientSession`, so a
 * device that has been online before comes up already knowing what its user may
 * do; the node's answer replaces it when one arrives. Awaiting it would hold the
 * mount on a network call, which is the failure this cache exists to prevent.
 *
 * Navigation follows from this and from nothing else (M16.6, CLIENT-001,
 * CLIENT-004). There is no second source any more: a client that resolves no
 * document and holds no cached one shows a sign-in screen, not a shell filled in
 * from a development session (M18.9).
 */
void loadClientSession().then(() => {
  /*
   * Now that the client knows what it holds, send it to sign in if it holds
   * nothing (M16.11).
   *
   * Here rather than in the route guard because the first navigation happens
   * while this resolution is still in flight: at that moment "holds nothing" says
   * how far the boot has got, not what the client has. `replace` rather than
   * `push`, so the surface nobody could see is not in the back history.
   */
  if (requiresSignIn(router.currentRoute.value.name)) {
    void router.replace({ name: "login" });
  }
});

createApp(App).use(router).mount("#app");
