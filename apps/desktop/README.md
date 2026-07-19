# Meridian Desktop

The Meridian Electron on-site workstation wrapper for Meridian Kiosk.

The desktop wrapper packages the Kiosk artifact from `apps/client/dist/kiosk`,
serves it through a tiny local static server, opens it in a fullscreen/kiosk
window, and auto-recovers if the wrapped UI crashes. The toggleable health panel
displays server/node placeholders and the packaged client version.

The wrapper intentionally does **not** manage Docker Compose, block accidental
close, or include emergency export in Alpha 1 (technical spec 3.3, 25.1).
Authentication, real node identity, sync state, PowerSync state, connected
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
| `src/config.ts` | Pure resolution of the Kiosk client dist path, server URL, health URL, and client version. |
| `src/health.ts` | Pure health panel model, HTML renderer, and the non-throwing health fetch helper. |
| `src/staticClientServer.ts` | Tiny local static server for the packaged shared Vue client. |
| `src/main.ts` | Electron main process: packaged client serving, kiosk window, auto-recovery, and the toggleable health panel window. |

`src/config.ts` and `src/health.ts` contain all domain logic and are unit
tested. `src/main.ts` is the thin Electron glue, verified by manual desktop QA
(`docs/qa/QA-ELECTRON-01-health-panel.md`).

## Configuration

| Environment variable | Default | Purpose |
|---|---|---|
| `MERIDIAN_CLIENT_DIST_DIR` | `../client/dist/kiosk` from `apps/desktop` | Meridian Kiosk build directory to serve locally. |
| `MERIDIAN_CLIENT_PORT` | `0` | Local static-server port. `0` lets the OS choose. |
| `MERIDIAN_CLIENT_VERSION` | read from `apps/client/package.json` | Optional packaged client version override for health display. |
| `MERIDIAN_SERVER_URL` | `http://localhost:8000/` | Local Laravel server/API URL used to derive health checks. |
| `MERIDIAN_HEALTH_URL` | `<server URL>/api/health` | Optional override for the server health endpoint. |

## Local development

Install workspace dependencies from the repository root:

```bash
corepack pnpm install
```

Run the desktop checks from the repository root via the workspace filter:

```bash
# Type check
corepack pnpm --filter @meridian/desktop run typecheck

# Unit tests (config + health modules)
corepack pnpm --filter @meridian/desktop run test

# Build the Kiosk artifact and compile the Electron main process to dist/
corepack pnpm --filter @meridian/desktop run build
```

The root `build`, `typecheck`, and `test` scripts delegate to this app (and the
mobile app) so the existing process CI runs them automatically.

### Running the wrapper

The Electron binary is a build dependency that pnpm does not download until you
approve it (to keep type-check/test/build lightweight). To run the wrapper
locally:

```bash
# One-time: approve the Electron binary download for this workspace
corepack pnpm approve-builds

# Build the Kiosk artifact and main process, then start the wrapper
corepack pnpm --filter @meridian/desktop run build
corepack pnpm --filter @meridian/desktop run start
```

Press `Ctrl+Shift+H` (`Cmd+Shift+H` on macOS) to toggle the health panel.

Installable packaging (electron-builder), version-mismatch warnings, and the
full live health fields are deferred to later Alpha 1 packaging and sync
milestones.
