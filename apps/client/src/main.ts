import { createApp } from "vue";

import App from "@/App.vue";
import { loadBrandingProfile } from "@/branding/brandingProfile";
import { FIXTURE_ORGANIZATION_ID } from "@/branding/brandingRouteProps";
import { installDevelopmentFieldSessionFromEnv } from "@/field-reports/fieldSession";
import { router } from "@/router";
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

createApp(App).use(router).mount("#app");
