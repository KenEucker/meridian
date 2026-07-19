/// <reference types="vite/client" />

declare const __MERIDIAN_CLIENT_VERSION__: string;
declare const __MERIDIAN_DEPLOYMENT_TARGET__: "server" | "mobile" | "desktop";
declare const __MERIDIAN_UI_MODE__: "admin" | "field" | "kiosk";

declare module "*.vue" {
  import type { DefineComponent } from "vue";
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}
