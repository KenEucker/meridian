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

1. Copy the sample configuration and edit every value marked `CHANGE ME`. It sets
   the node role, the hostname, the database password, and the mail credentials.

   ```bash
   cp deploy/docker/.env.deployment.example deploy/docker/.env.deployment
   ```

2. Build the images. Both are tagged with the Meridian version they were built
   from; put that value in `MERIDIAN_IMAGE_TAG` in the file you just edited.

   ```bash
   corepack pnpm run deploy:build
   ```

3. Decide how the node gets its certificate, because the browsers on the event
   network have to actually trust it. Event mode requires HTTPS and fails closed
   without it.

   - Internet-reachable node: leave `MERIDIAN_CADDYFILE` at the default and Caddy
     obtains the certificate itself.
   - Event node: set `MERIDIAN_CADDYFILE=/etc/caddy/Caddyfile.onsite` and point
     `MERIDIAN_TLS_CERTIFICATE` and `MERIDIAN_TLS_KEY` at a certificate obtained
     **before** you left for the event. A field network cannot get one.

4. Start the stack. Migrations run automatically in production and event modes —
   take a database backup before you start, not after. The server container prints
   the warning and then migrates.

   ```bash
   corepack pnpm run deploy:up
   ```

5. Open the server in a browser. A node with no identity redirects to first-run
   setup. Follow [Node setup and pairing](node-setup-and-pairing.md).
6. Open the God Mode console. The landing screen lists anything still
   outstanding.

If the stack does not come up, `corepack pnpm run deploy:logs` follows every
container, and `corepack pnpm run deploy:ps` shows which one is unhealthy. The
server container's health check is the database connection; the end-to-end answer
is `GET /api/health` through the proxy.

## Secrets

Production and event modes refuse to boot with default secrets, and generate
`APP_KEY`, node keys, and service secrets when they are missing or default.

Do not copy a `.env` between nodes. Two nodes sharing a signing key cannot be
told apart by the node they sync with.

Sample configuration files under `deploy/` carry fake values on purpose. Replace
every one of them.

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
