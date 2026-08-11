# Meridian Desktop

The Meridian Electron on-site workstation wrapper for Meridian Kiosk.

The desktop wrapper opens the Kiosk artifact in a fullscreen/kiosk window and
auto-recovers if the wrapped UI crashes. In unpackaged development it loads the
shared Vue Vite dev server so UI changes hot-update immediately; packaged builds
serve `apps/client/dist/kiosk` through a tiny local static server. The toggleable
health panel displays server/node placeholders and the root Meridian version.

The wrapper intentionally does **not** manage Docker Compose, block accidental
close, or include emergency export in Alpha 1 (technical spec 3.3, 25.1).
Authentication, real node identity, sync state, offline read set state, connected
devices, and version-mismatch warnings arrive with their owning Alpha 1
milestones; the health panel shows them as clearly labeled placeholders for now.

## Source references

- [ADR 0001: Shared Vue Client Across Web, Desktop, and Mobile](../../docs/adr/0001-shared-vue-client.md)
- Technical spec section 3.4 Desktop on-site wrapper.
- Technical spec section 25.1 Purpose.
- Technical spec section 25.2 Distribution.
- Technical spec section 25.3 Health panel.
- Kiosk and field hardware UX guide section 12 (Hardware-Aware Interaction)
  and section 13 (Night Operations).

## Stack

- Electron 42 (`electron`) for the installable on-site wrapper.
- TypeScript compiled to CommonJS for the Electron main process.
- Vitest for unit tests of the pure configuration and health modules.

These versions follow `docs/meridian-technology-baseline.md`.

## Layout

| File | Purpose |
|---|---|
| `src/config.ts` | Pure resolution of the Kiosk client dist path, server URL, health URL, and root Meridian version. |
| `src/health.ts` | Pure health panel model, HTML renderer, and the non-throwing health fetch helper. |
| `src/staticClientServer.ts` | Tiny local static server for the packaged shared Vue client. |
| `src/desktopRuntimeConfig.ts` | Pure build of what the wrapper tells the Kiosk about itself: the trusted shared workstation (M18.32) and the wrapper's own version (M19.1). |
| `src/preload.ts` | Hands that runtime config to the Kiosk before any page script runs. |
| `src/main.ts` | Electron main process: dev-server or packaged client loading, kiosk window, auto-recovery, and the toggleable health panel window. |

`src/config.ts` and `src/health.ts` contain all domain logic and are unit
tested. `src/main.ts` is the thin Electron glue, verified by manual desktop QA
(`docs/qa/QA-ELECTRON-01-health-panel.md`).

## Configuration

| Environment variable | Default | Purpose |
|---|---|---|
| `MERIDIAN_CLIENT_DIST_DIR` | `../client/dist/kiosk` from `apps/kiosk` | Meridian Kiosk build directory to serve locally. |
| `MERIDIAN_CLIENT_PORT` | `0` | Local static-server port. `0` lets the OS choose. |
| `MERIDIAN_CLIENT_DEV_SERVER_URL` | `http://localhost:5173/` | Shared Vue Vite dev server opened by unpackaged Electron. |
| `MERIDIAN_APP_URL` | unset | Optional override that skips both the development URL default and packaged static server. |
| `MERIDIAN_SERVER_URL` | `http://localhost:8000/` | Local Laravel server/API URL used to derive health checks. |
| `MERIDIAN_HEALTH_URL` | `<server URL>/api/health` | Optional override for the server health endpoint. |
| `MERIDIAN_SHARED_WORKSTATION_ID` | unset | The trusted shared workstation this machine is (M18.32; technical spec 13.1). Handed to the Kiosk through the preload so an installed workstation comes up knowing what it is. Not a credential: signing in still needs a login code the node issued to a named person (AUTH-030). |
| `MERIDIAN_DESKTOP_WINDOWED` | unset (fullscreen kiosk) | Open in a resizable window instead. For working on the surfaces; a workstation is fullscreen and locked down. |

The displayed client and Electron wrapper versions both come from the root
`package.json` Meridian version.

The wrapper also hands its own version to the Kiosk client through the preload,
as `desktopAppVersion` on `window.__MERIDIAN_RUNTIME_CONFIG__`, so the Settings
screen can show the desktop app version beside the bundle and server versions
(M19.1; technical spec 26.3). The main process passes it to the preload as a
renderer argument rather than an environment variable, because it is resolved
at startup rather than known when the process was spawned. It is not a
credential; the health panel prints the same value.

## Local development

Install workspace dependencies from the repository root:

```bash
corepack pnpm install
```

Run the desktop checks from the repository root via the workspace filter:

```bash
# Type check
corepack pnpm --filter @meridian/kiosk run typecheck

# Unit tests (config + health modules)
corepack pnpm --filter @meridian/kiosk run test

# Build the Kiosk artifact and compile the Electron main process to dist/
corepack pnpm --filter @meridian/kiosk run build
```

The root `build`, `typecheck`, and `test` scripts delegate to this app (and the
mobile app) so the existing process CI runs them automatically.

### Running it as a shared workstation

A Kiosk cannot be exercised without a trusted workstation row, a pinned
organization and event, and a login code in somebody's hand — and testing the
switch-user handover needs two of the last. One command from the repository root
does all of it and opens the desktop app against it:

```bash
corepack pnpm run kiosk:workstation
```

It migrates the node, provisions (or reuses) a trusted workstation pinned to the
node's event, issues a login code per user, starts the Kiosk dev server on its
own port, and opens the wrapper. The codes are printed once, exactly as the God
Mode screen prints them.

Everything it learns goes in `apps/kiosk/.env.shared-workstation`, which is
gitignored and survives the next run; `.env.shared-workstation.example` documents
what belongs there. Arguments pass through to the artisan command underneath, so
`--department=Rangers`, `--code-for=someone@example.test`, and `--name=` all
work.

### Running the wrapper

The Electron binary is a build dependency that pnpm does not download until you
approve it (to keep type-check/test/build lightweight). To run the wrapper
locally:

```bash
# One-time: approve the Electron binary download for this workspace
corepack pnpm approve-builds

# Terminal 1: start the shared Vue dev server
corepack pnpm run client:dev

# Terminal 2: compile the main process, then start the wrapper
corepack pnpm --filter @meridian/kiosk run start
```

When launched from the workspace with `electron .`, the unpackaged wrapper
opens `MERIDIAN_CLIENT_DEV_SERVER_URL` with the Kiosk runtime mode requested, so
Vue changes hot-update in the desktop shell without inheriting the Field mode
from the shared Vite process. Packaged builds still serve `apps/client/dist`
through the local static server.

Press `Ctrl+Shift+H` (`Cmd+Shift+H` on macOS) to toggle the health panel.

Installable packaging (electron-builder), version-mismatch warnings, and the
full live health fields are deferred to later Alpha 1 packaging and sync
milestones.
