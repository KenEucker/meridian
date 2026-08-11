/// <reference types="vite/client" />

interface MeridianRuntimeConfig {
  readonly apiBaseUrl?: string;
  readonly deploymentTarget?: "server" | "mobile" | "desktop";
  readonly uiMode?: "admin" | "field" | "kiosk";
  /** The trusted shared workstation this machine is, when it is one (M16.9). */
  readonly sharedWorkstationId?: string;
  /**
   * The Electron wrapper's own version, stated by the wrapper that is serving
   * this bundle (M19.1; technical spec 26.3). Absent in a browser.
   */
  readonly desktopAppVersion?: string;
}

/**
 * The part of Capacitor's injected global Meridian reads (M19.1).
 *
 * Declared here rather than taken from `@capacitor/core`: the mobile wrapper
 * owns that dependency, and the shared client only needs to know whether it is
 * running as the installed app and on what.
 */
interface CapacitorNativeRuntime {
  readonly getPlatform?: () => string;
}

interface Window {
  __MERIDIAN_RUNTIME_CONFIG__?: MeridianRuntimeConfig;
  Capacitor?: CapacitorNativeRuntime;
}

declare const __MERIDIAN_CLIENT_VERSION__: string;
declare const __MERIDIAN_DEPLOYMENT_TARGET__: "server" | "mobile" | "desktop";
declare const __MERIDIAN_UI_MODE__: "admin" | "field" | "kiosk";

declare module "*.vue" {
  import type { DefineComponent } from "vue";
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}
