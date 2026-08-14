# Meridian without Docker

The deployment bundle's five containers, as five host services on Debian or
Ubuntu with systemd. For a node whose host cannot run containers; the Compose
stack in [`../docker/`](../docker/README.md) remains the supported default and
is what a release is built and tested as.

Nothing about the application changes. This directory runs the same proxy
configuration out of [`../caddy/`](../caddy/README.md) rather than a copy, the
same environment keys, the same boot sequence, and the same fail-closed
safeguards — and `pnpm run deploy:check` asserts each of those, so the two
installation paths cannot drift apart silently.

| Compose service | Native equivalent |
|---|---|
| `postgres` | `postgresql@18-main`, tuned by `postgres/meridian.conf` |
| `server` | `php8.5-fpm`, pool in `php/meridian-pool.conf` |
| `worker` | `meridian-worker.service` (`queue:work`) |
| `scheduler` | `meridian-scheduler.service` (`schedule:work`) |
| `web` | `caddy.service`, running `deploy/caddy/*` from `/etc/caddy` |
| `entrypoint.sh` | `deploy-release.sh`, same order, same warnings |
| named volumes | `/var/www/meridian/apps/server/storage`, `/var/lib/postgresql` |
| `deploy:logs` | `journalctl -u meridian-worker -u caddy -f` |

Two things the Compose stack got for free and this does not: the image build
(so `deploy-release.sh` runs Composer, the client build, and the docs/changelog
packaging on the node), and network isolation for PostgreSQL (so
`postgres/meridian.conf` binds it to loopback instead).

## Install path

`/var/www/meridian`, holding the monorepo checkout. Not negotiable, for two
reasons: `deploy/caddy/meridian.snippet` roots the site at
`/var/www/meridian/apps/server/public`, and the server resolves the root
`package.json` from `base_path('../..')` and the client artifact from
`base_path('../client/dist/admin')`. Keeping the repository layout at that path
makes every one of those correct with no override — which is exactly why the
Dockerfile mirrors the monorepo instead of flattening it into a web root.

## Walkthrough

```bash
# 1. Provision the host. DB_PASSWORD is the deployment's database password.
sudo DB_PASSWORD='<a real password>' ./install-host.sh

# 2. Node configuration.
sudo cp .env.server.example /var/www/meridian/apps/server/.env
sudo chown www-data:www-data /var/www/meridian/apps/server/.env
sudo chmod 0640 /var/www/meridian/apps/server/.env
sudo -u www-data editor /var/www/meridian/apps/server/.env

# 3. The node's own application key. Paste the output into APP_KEY.
cd /var/www/meridian/apps/server && sudo -u www-data php8.5 artisan key:generate --show

# 4. Proxy configuration: MERIDIAN_SITE_ADDRESS, and the TLS choice below.
sudo editor /etc/meridian-proxy.env

# 5. Build and release.
sudo ./deploy-release.sh
```

Upgrades are steps 5 alone, after a `git pull` in `/var/www/meridian` — plus a
database backup first, which the script warns about before it migrates rather
than after.

## TLS is not optional

`MERIDIAN_NODE_ROLE=standalone` is a non-development role, so the node is in
event mode, so `MERIDIAN_EVENT_MODE_REQUIRE_HTTPS` applies and
`php artisan meridian:event-mode` fails the release if `APP_URL` is not HTTPS.
That is the safeguard working. Three ways through it, in order of preference:

1. **A public hostname resolving to the mini PC, ports 80 and 443 reachable.**
   Leave `MERIDIAN_CADDYFILE=/etc/caddy/Caddyfile`; Caddy obtains and renews the
   certificate over ACME on its own.
2. **A real hostname, no inbound route** (mini PC on a home LAN). Issue the
   certificate elsewhere over DNS-01, drop the pair in `/etc/caddy/tls`, and set
   `MERIDIAN_CADDYFILE=/etc/caddy/Caddyfile.onsite` with
   `MERIDIAN_TLS_CERTIFICATE` and `MERIDIAN_TLS_KEY` pointing at them. This is
   the event-node path, and it is the right one for a LAN box.
3. **An internal CA.** Same as (2), with a certificate you issued, and the CA
   installed on every device that will reach the node. Field and Kiosk clients
   will refuse it otherwise.

Whichever you pick, the network's DNS has to resolve `MERIDIAN_SITE_ADDRESS` to
the node's address — `deploy/dns/` has the dnsmasq and hosts templates for a
network Meridian controls.

## Verify

```bash
systemctl status php8.5-fpm meridian-worker meridian-scheduler caddy
curl -fsS https://<MERIDIAN_SITE_ADDRESS>/api/health
```

`GET /api/health` through the proxy is the end-to-end answer — the same one the
Compose healthchecks deliberately stopped short of. If it answers and the
worker's journal is quiet, the node is up. A node with no identity redirects to
first-run setup on first open; follow
`docs/technician/node-setup-and-pairing.md` from there.

## Backups

Same rule as the Compose deployment: the database and the storage tree go
together, because a restore of one without the other produces records pointing
at attachments that are gone.

```bash
sudo -u postgres pg_dump -Fc meridian > meridian-$(date +%F).dump
sudo tar czf meridian-storage-$(date +%F).tar.gz \
    -C /var/www/meridian/apps/server storage
```

Restore a node's `.env` with its database. A restored database with a
regenerated node identity is not recognised by the peer it was paired with, and
pairing has to be repeated from central.

## What keeps this honest

A second installation path is a second thing to forget when the first one
changes, so `pnpm run deploy:check` reads these files against the Compose stack
they mirror and fails on drift:

- the worker and scheduler units run the same commands, with the same queue
  list, as the `worker` and `scheduler` services;
- `deploy-release.sh` runs the boot sequence in the entrypoint's order — the
  backup warning before the migration, secrets after it, and the event-mode
  fail-closed checks after the config cache (technical spec 8.6, 26.2);
- the PHP and PostgreSQL versions match the Dockerfile's base image and the
  stack's database image, and the PHP request limits match the runtime block
  the image writes;
- the PostgreSQL settings match the `command:` the database service runs, so
  logical replication stays available for node-to-node sync;
- the sample environment carries fake values and no secrets (technical spec
  26.2);
- this directory ships no proxy configuration of its own —
  `install-host.sh` installs `deploy/caddy/` — because a second copy of the
  Caddyfiles is exactly the drift the shared snippet exists to prevent.

What no check here can answer is whether the scripts run, since that needs a
Debian or Ubuntu host with systemd. Treat the first `install-host.sh` on a node
as the real test.
