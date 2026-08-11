/**
 * What the desktop wrapper tells the Kiosk about the machine it is running on
 * (M18.32, M19.1; technical spec 13.1, 26.3).
 *
 * The shared client reads `window.__MERIDIAN_RUNTIME_CONFIG__` for the handful
 * of facts a build cannot know: which node to talk to, which artifact this is,
 * which trusted shared workstation the machine is, and which version of the
 * wrapper is serving the bundle. Every one of those is the deployment's to
 * answer, and for an on-site machine the deployment is this wrapper.
 *
 * It runs in a preload with `contextIsolation` on, so `exposeInMainWorld` is the
 * only way across — and it is the right way: the value lands before any page
 * script runs, which matters because `workstationIdentity` reads it at module
 * import. Injecting it after load would be injecting it after the Kiosk had
 * already decided it was not a shared workstation.
 *
 * Nothing here is a credential. The workstation identifier grants nothing on its
 * own (AUTH-030): the node still requires a login code issued to a named person
 * and still requires the workstation to be trusted. That is precisely why it can
 * be handed to the renderer at all.
 *
 * When there is nothing to say, nothing is exposed. A desktop install with no
 * workstation and no resolvable version should look to the Kiosk exactly as it
 * did before this existed, so the client falls back to whatever a technician
 * configured on the machine and shows setup when there is nothing to fall back
 * to. What gets built and why lives in `desktopRuntimeConfig.ts`.
 */

import { contextBridge } from "electron";

import { buildDesktopRuntimeConfig } from "./desktopRuntimeConfig";

const runtimeConfig = buildDesktopRuntimeConfig({
  env: process.env,
  argv: process.argv,
});

if (runtimeConfig !== null) {
  contextBridge.exposeInMainWorld("__MERIDIAN_RUNTIME_CONFIG__", runtimeConfig);
}
