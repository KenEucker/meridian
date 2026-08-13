# DNS Deployment

Name resolution for an on-site Meridian deployment (technical spec 8.3, 8.4, 8.5).

## The problem this solves

An event node needs browser-trusted HTTPS on a field network. Browsers trust a
certificate for a public hostname, and a certificate cannot be issued for a LAN
address. So the on-site model splits the two apart:

1. Before the event, while the node still has internet, obtain a certificate for a
   real public hostname — `juplaya-2027-onsite.example.org`.
2. During the event, the event network's own DNS answers that hostname with the
   node's LAN address.

Devices reach `https://juplaya-2027-onsite.example.org`, get a certificate their
browsers already trust, and never learn that the address behind the name changed.
That is the whole mechanism, and it is why the preferred on-site kit includes a
Meridian-controlled router or access point providing DHCP and DNS (technical spec
8.3).

## The files

| File | For |
|---|---|
| `onsite-dnsmasq.conf` | The Meridian-controlled router or AP. dnsmasq is what OpenWrt and most small routers already run for DHCP and DNS, so this is a fragment to drop in rather than a service to add. |
| `onsite-hosts.example` | A handful of machines that must reach the node in a browser, on a network whose DNS you cannot change. A per-machine edit, so it does not scale to a staff roster. |

Every hostname and address in both is fake (technical spec 26.2). Replace them.

## When Meridian does not control the network

Browser and PWA access is not guaranteed, and the installed Capacitor app is the
reliable client (technical spec 8.2 rule 4, 8.4). The app supports local discovery,
mDNS or IP discovery where available, and node fingerprint trust, which is the path
that does not depend on anybody's DNS.

Direct IP access with a self-signed certificate is God-mode and emergency access
only, never normal staff workflow (technical spec 8.2 rule 6). Do not plan an event
around clicking through a certificate warning.

## The on-site convention names: `meridian.home.arpa`

`home.arpa` is the special-use domain a local network answers for itself (RFC
8375) — it never resolves from the internet, which makes it the right home for
names that only mean something on this network. Meridian's convention on top of
it: `meridian.home.arpa` is the main local node, and additional nodes take
`meridian2.home.arpa`, `meridian3.home.arpa` in order. The installed Field app
assumes exactly this on a device nobody has configured — it works against
`meridian.home.arpa` from first boot, probes the numbered siblings when the
main name does not answer, and falls back to the central deployment last
(technical spec 8.4) — so a network that answers these names gives every fresh
install its node with no settings typed on any phone.

`onsite-dnsmasq.conf` carries the records. The serving half is
`deploy/caddy/Caddyfile.home-arpa`, and the walkthrough for running a local
node from a fresh clone is in the README beside it.

Numbering today is an operator act: the main node's record points at the main
node, and a second machine gets the `meridian2` record when somebody adds it.
Nodes actively discovering that `meridian.home.arpa` is taken and registering
the next free name themselves needs the node to manage the network's DNS
records, which no shipped component does yet — a follow-up with its own spec
section when multi-node kits become real.

## `.local` names

On-site nodes support `.local` mDNS names, and those names are not sufficient for
browser-trusted HTTPS unless the client trusts the certificate — so they serve the
installed app and admin or debug flows rather than staff workflow (technical spec
8.5). `onsite-dnsmasq.conf` deliberately does not answer for `.local`: a DNS server
that does breaks the mDNS the devices are doing themselves.

## Organization subdomains

Organizations resolve at `<organization-slug>.<deployment-domain>` as well as at
their root path (technical spec 8.7). Serving the subdomain form takes one
wildcard record at the deployment's DNS provider, pointing at the same address
the root record points at:

```text
*.meridian.example.org.    A    <the deployment's address>
```

(or a `CNAME` to the root name, where the provider allows a wildcard CNAME).
No per-organization records exist and none are ever added: which organization a
request addresses is the server's decision, made from the Host header, and a
subdomain that matches no active organization is the server's 404 (ORG-024).
The certificate half of the wildcard — the part DNS-01 issuance exists for — is
`deploy/caddy/Caddyfile.wildcard`; see the README beside it.

### `*.localhost` in development

Development needs no DNS at all: browsers resolve any `*.localhost` name to
loopback on their own, so `http://northwood.localhost:8000` reaches the dev
server directly, and the server derives the platform host from `APP_URL`
(technical spec 8.7). Two caveats:

- Tools that resolve through the OS rather than the browser (curl, some mobile
  emulators) may not resolve `*.localhost`. Give them `--resolve`, a hosts-file
  entry per slug you actually use — hosts files cannot express a wildcard — or
  a local dnsmasq line: `address=/.localhost/127.0.0.1`.
- The organization must exist and be active on the dev node: an unknown
  subdomain is a 404 by design, so `northwood.localhost` answers only after the
  Northwood scenario is seeded.

On-site nodes are unaffected either way: an on-site node serves its one event
hostname and does not serve the marketing surface (PUBLIC-006).
