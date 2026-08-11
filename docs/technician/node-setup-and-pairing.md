# Node setup and pairing

How a Meridian install gets an identity, and how an on-site node joins central.

## First-run setup

A server with no active local node sends every visitor to `/setup`. That form
asks for three things:

- **Node name** — letters, digits, dots, and hyphens. It identifies this node in
  sync exchanges, in pairing, and in the Electron health panel.
- **Node role** — `development`, `standalone`, `central`, or `onsite`.
- **Central node URL** — only for a node that will pair with central.

Setup generates the node's signing keypair. The private key is stored as node
configuration; the public key is what a peer verifies signatures against.

Choosing anything other than `development` puts the node in event mode, and setup
refuses the role rather than creating the node when an event-mode safeguard is
failing — plain HTTP, an unservable offline read set, or a secret still set to a
sample value. That refusal is the same one the node's own boot would make, so a
setup that succeeds is a node that will start. `php artisan meridian:secrets`
names what is outstanding, and **Secrets** in `deployment.md` covers the repair.

For a node that arrived without keys — a database restored without its config
values, an install prepared before the keys existed — `php artisan meridian:secrets
--generate` gives it a keypair. It never replaces one that is already there.

**Infrastructure → Node Configuration** in the console asks for the same three
things and does the same work, so a node can be set up from there instead. The
`/setup` page exists for the moment before anyone can sign in; once you are in
the console, you do not have to leave it to give this install an identity.

Setup runs once, from either entry point. A node that already has an active
local node refuses to run it again — that is deliberate, because a second
identity would orphan everything the first one signed.

If you chose the wrong role, change it on **Infrastructure → Node Configuration**
rather than trying to re-run setup.

## Pairing an on-site node with central

Pairing joins two Meridian **server installs** so they can exchange event
operations. It is not a device credential and not a Kiosk credential. Personal
devices register their own signing key through device trust; Kiosk uses shared
workstation login codes.

Only `standalone` and `onsite` nodes pair. A central node never shows the
pairing form.

### On central

1. Open **Infrastructure → Node Configuration**.
2. Select **Create pairing token**.
3. Copy the token immediately. It is stored only as a hash and is never shown
   again.

Tokens do not expire in Alpha 1. If one is issued in error or lost, use **Revoke
unused tokens** — that revokes every unused token, so any node still waiting to
pair will need a new one.

### On the on-site node

1. Open **Infrastructure → Node Configuration**.
2. Confirm the node role is `onsite` or `standalone`.
3. Under **Central pairing**, enter central's URL and paste the token.
4. Select **Pair with central**.

The token pairs one node once. Re-running pairing from the same node is safe; a
different node cannot reuse the token.

## Reading pairing state

Node Configuration shows a pairing status:

| Status | What it means |
|---|---|
| No node configured | First-run setup has not happened. |
| Not applicable for this node role | A central or development node. Nothing to do. |
| Not paired | This node can pair and has not. Operations will not reach central. |
| Pairing recheck required | The configured central URL no longer matches the URL this node paired against. Pair again. |
| Paired with central | Normal. |

"Pairing recheck required" is not a warning you can dismiss. Changing the central
node URL invalidates the pairing on purpose, so a node cannot be quietly pointed
at a different central.

## When pairing fails

- **Token rejected** — it was already used, revoked, or came from a different
  central. Issue a new one.
- **Cannot reach central** — check DNS and the certificate before you touch
  Meridian. Pairing is an HTTPS call like any other.
- **Wrong role** — a central node has no pairing form. Fix the role first.

## After pairing

Event data syncs down to the on-site node. Check **God Mode → Node
Configuration → Node sync**:

- **Queued to send** is not a fault. An on-site node queues operations whenever
  the internet is gone and pushes them when it returns.
- **Refused by the peer** and **could not be applied** are faults. The two mean
  different things: refused means this node's operations were rejected on
  arrival; could not be applied means they arrived and something local went
  wrong.
