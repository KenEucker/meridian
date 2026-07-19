/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION?: string;
}

interface MeridianRuntimeConfig {
  readonly apiBaseUrl?: string;
  readonly deploymentTarget?: "server" | "mobile" | "desktop";
  readonly uiMode?: "admin" | "field" | "kiosk";
}

interface Window {
  __MERIDIAN_RUNTIME_CONFIG__?: MeridianRuntimeConfig;
}

declare const __MERIDIAN_CLIENT_VERSION__: string;
declare const __MERIDIAN_DEPLOYMENT_TARGET__: "server" | "mobile" | "desktop";
declare const __MERIDIAN_UI_MODE__: "admin" | "field" | "kiosk";

declare module "*.vue" {
  import type { DefineComponent } from "vue";
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}
