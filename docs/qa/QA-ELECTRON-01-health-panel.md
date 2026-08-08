# QA-ELECTRON-01: Electron Wrapper Opens Local UI and Shows Health Panel

## Purpose

Verify that the Meridian Electron on-site wrapper (M2.3/M2.6) opens the shared
Meridian Kiosk UI in a fullscreen/kiosk window, uses the Vite dev server for
unpackaged development, and auto-recovers if the wrapped UI is not yet
available. Verify that the health panel (M2.4) displays the technical spec 25.3
fields with node/server version placeholders read from the server health
endpoint. No authentication, real sync state, or product workflows are expected
at this stage.

## Requirements covered

- Technical spec: Section 3.4 Desktop on-site wrapper
- Technical spec: Section 25.1 Purpose
- Technical spec: Section 25.2 Distribution
- Technical spec: Section 25.3 Health panel
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
  client version, and Electron wrapper version.
- "Server version" shows the running server version and "Node role" shows the
  server environment while connected; "Client version" and "Electron wrapper
  version" both show the root `package.json` Meridian version; "Certificate /
  HTTPS status" reflects the configured URL scheme.
- Fields owned by later milestones (local node name, event name, sync status,
  offline read set status, connected devices, local discovery status) show a clearly
  labeled placeholder.
- When the server is stopped, server-sourced fields show "Unavailable" and the
  wrapped UI does not block; when the server returns, the wrapped UI reloads
  automatically.

## Evidence to capture

- Terminal output from the unit tests, type check, and build.
- A screenshot of the wrapped Meridian web UI in the fullscreen/kiosk window.
- A screenshot of the health panel while the server is reachable.
- A screenshot of the health panel while the server is unreachable.

## Failure notes

Record the failed step, exact error text or unexpected panel value, operating
system, Node.js and pnpm versions, the configured `MERIDIAN_SERVER_URL`, whether
the local server health endpoint was reachable, and whether the Electron binary
build was approved.
