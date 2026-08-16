# Meridian Runtipi Distribution

This is the store-side half of the Runtipi distribution path (M19.29;
technical spec 26.7; `deploy/runtipi/README.md` is the agreed design): from a
tagged Meridian release to an app a Runtipi user installs by pasting one URL,
and the update path that keeps the published app pointed at the code. The
install itself is exercised and evidenced through
[`QA-RUNTIPI-01`](../qa/QA-RUNTIPI-01-runtipi-app-install.md), which follows
this document; a step that cannot be completed without asking its author is a
documentation failure to record there.

The official Runtipi app store is closed to new applications, so publishing
Meridian means publishing a Meridian-owned store a user adds by URL — that is
the designed channel, not a fallback (`deploy/runtipi/README.md`, Path B).

## What ships, and from where

A Runtipi app store is a public git repository of app directories. Meridian's
app directory is **generated, never hand-maintained**:

```bash
corepack pnpm run runtipi:app --out <store-checkout>/apps/meridian
```

`scripts/deploy/build-runtipi-app.mjs` derives the app version, the
`tipi_version` release counter, and both image tags from the root
`package.json`, so a published app cannot pin an image tag the code has moved
past. It writes `config.json`, `docker-compose.yml`,
`metadata/description.md`, and copies `deploy/runtipi/metadata/logo.jpg`. The
images the app pulls are the ones the tagged release workflow published to
GHCR (M19.26, amd64 only) — so the version being generated **must be a tagged
release whose workflow has completed**, or the install will fail at pull.

## Credentials

A GitHub account with permission to create and push the
`KenEucker/meridian-appstore` repository. Nothing else: the store repository
is public (Runtipi clones it anonymously), the images are public GHCR
packages, and no signing is involved.

## Creating the store repository, once

1. Create `KenEucker/meridian-appstore` from the
   [`runtipi/example-appstore`](https://github.com/runtipi/example-appstore)
   template, **public** — "custom store" means "not the official store", not
   "not published". Keep the template's `apps/` layout and top-level
   `schema.json`; remove its example app.
2. Clone it, and generate the Meridian app into it:

   ```bash
   corepack pnpm run runtipi:app --out ../meridian-appstore/apps/meridian
   ```

   The directory name `meridian` must equal the `id` in `config.json`;
   the generator's output assumes exactly that layout.
3. Commit and push. The commit message should name the Meridian version the
   app was generated from, because the store repository's history is the
   record of what was published when.
4. On a Runtipi host: **Settings → App Stores → Add App Store**, paste the
   repository URL. The Meridian app appears in the store list on amd64 hosts
   and is hidden on ARM hosts by the `supported_architectures` declaration.

The store lives in its own repository rather than in this monorepo because
Runtipi clones the whole store repository on every refresh, and this monorepo
is large (`deploy/runtipi/README.md` records the decision).

## Updating the app on a Meridian release

On every release that should reach Runtipi users, after the tag's release
workflow has completed:

1. Pull the tagged commit, and regenerate into a current store checkout:

   ```bash
   git checkout v<version>
   corepack pnpm run runtipi:app --out ../meridian-appstore/apps/meridian
   ```

2. Review the diff — it should be the version and `tipi_version` lines and
   whatever the release genuinely changed, and nothing hand-edited, because
   the next regeneration would silently discard hand edits.
3. Commit and push. Runtipi refreshes its stores on a schedule and offers the
   update when it sees the higher `tipi_version`.

A Runtipi **Update** click is also a schema migration: the entrypoint runs
migrations automatically at start (technical spec 26.2). The generated
description tells the user to back up first; nothing about this path makes
that warning optional.

## Installing without a store

Runtipi's dashboard also accepts a Compose file directly ("Add custom app"),
which is the fastest way to get a Meridian node onto a Runtipi box for QA
before the store repository exists:

```bash
corepack pnpm run runtipi:app --print docker-compose.yml
```

Paste the output, fill the environment in Runtipi's own editor with the same
variables the store form would have collected (the `${...}` names in the
file), and expose the app on a domain — an unexposed install answers 503 to
everything, by design (technical spec 8.2).

## What the first real install must settle

`deploy/runtipi/README.md` carries two open questions drawn from Runtipi's
documentation rather than a running instance: what the `random` form field
actually generates, and whether Traefik passes the forwarded scheme through.
The first QA-RUNTIPI-01 run records both answers, with the Runtipi version
they were observed on, back into that document. Until that run has happened,
this distribution path has not been exercised end to end and the store should
not be announced anywhere a stranger would find it.
