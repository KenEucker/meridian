# @meridian/ui-tokens

Shared semantic UI design tokens for Meridian field and admin surfaces (task
`M2.5` "Shared UI tokens baseline").

This package is the single source of truth for the semantic `--m-*` CSS custom
properties defined in `docs/ui/meridian-ui-implementation-contract.md`
section 10. Components consume these semantic tokens instead of raw brand hex
values, per `docs/ui/meridian-component-library-specification.md` section 3.

## What is included

- `tokens.css` — light `:root` baseline plus dark-mode values (automatic via
  `prefers-color-scheme`, or forced with `data-theme="dark"` / `data-theme="light"`).

Values trace to `docs/ui/meridian-style-guide.md` (color section 4, typography
section 5, spacing/radius section 10, status section 11, accessibility
section 13). Brand colors and status/severity colors are kept distinct.

## Consuming the tokens

### Field app (Vue / Capacitor)

```ts
import "@meridian/ui-tokens/tokens.css";
```

Then reference tokens in component CSS, e.g. `color: var(--m-text-primary);`.

### Admin surface (Laravel / Orchid)

The Laravel server serves a mirror of this file at
`apps/server/public/css/meridian-tokens.css`, registered as an Orchid
stylesheet resource so the admin panel exposes the same tokens.

The mirror copy is generated, not hand-edited:

```bash
pnpm run tokens:sync
```

The `@meridian/ui-tokens` smoke test fails if the package file and the server
mirror drift apart.

## Scope

This is a token skeleton baseline. Component implementations, per-department
accent palettes, and a user-facing theme toggle are out of scope and arrive
with later Alpha 1 milestones.
