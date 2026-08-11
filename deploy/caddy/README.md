# Caddy Deployment

Caddy terminates TLS in front of the Meridian server and proxies PHP to it
(technical spec 5.1, 8.2). It is the only part of a deployment that speaks to the
network directly.

## The files

| File | For |
|---|---|
| `Caddyfile` | An internet-reachable node — central or standalone. Caddy obtains and renews the certificate itself over ACME. |
| `Caddyfile.onsite` | An event node on a field network. Serves a certificate provisioned before the event, with automatic issuance switched off. |
| `meridian.snippet` | The site body both import: root, PHP upstream, compression, body limit, security headers, logging. |

Both Caddyfiles are copied into the `web` image at `/etc/caddy/`.
`MERIDIAN_CADDYFILE` in the deployment environment file decides which one runs.

The shared snippet exists because the difference between the two deployments is
how the certificate is obtained, not what the site serves. A header or a limit
that drifted between them would be a difference nobody chose.

## Why two, rather than one with a switch

An event network has no inbound route from the internet, so an ACME challenge
against it cannot be answered. Leaving automatic issuance on would make every boot
a retry loop against a challenge that cannot succeed, and a node that fails HTTPS
validation in event mode fails closed (technical spec 8.6, 26.2) — which is the
correct behavior and a miserable way to discover the configuration was wrong.

So the on-site file states it plainly: `auto_https disable_certs`, OCSP stapling
off, and an explicit `tls` directive naming the certificate and key. Get the
certificate for the event hostname while the node still has internet, renew it
before each event, and never during one.

## Validation

Both files are parsed at image build time. The `web` stage runs `caddy adapt` over
each of them, which converts the Caddyfile to Caddy's JSON configuration and
therefore parses every directive with the module that owns it — so a misspelled
directive, a subdirective a directive does not accept, or a snippet imported but
never defined fails the build.

`adapt` rather than `validate` because validate also provisions the modules, and
provisioning `Caddyfile.onsite` means loading a certificate that exists only on an
event node.

To reformat after editing:

```bash
docker run --rm --mount type=bind,source="$PWD/deploy/caddy",target=/etc/caddy caddy:2-alpine caddy fmt --overwrite /etc/caddy/Caddyfile
```

## Configuration

Set in the deployment environment file, read by Caddy from the container's
environment:

| Variable | Meaning |
|---|---|
| `MERIDIAN_SITE_ADDRESS` | The hostname on the certificate, and the site Caddy serves. Matches the host in `APP_URL`. |
| `MERIDIAN_SERVER_UPSTREAM` | The php-fpm address. `server:9000` inside the stack. |
| `MERIDIAN_ACME_EMAIL` | Where the certificate authority sends renewal-failure notices. `Caddyfile` only. |
| `MERIDIAN_TLS_CERTIFICATE`, `MERIDIAN_TLS_KEY` | Paths inside the container to the provisioned certificate. `Caddyfile.onsite` only. |

## The public root coupling

Caddy holds a copy of the server's `public` directory at the same path the server
image holds it, `/var/www/meridian/apps/server/public`. That path reads like a
checkout because the server image mirrors the monorepo layout: the server resolves
its build version, its license, and the client artifact from paths relative to
`apps/server`, so keeping the depth makes them correct with no override.

The copy is not redundancy: `php_fastcgi`
builds `SCRIPT_FILENAME` from Caddy's own root and sends that path to php-fpm, so
the path has to resolve in both containers. A path that resolves in only one
produces `Primary script unknown` rather than a page.

Caddy's own file serving therefore covers only the server's public files —
`favicon.ico`, `robots.txt`, console CSS and images. The built client artifact and
its assets are served through PHP by `ClientAppController`, so they are not in the
proxy image at all.

## Organization subdomains

Organizations are addressable at `<organization-slug>.<deployment-domain>` as well
as at their root path (technical spec 8.7). Serving the subdomain form needs a
wildcard host and a wildcard or per-organization certificate, which is M19.10.
These files serve the deployment root, where the root-path form works today.

Adding `*.example.org` here before that task would ask Caddy for a wildcard
certificate, which requires the DNS-01 challenge and a DNS provider module
configured with credentials — a deployment decision, not a line in a Caddyfile.

## Local discovery

`.local` mDNS names are not sufficient for browser-trusted HTTPS unless the client
trusts the certificate, so they serve the installed app and admin or debug access
rather than staff workflow (technical spec 8.5). Nothing in this directory serves
them; the event hostname above is what browsers use.
