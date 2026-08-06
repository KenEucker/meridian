# ADR 0001: Shared Vue Client Across Web, Desktop, and Mobile

## Status

Accepted.

## Context

Meridian has server-hosted web, desktop/on-site, and mobile install targets for
the same product codebase. Maintaining separate UI implementations for those
workflows would create avoidable product drift, duplicate accessibility and
offline behavior, and make mobile-first design harder to preserve.

Laravel remains the right fit for backend/API ownership. Orchid remains the
right fit for God Mode / repair tooling. Electron and Capacitor remain the
right fit for packaging and platform capabilities.

## Decision

Meridian Admin, Meridian Field, and Meridian Kiosk product workflows live in one
shared Vue client under `apps/client`.

The shared Vue client produces three fixed artifacts:

- `apps/client/dist/admin` for the server-hosted Meridian Admin web app;
- `apps/client/dist/field` for the Capacitor Meridian Field mobile app;
- `apps/client/dist/kiosk` for the Electron Meridian Kiosk app.

Laravel serves the Admin artifact at `/` and continues to expose APIs and
backend services. Orchid remains God Mode / repair tooling under `/admin`.

`apps/mobile` is a Capacitor packaging project for iOS and Android. It contains
mobile packaging configuration and points at the Field artifact.

`apps/kiosk` is an Electron packaging project. It serves a packaged local
copy of the Kiosk artifact through a tiny local static server and keeps desktop
health/status UI in the Electron shell.

Platform differences belong behind shell/platform adapters. The shared Vue
client owns routes, screens, design system usage, accessibility, offline UX,
and operational workflow parity.

UI mode is fixed by deployment target and must not be inferred from viewport,
device type, touch capability, network state, role, permission, user preference,
or trusted-workstation state.

## Consequences

- Responsive and presentation-profile design is implemented once in `apps/client`.
- Admin, Field, and Kiosk must use the same route and component code where the
  mode-by-surface matrix allows the workflow.
- Electron and Capacitor may add platform capabilities, but they should not
  fork product UI.
- Laravel routes outside `/admin`, `/api`, auth/setup endpoints, and file
  delivery should trend toward serving or supporting Meridian Admin.
- Orchid should not receive normal product workflow UI.
- Version visibility belongs in the shared client's settings/about surface and
  in desktop health.
