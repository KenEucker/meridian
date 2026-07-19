export type DeploymentTarget = "server" | "mobile" | "desktop";
export type UiMode = "admin" | "field" | "kiosk";
export type ProductName = "Meridian Admin" | "Meridian Field" | "Meridian Kiosk";

export interface MeridianAppConfig {
  readonly deploymentTarget: DeploymentTarget;
  readonly uiMode: UiMode;
  readonly productName: ProductName;
}

const CONFIG_BY_DEPLOYMENT_TARGET = {
  server: {
    deploymentTarget: "server",
    uiMode: "admin",
    productName: "Meridian Admin",
  },
  mobile: {
    deploymentTarget: "mobile",
    uiMode: "field",
    productName: "Meridian Field",
  },
  desktop: {
    deploymentTarget: "desktop",
    uiMode: "kiosk",
    productName: "Meridian Kiosk",
  },
} satisfies Record<DeploymentTarget, MeridianAppConfig>;

const CONFIG_BY_UI_MODE = Object.fromEntries(
  Object.values(CONFIG_BY_DEPLOYMENT_TARGET).map((config) => [
    config.uiMode,
    config,
  ]),
) as Record<UiMode, MeridianAppConfig>;

export function appConfigForDeploymentTarget(
  deploymentTarget: DeploymentTarget,
): MeridianAppConfig {
  return CONFIG_BY_DEPLOYMENT_TARGET[deploymentTarget];
}

export function appConfigForUiMode(uiMode: UiMode): MeridianAppConfig {
  return CONFIG_BY_UI_MODE[uiMode];
}

export const meridianAppConfig = appConfigForDeploymentTarget(
  __MERIDIAN_DEPLOYMENT_TARGET__,
);
