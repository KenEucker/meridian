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

In local Laravel development (`APP_ENV=local`), the server product routes load
this Vite dev server by default so changes hot-update when viewing the app
through Laravel. Unpackaged Electron development also loads this Vite dev
server by default. The production build still writes to `apps/client/dist`;
Laravel, packaged Electron, and Capacitor consume that same build output.

Running the Laravel server without the Vite dev server is fine: it confirms the
dev server is answering before it uses it, and serves the last build from
`apps/client/dist/admin` when nothing is. Those responses carry
`X-Meridian-Client-Source: build-fallback`, which is the thing to check when an
edit does not appear — the build is only as new as the last
`corepack pnpm run client:build`. Start Vite and the next reload picks it up.

### Field Report photo upload (local QA)

Photos upload to the Laravel server over `POST /api/commands/*` under the bearer
token this device holds. From the repository root, run:

```bash
corepack pnpm run setup:local
```

The setup script writes ignored `.env.development.local`,
`.env.meridian-admin.local`, `.env.meridian-field.local`, and
`.env.meridian-kiosk.local` files pointing at the local node, and enables the
development Field session fixture so the author surfaces have an event and a
staff record to work with.

It configures no credential: sign in at `/login` as the seeded fixture user
`local-field@meridian.test` and read the login code out of the mail log
(`grep -A 2 "Enter this code" apps/server/storage/logs/laravel.log | tail -1`). The token is stored
under `meridian.api-token.v1` in `localStorage`, alongside this device's
identifier under `meridian.device.id`; Settings reports whether the device is
signed in, and the command outbox holds its work rather than sending it when it
is not.

Restart server and client after changing env files. Submit a Field Report with
photos; detail shows local previews and syncs text then photos. Files land in
`apps/server/storage/app/attachments/field-reports/`. Use **Retry upload** on
detail if the server was offline at submit time.

### Session and cached permissions (local QA)

The client resolves its session from `GET /api/me` at startup and stores the
answer under the `meridian.session.v1` key in `localStorage` (`src/session/`).
That stored copy is what the client boots from when the node cannot be reached,
so a device restarted out of coverage still knows what its user may do.

To exercise it without a server:

1. load the app once against a reachable node so a session is stored;
2. stop the server, or take the device offline, and reload;
3. the shell shows **Permissions are cached** with the time the node last
   answered.

The cached copy stays usable until the active event window of the event the
session resolved to has ended. Past that the shell shows **Permissions need a
refresh** instead and the client grants no capability until a refresh succeeds.
A refresh the node answers with 401 or 403 clears the stored session outright,
because the node was reached and refused the credential.

### Permission-aware navigation (local QA)

Navigation, the department switcher, the name on the user button, and the
department in the shell's context block all come from the session response
(CLIENT-004, CLIENT-005). Nothing renders for a capability the user does not
hold, and a client that has resolved no session renders no navigation at all —
not a reduced menu, none.

`GET /api/me` sits behind `auth:sanctum`, so a shell is populated by signing in
and by nothing else (M18.9; CLIENT-001). There was a
`VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION` switch that installed a development
session document when the node produced none; it is gone, along with the two
fixture modules behind it. A client holding no session shows the sign-in screen.

To see a populated shell locally, seed a node and sign in against it:

```bash
corepack pnpm run setup:local
```

`php artisan meridian:seed-local-field-fixture` seeds a staff member in four
departments, and switching between them in the user menu is the quickest way to
see the permission rules at work:

| Department | Holds | Reaches |
|---|---|---|
| Rangers | department lead, logistics, operations, planning, IC lead, team lead of Dirt | every workflow |
| Organizer | organizer | the organization pages, and no department workflow |
| Gate | staff | their own pages and Gate's member pages |
| DPW | team lead of Bikes | their own pages, DPW's member pages, and Team Overview |

The client's specs stand on the same shape without a server at all
(`src/session/localFieldSessionFixture.ts`, CLIENT-024). Nothing in the
application imports it, and `src/app/fixtureIsolation.spec.ts` walks the module
graph from `main.ts` and `App.vue` to prove it.

### Organization and event context (local QA)

The organization and event the client works in come from the node first
(CLIENT-011). A node locked to an event decides both, and the session response
narrows them to the user's own departments and teams. The branding the shell
paints follows the same answer, so there is no organization identifier anywhere
in the client any more.

Switching lives on two surfaces, `/organizations` and
`/organizations/:organizationId/events`, reached from the user menu and from
Home. They are not in the top bar: the top bar must not be the organization or
event switcher (UI implementation contract 4.3, 6.1).

Switching is connected-only and is offered only by a node with no event lock:

| State | What the client shows |
|---|---|
| Node locked to an event | No switcher, and a line saying the node runs that event and holds no other event's records |
| Offline, or running on cached permissions | No switcher, and a line saying switching needs the node |
| One organization and one event | Nothing at all — there is nothing to switch to |
| Connected, more than one association | Both surfaces, listing only associations the session response carries |

The seeded development node is locked to its one event, which is the right shape
for an on-site node and the wrong shape for exercising a switcher. The specs
build the unlocked shape through `switchableLocalFieldContext()` in
`src/session/localFieldSessionFixture.ts`; to see it in the browser, point the
client at a node whose `nodes` row carries no `event_id`.

A switch that lands re-resolves permissions, navigation, branding, and the
durable session copy, and drops what belonged to the context being left — the
department selection and the previous event's Field Reports. Reports still
pending sync are kept whatever event they belong to: they are unsent work this
device is the only copy of.

## Routes

Domain routes use the UI Implementation Contract route inventory and are shared
by Admin, Field, and Kiosk shells where the mode-by-surface matrix allows them.
