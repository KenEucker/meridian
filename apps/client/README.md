# Meridian Client

The shared Meridian Vue product client.

`apps/client` owns Meridian Admin, Meridian Field, and Meridian Kiosk product
workflows from one Vue codebase. It builds three fixed artifacts:

- `dist/admin` for the server-hosted Meridian Admin web application;
- `dist/field` for the Capacitor Meridian Field mobile application;
- `dist/kiosk` for the Electron Meridian Kiosk on-site application.

Laravel serves the Admin artifact at `/`; Orchid remains God Mode / repair
tooling under `/admin`. Electron packages and serves the Kiosk artifact through
a tiny local static server. Capacitor packages the Field artifact for iOS and
Android from `apps/mobile`.

## Source references

- [ADR 0001: Shared Vue Client Across Web, Desktop, and Mobile](../../docs/adr/0001-shared-vue-client.md)
- Technical spec sections 3.1 through 3.4.
- UI Implementation Contract sections 3, 4, 5, and 12.

## Stack

- Vue 3 (`vue`, `vue-router`).
- Vite 8 build tooling with `@vitejs/plugin-vue`.
- TypeScript with `vue-tsc` for type checking.
- Vitest with `@vue/test-utils` and `jsdom` for component and workflow tests.
- Shared semantic UI tokens from `@meridian/ui-tokens`.

## Local development

Install workspace dependencies from the repository root:

```bash
corepack pnpm install
```

Run client commands from the repository root:

```bash
# Start the shared client dev server in Field mode
corepack pnpm run client:dev

# Preview the other fixed modes explicitly
corepack pnpm run client:dev:admin
corepack pnpm run client:dev:kiosk

# Type check
corepack pnpm run client:typecheck

# Production build for all fixed artifacts
corepack pnpm run client:build

# Test
corepack pnpm run client:test
```

The default Vite dev server previews Meridian Field because local browser
development usually exercises mobile/offline field workflows. Meridian Admin
and Meridian Kiosk previews are explicit commands; packaged deployments still
use their fixed build artifacts.

The production build writes to `apps/client/dist/admin`,
`apps/client/dist/field`, and `apps/client/dist/kiosk`.

### Field Report photo upload (local QA)

Photos upload to the Laravel server over `POST /api/commands/*` when local Field
API auth is enabled:

```bash
# apps/server/.env
MERIDIAN_LOCAL_FIELD_API_ENABLED=true
MERIDIAN_LOCAL_FIELD_API_TOKEN=local-field-dev-token

cd apps/server && php artisan meridian:seed-local-field-fixture

# apps/client - match the token
cp apps/client/.env.development.example apps/client/.env.development.local
```

Restart server and client. Submit a Field Report with photos; detail shows local
previews and syncs text then photos. Files land in
`apps/server/storage/app/attachments/field-reports/`. Use **Retry upload** on
detail if the server was offline at submit time.

## Routes

Domain routes use the UI Implementation Contract route inventory and are shared
by Admin, Field, and Kiosk shells where the mode-by-surface matrix allows them.
