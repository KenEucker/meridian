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

## `.local` names

On-site nodes support `.local` mDNS names, and those names are not sufficient for
browser-trusted HTTPS unless the client trusts the certificate — so they serve the
installed app and admin or debug flows rather than staff workflow (technical spec
8.5). `onsite-dnsmasq.conf` deliberately does not answer for `.local`: a DNS server
that does breaks the mDNS the devices are doing themselves.

## Organization subdomains

Organizations resolve at `<organization-slug>.<deployment-domain>` as well as at
their root path (technical spec 8.7). The wildcard DNS entry that serves the
subdomain form on a deployment, and the `*.localhost` development equivalent, are
M19.10.

On-site nodes are unaffected either way: an on-site node serves its one event
hostname and does not serve the marketing surface (PUBLIC-006).
