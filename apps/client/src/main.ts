import { createApp } from "vue";

import App from "@/App.vue";
import { followSessionBranding } from "@/branding/brandingContext";
import { discardFieldReportsOutsideEvent } from "@/field-reports/fieldReportRuntime";
import { installDevelopmentFieldSessionFromEnv } from "@/field-reports/fieldSession";
import { router } from "@/router";
// Imported for its side effect: adopting the token this device already holds and
// registering it as the credential every request carries (M16.11).
import "@/session/apiLogin";
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
void loadClientSession().then((outcome) => {
  if (outcome !== "refreshed") {
    installLocalFieldSessionFromEnv();
  }
});

createApp(App).use(router).mount("#app");
