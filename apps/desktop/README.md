# Meridian Desktop

The Meridian Electron on-site workstation wrapper.

`M2.3` adds the Electron wrapper shell that opens the local Meridian web UI in
a fullscreen/kiosk window and auto-recovers if the wrapped UI crashes or has
not loaded yet. `M2.4` adds a toggleable health panel that displays the
node/server version placeholders described by technical spec 25.3, reading the
values it can from the server health endpoint.

The wrapper intentionally does **not** manage Docker Compose, block accidental
close, or include emergency export in Alpha 1 (technical spec 3.3, 25.1).
Authentication, real node identity, sync state, PowerSync state, connected
devices, and version-mismatch warnings arrive with their owning Alpha 1
milestones; the health panel shows them as clearly labeled placeholders for now.

## Source references

- Technical spec section 3.3 Desktop on-site wrapper.
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
| `src/config.ts` | Pure resolution of the local Meridian web UI URL and health URL from environment variables. |
| `src/health.ts` | Pure health panel model, HTML renderer, and the non-throwing health fetch helper. |
| `src/main.ts` | Electron main process: kiosk window, auto-recovery, and the toggleable health panel window. |

`src/config.ts` and `src/health.ts` contain all domain logic and are unit
tested. `src/main.ts` is the thin Electron glue, verified by manual desktop QA
(`docs/qa/QA-ELECTRON-01-health-panel.md`).

## Configuration

| Environment variable | Default | Purpose |
|---|---|---|
| `MERIDIAN_APP_URL` | `http://localhost:8000/` | Local Meridian web UI the wrapper opens. Must be `http`/`https`. |
| `MERIDIAN_HEALTH_URL` | `<app URL>/api/health` | Optional override for the server health endpoint. |

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

# Compile the Electron main process to dist/
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

# Build the main process and start the wrapper against a running local server
corepack pnpm --filter @meridian/desktop run build
MERIDIAN_APP_URL="http://localhost:8000/" \
  corepack pnpm --filter @meridian/desktop run start
```

Press `Ctrl+Shift+H` (`Cmd+Shift+H` on macOS) to toggle the health panel.

Installable packaging (electron-builder), version-mismatch warnings, and the
full live health fields are deferred to later Alpha 1 packaging and sync
milestones.
