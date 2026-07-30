# Deployment

How to stand up a Meridian server.

## What a deployment is made of

| Piece | What it does |
|---|---|
| Laravel server | The API, Meridian Admin, and the God Mode console. |
| PostgreSQL | All Meridian data. |
| PowerSync | Feeds offline-capable devices. Required in event mode. |
| Caddy | TLS termination and reverse proxy. |
| Shared Vue client | Meridian Admin, Field, and Kiosk builds served by the server. |

Deployment configuration templates live under `deploy/`: `deploy/docker` for
Compose, `deploy/caddy` for the reverse proxy, `deploy/powersync` for the sync
service, and `deploy/dns` for name resolution on the event network.

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

1. Start PostgreSQL and confirm the server can reach it.
2. Start PowerSync. In event mode a node refuses to run without it.
3. Put Caddy in front with a certificate the browsers on the event network will
   actually trust. Event mode requires HTTPS and fails closed without it.
4. Start the Laravel server. Migrations run automatically in production and
   event modes — take a database backup before you start, not after.
5. Open the server in a browser. A node with no identity redirects to first-run
   setup. Follow [Node setup and pairing](node-setup-and-pairing.md).
6. Open the God Mode console. The landing screen lists anything still
   outstanding.

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
- [ ] PowerSync is reachable from the event network, not only from the host.
- [ ] The God Mode landing screen reports no outstanding attention items.
- [ ] You know how to reach the person who can change central-side data once
      the active event window opens and central starts refusing event-scoped
      writes.

## Moving or rebuilding a node

Restore the database and the node's configuration together. A restored database
with a regenerated node identity will not be recognised by the peer it was
paired with, and pairing has to be repeated from central.
