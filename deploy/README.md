# Meridian Deployment Bundle

The deployment configuration bundle: the fourth distribution artifact Meridian
produces, beside the server image, the mobile package, and the desktop installer
(technical spec section 4).

One Compose install serves every Alpha 1 deployment role — central, on-site, and
standalone (technical spec 8.1). The role is configuration rather than a
different stack: `MERIDIAN_NODE_ROLE` decides what a node is allowed to do, and an
on-site node additionally runs the proxy configuration that serves a certificate
provisioned before the event.

Development is the exception and does not use this bundle. A developer runs the
server with `php artisan serve` and the client with Vite, against the database
service in [`docker/compose.yaml`](docker/compose.yaml).

## What is in it

| Path | What it is |
|---|---|
| [`docker/Dockerfile`](docker/Dockerfile) | The `server` and `web` images. Multi-stage, built from the repository root. |
| [`docker/entrypoint.sh`](docker/entrypoint.sh) | Per-boot work: storage tree, migrations with a backup warning, secret generation and refusal, config caches, event-mode fail-closed checks. |
| [`docker/compose.deployment.yaml`](docker/compose.deployment.yaml) | The deployment stack: database, server, queue worker, scheduler, proxy. |
| [`docker/.env.deployment.example`](docker/.env.deployment.example) | The deployment's configuration, with fake values. |
| [`docker/compose.yaml`](docker/compose.yaml) | The development database service, on the loopback interface. |
| [`caddy/Caddyfile`](caddy/Caddyfile) | Proxy for an internet-reachable node; Caddy obtains the certificate over ACME. |
| [`caddy/Caddyfile.onsite`](caddy/Caddyfile.onsite) | Proxy for an event node; serves a certificate provisioned before the event. |
| [`caddy/meridian.snippet`](caddy/meridian.snippet) | The site body both Caddyfiles import, so the two cannot drift. |
| [`dns/onsite-dnsmasq.conf`](dns/onsite-dnsmasq.conf) | Event network DNS for the Meridian-controlled router or AP. |
| [`dns/onsite-hosts.example`](dns/onsite-hosts.example) | Per-machine fallback where Meridian does not control DNS. |

## What is not in it

**No PowerSync service.** ADR-0003 retired it. Devices fetch the offline read set
from the Laravel server (`GET /api/offline-read-set`) and queue writes back through
the same node, so a deployment runs one application and no sync engine beside it.
Earlier planning documents list PowerSync among the bundle's services; they
predate the decision.

**No Redis or Memcached.** Sessions, cache, and queue are all database-backed.

**No wildcard host handling.** Organizations are addressable at
`<organization-slug>.<deployment-domain>` as well as at their root path
(technical spec 8.7), and the wildcard DNS and TLS configuration that serves the
subdomain form is M19.10. Until then this bundle serves the deployment root, and
the root-path form of organization addressing works there.

**No secret generation of its own.** The safeguards technical spec 26.2 asks for
— generate `APP_KEY` and node keys when they are missing or still a sample value,
and refuse to boot on the rest — belong to the server (M19.3), so they hold
however a node is started and not only when it is started by Compose. The
entrypoint calls them (`php artisan meridian:secrets --generate`) so a
misconfigured node stops at start with one legible line in
`docker compose logs`, rather than coming up and answering `503` to everything.
Nothing in this bundle decides the policy.

## Deploy a node

The full walkthrough, with what each value means, is
[Deployment](../docs/technician/deployment.md). The short form, in the order the
steps actually depend on each other:

```bash
cp deploy/docker/.env.deployment.example deploy/docker/.env.deployment
corepack pnpm run deploy:build
```

`deploy:build` tags both images with the root `package.json` version, which is the
only Meridian product version (technical spec 26.3; the versioning strategy). Set
`MERIDIAN_IMAGE_TAG` in the environment file to the value it prints before going
further — the Compose file requires it, so nothing below runs without it.

Then generate the node's own application key, which needs the image that was just
built, and set `APP_KEY` to what it prints:

```bash
docker compose --env-file deploy/docker/.env.deployment \
  -f deploy/docker/compose.deployment.yaml \
  run --rm server php artisan key:generate --show
```

Edit the remaining values marked `CHANGE ME` — node role, hostname, database
password, mail credentials, and the certificate settings for an event node — and
start the stack:

```bash
corepack pnpm run deploy:up
```

A node that still holds a sample secret stops at start rather than serving, and
names the variable it is waiting on in `deploy:logs`. That is technical spec 26.2
working, not a broken deployment.

The other scripts:

```bash
corepack pnpm run deploy:check     # validate the bundle (the build smoke test)
corepack pnpm run deploy:config    # render the resolved stack configuration
corepack pnpm run deploy:ps        # service status
corepack pnpm run deploy:logs      # follow logs
corepack pnpm run deploy:down      # stop the stack (data is preserved)
```

A node with no identity redirects to first-run setup on first open. Follow
[Node setup and pairing](../docs/technician/node-setup-and-pairing.md), and
[Deployment](../docs/technician/deployment.md) for the operational walkthrough.

## An event node

Two things change for a node that will run an event on a field network:

1. `MERIDIAN_CADDYFILE=/etc/caddy/Caddyfile.onsite`, with
   `MERIDIAN_TLS_CERTIFICATE` and `MERIDIAN_TLS_KEY` pointing at the certificate
   provisioned before the event. An event network has no route from the internet,
   so no certificate can be issued once you are there.
2. The event network's DNS resolves that certificate's hostname to the node's LAN
   address. See [`dns/`](dns/README.md).

`MERIDIAN_NODE_ROLE=onsite` and pairing with central are covered by
[Node setup and pairing](../docs/technician/node-setup-and-pairing.md).

## Backups

Back up the database and the storage volume together. The database holds the
records; `meridian-deployment-storage` holds Field Report photos and staff profile
pictures. A restore of one without the other produces records pointing at
attachments that are gone.

Restore a node's configuration with its database. A restored database with a
regenerated node identity is not recognised by the peer it was paired with, and
pairing has to be repeated from central.

## Validation

The bundle is checked on every pull request, in two halves.

The hermetic half runs inside `pnpm run check` and `pnpm test`:

```bash
corepack pnpm run deploy:check
```

It asserts the bundle's files are present, base images are pinned, the Compose
stack builds Dockerfile targets that exist, the image tag is the root version, the
deployment database publishes no host port, the sample environment carries no
secrets and is complete enough to render the stack, no proxy configuration serves
plain HTTP and the shared site body tells browsers to refuse the plain-HTTP form,
the entrypoint runs the server's event-mode fail-closed checks after the caches
it builds, and the DNS templates carry documentation names and private addresses
only.

The Docker half is opt-in, and runs as its own CI step:

```bash
corepack pnpm run deploy:check:docker
```

It renders both Compose files against the committed sample environment — which is
how the sample is proven sufficient — and runs `docker build --check` against both
image targets. It is separate because it reaches a registry for base image
metadata, and a rate-limited registry should not be able to fail a pull request
that changed a Vue component.

Whether the Caddyfiles actually parse is a question only Caddy can answer, so the
`web` image build answers it: it runs `caddy adapt` over both configurations, and a
misspelled directive or an undefined snippet fails the build. That check lives in
the build rather than beside the others because a proxy configuration that does not
parse produces a container that will not start, and an event network is the worst
place to find that out.

Neither half of `deploy:check` builds the images. `pnpm run deploy:build` does
that, and it is the release step rather than a per-pull-request one.
