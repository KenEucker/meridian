# Meridian Mobile

The Meridian Vue field/mobile application shell.

The `M2.1` app shell is a Vue 3 + Vite application with placeholder routing and
no domain workflows. `M2.2` adds the Capacitor baseline: a `capacitor.config.ts`
project configuration and a documented local run path. Shared semantic UI
tokens (`M2.5`), authentication, offline/PowerSync state, and product screens
arrive with their owning Alpha 1 milestones.

Native iOS/Android platform projects are intentionally not added yet. Alpha 1
does not require running on a physical phone, and native app-store distribution
is an explicit Alpha 1 exclusion. The browser/PWA experience remains the working
client for MVP-critical workflows, and `capacitor.config.ts` is ready for native
platforms to be added later.

## Source references

- Technical spec section 3.2 Mobile/field application.
- Technical spec sections 8.2 and 8.4 (installed Capacitor app as the reliable
  on-site/offline client).
- UI Implementation Contract sections 3 (Alpha 1 Technical Contract), 4 (Global
  UI Rules), and 12 (Route and Screen Inventory).

## Stack

- Vue 3 (`vue`, `vue-router`).
- Vite 8 build tooling with `@vitejs/plugin-vue`.
- Capacitor 8 (`@capacitor/core`, `@capacitor/cli`) for installable mobile
  packaging.
- TypeScript with `vue-tsc` for type checking.
- Vitest with `@vue/test-utils` and `jsdom` for the smoke and config tests.

These versions follow `docs/meridian-technology-baseline.md`.

## Local development

Install workspace dependencies from the repository root:

```bash
corepack pnpm install
```

### Field Report photo upload (local QA)

Photos upload to the Laravel server over `POST /api/commands/*` when local Field
API auth is enabled:

```bash
# apps/server/.env
MERIDIAN_LOCAL_FIELD_API_ENABLED=true
MERIDIAN_LOCAL_FIELD_API_TOKEN=local-field-dev-token

cd apps/server && php artisan meridian:seed-local-field-fixture

# apps/mobile — match the token
cp apps/mobile/.env.development.example apps/mobile/.env.development.local
```

Restart server and mobile. Submit a Field Report with photos; detail shows local
previews and syncs text then photos. Files land in
`apps/server/storage/app/attachments/field-reports/`. Use **Retry upload** on
detail if the server was offline at submit time.

Run the field app commands from the repository root via the workspace filter:

```bash
# Start the dev server
corepack pnpm --filter @meridian/mobile run dev

# Type check
corepack pnpm --filter @meridian/mobile run typecheck

# Production build
corepack pnpm --filter @meridian/mobile run build

# Smoke test
corepack pnpm --filter @meridian/mobile run test
```

The root `build`, `typecheck`, and `test` scripts delegate to this app so the
existing process CI runs them automatically.

## Capacitor

The Capacitor baseline configuration lives in `capacitor.config.ts`:

| Field | Value | Purpose |
|---|---|---|
| `appId` | `org.meridian.field` | Reverse-domain native application identifier |
| `appName` | `Meridian Field` | Installed application display name |
| `webDir` | `dist` | Web assets the Capacitor CLI copies into native platforms (the Vite build output) |

### Config validation

The configuration is validated automatically by the Vitest config test
(`src/capacitor.config.spec.ts`), which runs as part of `run test`. To validate
the build-and-config path locally:

```bash
# Build the web assets that Capacitor packages
corepack pnpm --filter @meridian/mobile run build

# Validate config and shell behavior
corepack pnpm --filter @meridian/mobile run test
```

You can also confirm the Capacitor CLI reads the configuration:

```bash
# Lists configured native platforms (none yet) without modifying anything
corepack pnpm --filter @meridian/mobile exec cap ls
```

### Adding native platforms later

Native platform projects are not committed yet. When a later milestone needs
installable native builds, add a platform and sync the built web assets:

```bash
# One-time, per platform (requires platform SDKs installed locally)
corepack pnpm --filter @meridian/mobile exec cap add android
corepack pnpm --filter @meridian/mobile exec cap add ios

# After each web build, copy assets and update native dependencies
corepack pnpm --filter @meridian/mobile run build
corepack pnpm --filter @meridian/mobile run cap:sync
```

`cap:sync` runs `cap sync`, and `cap:copy` runs `cap copy`. Both require at
least one native platform to be added first.

## Routes

| Route name | Path | Purpose |
|---|---|---|
| `home` | `/` | Placeholder field home surface |
| `not-found` | catch-all | Placeholder not-found surface |

Domain routes from the UI Implementation Contract route inventory are added
with their owning milestones.
