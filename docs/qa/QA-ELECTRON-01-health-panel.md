# QA-ELECTRON-01: Electron Wrapper Opens Local UI and Shows Health Panel

## Purpose

Verify that the Meridian Electron on-site wrapper (M2.3/M2.6) opens the shared
Meridian Kiosk UI in a fullscreen/kiosk window, uses the Vite dev server for
unpackaged development, and auto-recovers if the wrapped UI is not yet
available. Verify that the finalized health panel (M19.5) displays every
technical spec 25.3 field with real values read from the server health
endpoint — node name and role, event name, sync status, offline read set
status, connected devices, local discovery, certificate/HTTPS status, and
versions — and warns on sync failures, open sync conflicts, an unservable
offline read set in event mode, and a server/app version mismatch.

## Requirements covered

- Technical spec: Section 3.4 Desktop on-site wrapper
- Technical spec: Section 25.1 Purpose
- Technical spec: Section 25.2 Distribution
- Technical spec: Section 25.3 Health panel
- Technical spec: Section 26.3 Versioning
- Kiosk and field hardware UX guide: Section 12 Hardware-Aware Interaction

## Environment

- Fresh local checkout.
- Development environment.
- Node.js 24 LTS and pnpm 11.x available via Corepack.
- A local HTTP server reachable at `MERIDIAN_SERVER_URL` that exposes
  `GET /api/health` (for example, the `apps/server` Laravel app running
  `php artisan serve`, which serves the health endpoint at
  `http://localhost:8000/api/health`).
- The shared Vue Vite dev server running in Kiosk mode for unpackaged Electron
  development.

## Personas

- Developer
- Human reviewer

## Setup data

- No seed data is required. The wrapper has no authentication or domain data.
- The health endpoint returns non-sensitive status and version metadata only.

## Steps

1. From the repository root, install workspace dependencies:
   `corepack pnpm install`.
2. Run the automated unit tests:
   `corepack pnpm --filter @meridian/kiosk run test`.
3. Type check and build the Electron main process:
   `corepack pnpm --filter @meridian/kiosk run typecheck` and
   `corepack pnpm --filter @meridian/kiosk run build`.
4. Approve the Electron binary download once for this workspace:
   `corepack pnpm approve-builds` (select `electron`).
5. Start a local server that serves `GET /api/health` (for example, run
   `apps/server` with `php artisan serve`).
6. Start the shared client dev server in Kiosk mode:
   `corepack pnpm run client:dev:kiosk -- --host 127.0.0.1`.
7. Start the wrapper:
   `MERIDIAN_SERVER_URL="http://localhost:8000/" MERIDIAN_CLIENT_DEV_SERVER_URL="http://127.0.0.1:5173/" corepack pnpm --filter @meridian/kiosk run start`.
8. Confirm the wrapper opens the development Meridian Kiosk UI in a
   fullscreen/kiosk window with no browser chrome or menu bar.
9. Make a temporary visible Vue text or style change and confirm the Electron
   window hot-updates without rebuilding the desktop app.
10. Press `Ctrl+Shift+H` (`Cmd+Shift+H` on macOS) to open the health panel.
11. Stop the local server, press the shortcut to refresh/reopen the health
   panel, and confirm server-sourced fields show as unavailable while the
   wrapper itself keeps running.
12. Restart the Laravel server and confirm the health panel returns to reachable
    server-sourced values without manually restarting the wrapper.
13. In the wrapped Kiosk UI, open **Settings** and read the **Versions**
    section. Confirm it names the app, the desktop app version, the client
    bundle version, the UI mode, the deployment target, the server version, and
    the config schema version (M19.1; technical spec 26.3).

## Expected results

- `corepack pnpm install` completes without errors.
- The unit tests pass (config and health modules).
- `typecheck` and `build` complete without errors; `build` produces
  `apps/kiosk/dist/main.js`.
- The wrapper opens the development Meridian Kiosk UI from Vite fullscreen/kiosk
  with no browser chrome, and Vue changes hot-update in the Electron window.
- The health panel lists all technical spec 25.3 fields in order: local node
  name, node role, event name, sync status, offline read set status, connected
  devices, local discovery status, certificate/HTTPS status, server version,
  config schema version, client version, and Electron wrapper version.
- "Server version" shows the running server version, "Config schema version"
  shows the node's configuration schema version (technical spec 26.3), and
  "Node role" shows the configured node's role while connected; "Client version"
  and "Electron wrapper version" both show the root `package.json` Meridian
  version; "Certificate / HTTPS status" reflects the configured URL scheme.
- Step 13: the Settings Versions section shows "Desktop app version" with the
  same value the panel's "Electron wrapper version" shows, because the wrapper
  states it to the client rather than the client assuming it; "Server version"
  and "Config schema version" match the panel; the mobile app version row is
  absent, since this is not the installed mobile app.
- With a configured node: "Local node name" shows the technician-given node
  name, "Event name" shows the locked event's name (or "No event locked to this
  node"), "Sync status" summarizes the node sync state, "Offline read set
  status" says whether the node can serve the device cache set, "Connected
  devices" counts devices seen in the last 15 minutes, and "Local discovery
  status" describes the resolved node URL (mDNS `.local` name, direct IP, DNS
  name, or local machine). On an install with no configured node, the identity
  fields state "No node configured" rather than a placeholder.
- When node sync has failures or open conflicts, when an event-mode node cannot
  serve the offline read set, or when the server version differs from the
  wrapper's expected app version, an amber warning list renders above the field
  table; the panel refreshes every 15 seconds while open, so the warnings track
  the server without reopening the panel.
- When the server is stopped, server-sourced fields show "Unavailable" and the
  wrapped UI does not block; when the server returns, the wrapped UI reloads
  automatically.

## Evidence to capture

- Terminal output from the unit tests, type check, and build.
- A screenshot of the wrapped Meridian web UI in the fullscreen/kiosk window.
- A screenshot of the health panel while the server is reachable.
- A screenshot of the health panel while the server is unreachable.
- A screenshot of the Settings Versions section inside the wrapped Kiosk UI.

## Failure notes

Record the failed step, exact error text or unexpected panel value, operating
system, Node.js and pnpm versions, the configured `MERIDIAN_SERVER_URL`, whether
the local server health endpoint was reachable, and whether the Electron binary
build was approved.
