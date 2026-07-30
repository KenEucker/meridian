import { createApp } from "vue";

import App from "@/App.vue";
import { loadBrandingProfile } from "@/branding/brandingProfile";
import { FIXTURE_ORGANIZATION_ID } from "@/branding/brandingRouteProps";
import { installDevelopmentFieldSessionFromEnv } from "@/field-reports/fieldSession";
import { router } from "@/router";
import { loadClientSession } from "@/session/clientSession";
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
 * The organization id comes from the development fixture until auth and
 * organization selection own that context, exactly as the rest of the client
 * still does.
 */
void loadBrandingProfile(FIXTURE_ORGANIZATION_ID);

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
 * is why the local development session is installed only when the node produced
 * no document: the client holds no bearer token until login is wired into it,
 * and a developer running against a seeded node would otherwise be shown an
 * empty shell. A node that answers always wins, here and on every later refresh.
 */
void loadClientSession().then((outcome) => {
  if (outcome !== "refreshed") {
    installLocalFieldSessionFromEnv();
  }
});

createApp(App).use(router).mount("#app");
