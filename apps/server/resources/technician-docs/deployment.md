# Deployment

How to stand up a Meridian server.

## What a deployment is made of

| Piece | What it does |
|---|---|
| Laravel server | The API, Meridian Admin, and the God Mode console. |
| PostgreSQL | All Meridian data. |
| Caddy | TLS termination and reverse proxy. |
| Shared Vue client | Meridian Admin, Field, and Kiosk builds served by the server. |

Offline-capable devices are fed by the Laravel server itself. There is no
separate sync service to run: a device fetches its offline read set from the
node it is pointed at, and queues its writes back through the same node.

Two more containers run beside those, and a deployment without them is quietly
broken rather than obviously broken:

| Piece | What it does |
|---|---|
| Queue worker | Sends the transactional mail — magic-link logins, operational notifications. Without it, mail is written to the queue and never delivered. |
| Scheduler | Runs node-to-node sync every minute, the diagnostics heartbeat, health reports, and the daily staff lifecycle pass. Without it, an on-site node never pushes back to central. |

The deployment configuration bundle lives under `deploy/`: `deploy/docker` for the
images and Compose stack, `deploy/caddy` for the reverse proxy, and `deploy/dns`
for name resolution on the event network. `deploy/README.md` is its index.

## Roles a deployment can take

Choose the role before you start, because it changes what the node is allowed to
do:

- **development** — a workstation. Event-mode safeguards are off.
- **standalone** — one node running everything, no central peer. May be paired
  with a central node later.
- **central** — holds configuration and organization governance. Authoritative
  outside the active event window.
- **onsite** — runs the event. Authoritative for event-scoped writes during the
  active event window, and queues operations when the internet is gone.

## Bring up a node

The whole stack is one Compose install, and the node's role is configuration
rather than a different stack.

The order below matters in two places, and both are the same reason: a step needs
something an earlier step produces. Do them in order the first time.

1. Copy the sample configuration. Every value in it is fake on purpose, and the
   node will not start until the ones marked `CHANGE ME` are real.

   ```bash
   cp deploy/docker/.env.deployment.example deploy/docker/.env.deployment
   ```

2. Build the images, then put the version they were tagged with into
   `MERIDIAN_IMAGE_TAG` in the file you just copied. Nothing else in this list
   works until that variable is set: the Compose file requires it, by design, so a
   node cannot run an image nobody named.

   ```bash
   corepack pnpm run deploy:build
   corepack pnpm run deploy:tag     # prints the same value, if you need it again
   ```

3. Generate the application key and set `APP_KEY` to what it prints. This needs
   the image from step 2, which is why it is not part of step 1.

   ```bash
   docker compose --env-file deploy/docker/.env.deployment \
     -f deploy/docker/compose.deployment.yaml \
     run --rm server php artisan key:generate --show
   ```

   Generate one **per node** and never copy it between them. It protects
   sessions, signed URLs, encrypted values, and the configuration override store.

4. Fill in the rest of the `CHANGE ME` values: the node role, `APP_URL` and
   `MERIDIAN_SITE_ADDRESS` (the same hostname, and the one on the certificate),
   `DB_PASSWORD`, and the mail credentials. Mail is not optional — magic-link
   login is how people sign in, so a node that cannot send mail is a node nobody
   can log in to.

5. Decide how the node gets its certificate, because the browsers on the event
   network have to actually trust it. Event mode requires HTTPS and fails closed
   without it.

   - Internet-reachable node: leave `MERIDIAN_CADDYFILE` at the default and Caddy
     obtains the certificate itself.
   - Event node: set `MERIDIAN_CADDYFILE=/etc/caddy/Caddyfile.onsite` and point
     `MERIDIAN_TLS_CERTIFICATE` and `MERIDIAN_TLS_KEY` at a certificate obtained
     **before** you left for the event. A field network cannot get one.

6. Start the stack. Migrations run automatically in production and event modes —
   take a database backup before you start, not after. The server container prints
   the warning and then migrates.

   ```bash
   corepack pnpm run deploy:up
   ```

7. Confirm it actually started. A node that still holds a sample secret stops
   here rather than serving, and says which variable it is waiting on — as does
   a node in an event or production role whose application URL is not HTTPS:

   ```bash
   corepack pnpm run deploy:ps
   corepack pnpm run deploy:logs
   ```

   See **Secrets** and **Event-mode fail-closed checks** below for what to do
   about each finding.

8. Open the server in a browser. A node with no identity redirects to first-run
   setup, which is where it gets its name, its role, and its signing keypair.
   Follow [Node setup and pairing](node-setup-and-pairing.md) — that document also
   covers pairing an on-site node with central, which is a separate step and has
   to be done from both sides.
9. Open the God Mode console. The landing screen lists anything still
   outstanding.

If the stack does not come up, `corepack pnpm run deploy:logs` follows every
container, and `corepack pnpm run deploy:ps` shows which one is unhealthy. The
server container's health check is the database connection; the end-to-end answer
is `GET /api/health` through the proxy.

## Secrets

Production and event modes refuse to boot with default secrets. A node whose
environment still carries a sample or placeholder value answers every request
with `503` and the names of the variables it is waiting on, the queue worker and
the scheduler refuse to start, and the container stops at the entrypoint with the
same list in `docker compose logs`.

`artisan` keeps working. That is deliberate: a node that cannot boot cannot be
repaired, and the repair lives there.

```bash
php artisan meridian:secrets              # what this node holds, and what it would refuse on
php artisan meridian:secrets --generate   # generate what Meridian owns, name what it does not
```

`--generate` covers the two secrets Meridian owns: Laravel's `APP_KEY`, written
to the node's environment file, and this node's signing keypair, stored as node
configuration. An existing key is never replaced — replacing one orphans every
operation this node has already signed.

Everything else is named and left alone, because it is not Meridian's to mint. A
database password belongs to the database, an SMTP password to the mail account,
an OAuth client secret to the provider that issued it; a generated value would
only stop the node connecting. Set those in the node's environment.

A deployed container has no environment file — its values arrive as process
environment — so `APP_KEY` is generated once, by hand, and set in the deployment
environment:

```bash
docker compose run --rm server php artisan key:generate --show
```

Neither the command nor the refusal ever prints a secret value. They print
variable names.

Do not copy a `.env` between nodes. Two nodes sharing a signing key cannot be
told apart by the node they sync with.

Sample configuration files under `deploy/` carry fake values on purpose. Replace
every one of them.

## Event-mode fail-closed checks

A node in an event or production role — any role other than `development` —
fails closed rather than starting insecurely. Two checks are the server's to
make, and it makes them at every boot, not only when the role is first
configured:

- **HTTPS validation.** The configured application URL (`APP_URL`) must use
  HTTPS. Production and event modes never use plain HTTP.
- **Offline read set.** The node must be able to serve the set devices cache to
  keep working without signal. A node that cannot hand a device anything is not
  ready to run an event.

A node that fails one answers every request with `503` and the failed check —
`/up` included, so the container never reports healthy — the queue worker and
the scheduler refuse to start, and the deployed container stops at the
entrypoint with the reason in `docker compose logs`.

```bash
php artisan meridian:event-mode           # each check, its state, and what failed
php artisan meridian:event-mode --json    # the same evaluation for tooling
```

As with the secrets, `artisan` keeps working so the node can be repaired. Fix
the finding the message names — usually `APP_URL` still reading `http://` —
and start the stack again. Local encryption and device signing are the
client-side halves of the same rule and are enforced by the apps themselves.

## Before an event

Work through this on the node that will run the event:

- [ ] Node role is `onsite` (or `standalone` for a single-node event).
- [ ] Node is paired with central and event data has synced down.
- [ ] HTTPS is trusted by the devices that will be used, not just by your
      laptop.
- [ ] The server is reachable from the event network, not only from the host —
      that is how devices get the data they will run on when the signal drops.
- [ ] The God Mode landing screen reports no outstanding attention items.
- [ ] You know how to reach the person who can change central-side data once
      the active event window opens and central starts refusing event-scoped
      writes.

## Backups

Back up the database and the storage volume together. The database holds the
records; the storage volume holds Field Report photos and staff profile pictures.
Restoring one without the other leaves records pointing at attachments that are
gone.

## Moving or rebuilding a node

Restore the database and the node's configuration together. A restored database
with a regenerated node identity will not be recognised by the peer it was
paired with, and pairing has to be repeated from central.

## Upgrading a node

Build or pull the new images, set `MERIDIAN_IMAGE_TAG` to the new version, and
restart the stack. The server container migrates on boot, so the backup has to
exist before the restart, not after it.

Do not run two nodes on different major versions and expect them to pair: node
pairing rejects incompatible major versions.
