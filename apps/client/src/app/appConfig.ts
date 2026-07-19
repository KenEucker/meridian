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

interface MeridianAppConfigOverride {
  readonly deploymentTarget?: unknown;
  readonly uiMode?: unknown;
}

export function appConfigForDeploymentTarget(
  deploymentTarget: DeploymentTarget,
): MeridianAppConfig {
  return CONFIG_BY_DEPLOYMENT_TARGET[deploymentTarget];
}

export function appConfigForUiMode(uiMode: UiMode): MeridianAppConfig {
  return CONFIG_BY_UI_MODE[uiMode];
}

export function resolveMeridianAppConfig(
  override: MeridianAppConfigOverride = readRuntimeAppConfigOverride(),
): MeridianAppConfig {
  if (isUiMode(override.uiMode)) {
    return appConfigForUiMode(override.uiMode);
  }

  if (isDeploymentTarget(override.deploymentTarget)) {
    return appConfigForDeploymentTarget(override.deploymentTarget);
  }

  return appConfigForDeploymentTarget(__MERIDIAN_DEPLOYMENT_TARGET__);
}

function readRuntimeAppConfigOverride(): MeridianAppConfigOverride {
  if (typeof window === "undefined") {
    return {};
  }

  return {
    deploymentTarget: window.__MERIDIAN_RUNTIME_CONFIG__?.deploymentTarget,
    uiMode:
      window.__MERIDIAN_RUNTIME_CONFIG__?.uiMode ??
      new URLSearchParams(window.location.search).get("meridianUiMode"),
  };
}

function isDeploymentTarget(value: unknown): value is DeploymentTarget {
  return value === "server" || value === "mobile" || value === "desktop";
}

function isUiMode(value: unknown): value is UiMode {
  return value === "admin" || value === "field" || value === "kiosk";
}

export const meridianAppConfig = resolveMeridianAppConfig();
