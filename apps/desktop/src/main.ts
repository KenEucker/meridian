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

import { app, BrowserWindow, globalShortcut } from "electron";

import {
  resolveClientDistPath,
  resolveClientDevAppUrl,
  resolveClientPort,
  resolveClientVersion,
  resolveHealthUrl,
  resolveAppIconPath,
  resolveAppUrlOverride,
  resolveServerUrl,
} from "./config";
import { buildHealthPanelModel, fetchServerHealth, renderHealthPanelHtml } from "./health";
import { startClientStaticServer, type ClientStaticServer } from "./staticClientServer";

const RELOAD_DELAY_MS = 2000;
const HEALTH_TOGGLE_SHORTCUT = "CommandOrControl+Shift+H";

let mainWindow: BrowserWindow | null = null;
let healthWindow: BrowserWindow | null = null;
let clientServer: ClientStaticServer | null = null;
let currentAppUrl = "";
let currentMeridianVersion = "unknown";

function createMainWindow(appUrl: string): BrowserWindow {
  const window = new BrowserWindow({
    show: false,
    fullscreen: true,
    kiosk: true,
    autoHideMenuBar: true,
    backgroundColor: "#11151c",
    icon: resolveAppIconPath(process.env),
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
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
  const serverUrl = resolveServerUrl(process.env);
  const healthUrl = resolveHealthUrl(process.env, serverUrl);
  const health = await fetchServerHealth(healthUrl);
  const model = buildHealthPanelModel({
    appUrl: currentAppUrl,
    appVersion: currentMeridianVersion,
    clientVersion: currentMeridianVersion,
    health,
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

app.whenReady().then(async () => {
  currentMeridianVersion = resolveClientVersion(process.env);
  currentAppUrl = await resolveMainAppUrl();
  mainWindow = createMainWindow(currentAppUrl);

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
