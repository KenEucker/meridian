/**
 * What the desktop wrapper tells the Kiosk about the machine it is running on
 * (M18.32; technical spec 13.1).
 *
 * The shared client reads `window.__MERIDIAN_RUNTIME_CONFIG__` for the handful
 * of facts a build cannot know: which node to talk to, which artifact this is,
 * and which trusted shared workstation the machine is. Every one of those is the
 * deployment's to answer, and for an on-site machine the deployment is this
 * wrapper.
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
 * When no workstation is configured, nothing is exposed. A desktop install that
 * is not a shared workstation should look to the Kiosk exactly as it did before
 * this existed, so the client falls back to whatever a technician configured on
 * the machine and shows setup when there is nothing to fall back to.
 */

import { contextBridge } from "electron";

const sharedWorkstationId = process.env.MERIDIAN_SHARED_WORKSTATION_ID?.trim();

if (sharedWorkstationId) {
  contextBridge.exposeInMainWorld("__MERIDIAN_RUNTIME_CONFIG__", {
    sharedWorkstationId,
  });
}
