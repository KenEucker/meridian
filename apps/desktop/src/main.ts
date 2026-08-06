/**
 * Meridian Electron on-site wrapper main process.
 *
 * Responsibilities for M2.3 and M2.4 (technical spec 3.3, 25.1, 25.2, 25.3):
 * - Open the local Meridian web UI in a fullscreen/kiosk window.
 * - Hide browser chrome and the application menu.
 * - Auto-recover the wrapped UI if it crashes or fails to load (the local
 *   server may still be starting).
 * - Provide a toggleable health panel that displays node/server version
 *   placeholders read from the server health endpoint.
 *
 * The wrapper does not start or stop Docker Compose, does not block accidental
 * close, and does not include emergency export in Alpha 1.
 *
 * Domain logic lives in `config.ts` and `health.ts` (pure, unit tested). This
 * module is the thin Electron glue, verified by manual desktop QA
 * (QA-ELECTRON-01).
 */

import { join } from "node:path";

import { app, BrowserWindow, globalShortcut, nativeImage } from "electron";

import {
  resolveClientDistPath,
  resolveClientDevAppUrl,
  resolveClientPort,
  resolveClientVersion,
  resolveHealthUrl,
  resolveAppIconPath,
  resolveAppUrlOverride,
  resolveServerUrlSetting,
  resolveWindowedMode,
  NODE_SETTINGS_FILE,
  type ResolvedServerUrl,
} from "./config";
import {
  brandingManifestUrl,
  resolveBrandedOrganizationId,
  resolveWindowIconUrl,
  type BrandingManifest,
} from "./branding";
import { buildHealthPanelModel, fetchServerHealth, renderHealthPanelHtml } from "./health";
import { startClientStaticServer, type ClientStaticServer } from "./staticClientServer";

const RELOAD_DELAY_MS = 2000;
const HEALTH_TOGGLE_SHORTCUT = "CommandOrControl+Shift+H";

let mainWindow: BrowserWindow | null = null;
let healthWindow: BrowserWindow | null = null;
let clientServer: ClientStaticServer | null = null;
let currentAppUrl = "";
let currentMeridianVersion = "unknown";

/**
 * The node this wrapper reads health and branding from.
 *
 * Resolved per call rather than cached at startup, so a technician who writes
 * the settings file while the app is running sees it take effect on the next
 * health refresh instead of after a restart. Electron's userData path is only
 * available once the app is ready, so this is a function rather than a
 * constant.
 */
function nodeSetting(): ResolvedServerUrl {
  const settingsPath = app.isReady()
    ? join(app.getPath("userData"), NODE_SETTINGS_FILE)
    : null;

  return resolveServerUrlSetting(process.env, settingsPath);
}

function createMainWindow(appUrl: string): BrowserWindow {
  // Fullscreen and locked down is what an on-site workstation is, and stays the
  // default. `MERIDIAN_DESKTOP_WINDOWED` is for exercising a Kiosk workflow on a
  // developer's own machine, where a fullscreen window with no menu bar is how a
  // testing session ends in a forced quit.
  const windowed = resolveWindowedMode(process.env);

  const window = new BrowserWindow({
    show: false,
    fullscreen: !windowed,
    kiosk: !windowed,
    width: windowed ? 1280 : undefined,
    height: windowed ? 900 : undefined,
    autoHideMenuBar: true,
    backgroundColor: "#11151c",
    icon: resolveAppIconPath(process.env),
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      // Tells the Kiosk which trusted shared workstation this machine is, before
      // any page script runs (M18.32). See `preload.ts` for why that ordering is
      // the whole point.
      preload: join(__dirname, "preload.js"),
    },
  });

  window.setMenuBarVisibility(false);

  const loadAppUrl = (): void => {
    void window.loadURL(appUrl).catch(() => scheduleReload());
  };

  const scheduleReload = (): void => {
    if (window.isDestroyed()) {
      return;
    }
    setTimeout(() => {
      if (!window.isDestroyed()) {
        loadAppUrl();
      }
    }, RELOAD_DELAY_MS);
  };

  // Auto-recovery: the wrapped UI should come back after a crash, an
  // unresponsive renderer, or a failed load while the local server boots.
  window.webContents.on("render-process-gone", scheduleReload);
  window.webContents.on("unresponsive", scheduleReload);
  window.webContents.on("did-fail-load", (_event, _code, _desc, _url, isMainFrame) => {
    if (isMainFrame) {
      scheduleReload();
    }
  });

  window.once("ready-to-show", () => window.show());
  loadAppUrl();

  return window;
}

async function refreshHealthWindow(window: BrowserWindow): Promise<void> {
  const node = nodeSetting();
  const healthUrl = resolveHealthUrl(process.env, node.url);
  const health = await fetchServerHealth(healthUrl);
  const model = buildHealthPanelModel({
    appUrl: currentAppUrl,
    appVersion: currentMeridianVersion,
    clientVersion: currentMeridianVersion,
    health,
    node,
  });
  const html = renderHealthPanelHtml(model);
  if (!window.isDestroyed()) {
    await window.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`);
  }
}

function toggleHealthWindow(): void {
  if (healthWindow && !healthWindow.isDestroyed()) {
    healthWindow.close();
    return;
  }

  healthWindow = new BrowserWindow({
    width: 520,
    height: 640,
    title: "Meridian On-site Health",
    autoHideMenuBar: true,
    backgroundColor: "#11151c",
    icon: resolveAppIconPath(process.env),
    parent: mainWindow ?? undefined,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  healthWindow.on("closed", () => {
    healthWindow = null;
  });

  void refreshHealthWindow(healthWindow);
}

async function resolveMainAppUrl(): Promise<string> {
  const explicitAppUrl = resolveAppUrlOverride(process.env);
  if (explicitAppUrl !== null) {
    return explicitAppUrl;
  }

  if (!app.isPackaged) {
    return resolveClientDevAppUrl(process.env, "kiosk");
  }

  clientServer = await startClientStaticServer({
    distDir: resolveClientDistPath(process.env),
    port: resolveClientPort(process.env),
  });

  return clientServer.url;
}

/**
 * Show the organization's mark on the window and taskbar icon while this
 * install is locked to an event (BRAND-003A).
 *
 * Best effort, and deliberately so. Every failure path — no node identity, no
 * event, no branding profile, no asset, an unreachable server, bytes Electron
 * cannot decode — leaves Meridian's icon in place, which is both the correct
 * fallback and the state the window was created in. Branding is chrome; it
 * must never be a reason the wrapper fails to start.
 */
async function applyOrganizationWindowIcon(window: BrowserWindow): Promise<void> {
  try {
    const serverUrl = nodeSetting().url;
    const health = await fetchServerHealth(resolveHealthUrl(process.env, serverUrl));
    const organizationId = resolveBrandedOrganizationId(health);

    if (organizationId === null) {
      return;
    }

    const response = await fetch(brandingManifestUrl(serverUrl, organizationId));

    if (!response.ok) {
      return;
    }

    const iconUrl = resolveWindowIconUrl((await response.json()) as BrandingManifest);

    if (iconUrl === null) {
      return;
    }

    const iconResponse = await fetch(iconUrl);

    if (!iconResponse.ok) {
      return;
    }

    const icon = nativeImage.createFromBuffer(
      Buffer.from(await iconResponse.arrayBuffer()),
    );

    if (!icon.isEmpty() && !window.isDestroyed()) {
      window.setIcon(icon);
    }
  } catch {
    // Meridian's icon stays. Nothing about the wrapper depends on this.
  }
}

app.whenReady().then(async () => {
  currentMeridianVersion = resolveClientVersion(process.env);
  currentAppUrl = await resolveMainAppUrl();
  mainWindow = createMainWindow(currentAppUrl);

  void applyOrganizationWindowIcon(mainWindow);

  globalShortcut.register(HEALTH_TOGGLE_SHORTCUT, toggleHealthWindow);

  app.on("activate", () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      mainWindow = createMainWindow(currentAppUrl);
    }
  });
});

app.on("will-quit", () => {
  globalShortcut.unregisterAll();
  if (clientServer) {
    void clientServer.close();
    clientServer = null;
  }
});

app.on("window-all-closed", () => {
  if (process.platform !== "darwin") {
    app.quit();
  }
});
