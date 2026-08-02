import { createApp } from "vue";

import App from "@/App.vue";
import { followSessionBranding } from "@/branding/brandingContext";
import { clearCachedLogisticsDesk } from "@/department-ops/logisticsDeskCache";
import { discardFieldReportsOutsideEvent } from "@/field-reports/fieldReportRuntime";
import { installDevelopmentFieldSessionFromEnv } from "@/field-reports/fieldSession";
import { redirectWhenSignedOut, requiresSignIn, router } from "@/router";
// Importing this adopts the token this device already holds and registers it as
// the credential every request carries (M16.11).
import { signedIn } from "@/session/apiLogin";
import { loadClientSession } from "@/session/clientSession";
import { registerSessionContextReset } from "@/session/sessionContext";
import { installLocalFieldSessionFromEnv } from "@/session/localFieldSession";
import "@meridian/ui-tokens/tokens.css";
import "@/assets/base.css";

installDevelopmentFieldSessionFromEnv();

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
   * The Logistics Desk's stored department index goes whole rather than by
   * event, because it is one department's roster and there is no version of it
   * that belongs to the context being entered (M18.8; SLB-021, CLIENT-014). The
   * same registry runs on sign-out and on a shared-workstation session end, which
   * is what keeps a department's staff off a workstation the next person signs
   * in to (technical spec 13.3).
   */
  clearCachedLogisticsDesk();
});

/*
 * Establish the session the same way, and for the same reason (CLIENT-007,
 * CLIENT-010).
 *
 * The durable copy is installed synchronously inside `loadClientSession`, so a
 * device that has been online before comes up already knowing what its user may
 * do; the node's answer replaces it when one arrives. Awaiting it would hold the
 * mount on a network call, which is the failure this cache exists to prevent.
 *
 * Navigation follows from this and from nothing else (M16.6, CLIENT-004), which
 * is why the local development session is installed only when the client ended
 * up with no document at all: a developer running `npm run dev` against a seeded
 * node, who has not signed in, would otherwise be shown an empty shell. A node
 * that answers always wins, here and on every later refresh, and so does a
 * cached session from a real sign-in.
 */
// Whether this device came up holding a token, captured before the refresh can
// drop it. A refused refresh means two different things depending on the answer:
// somebody was signed out, or nobody was ever signed in (M16.11).
const bootedSignedIn = signedIn.value;

/*
 * A client that loses its session goes to sign in, wherever it was standing
 * (M16.11; AUTH-023). Installed before the first resolution so a refusal that
 * lands during boot is covered too.
 */
redirectWhenSignedOut();

void loadClientSession().then((outcome) => {
  if (outcome !== "refreshed") {
    installLocalFieldSessionFromEnv({
      credentialRefused: outcome === "unauthenticated" && bootedSignedIn,
    });
  }

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
