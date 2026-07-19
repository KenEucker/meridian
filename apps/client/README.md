# Meridian Client

The shared Meridian Vue product client.

`apps/client` owns all non-admin user-facing operational workflows across
web/PWA, Electron desktop/kiosk, and Capacitor mobile packaging. It is built
mobile-first and progressively enhances larger viewports.

Laravel serves this built client at `/`; Orchid remains the admin and god-mode
surface under `/admin`. Electron packages and serves this build through a tiny
local static server. Capacitor packages this build for iOS and Android from
`apps/mobile`.

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
# Start the shared client dev server
corepack pnpm run client:dev

# Type check
corepack pnpm run client:typecheck

# Production build
corepack pnpm run client:build

# Test
corepack pnpm run client:test
```

The production build writes to `apps/client/dist`. Laravel, Electron, and
Capacitor consume that same build output.

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
by web, desktop, and mobile shells.
