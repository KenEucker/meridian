# Configuration

How Meridian resolves a setting, and what to do when a value is not the one you
set.

## The three sources

Every node configuration value comes from exactly one of three places. God Mode
shows which:

| Source | Where it comes from | Wins over |
|---|---|---|
| Database override | A value written by first-run setup, pairing, or a God Mode edit | File config and runtime default |
| File config | The node's environment file, read through `config/meridian.php` | Runtime default |
| Runtime/default | The value compiled into the build | Nothing |

Precedence is **database first, then file, then default**. This surprises people
who expect the environment file to be authoritative. It is not: an operator
sitting at the console during an event must be able to change a value without
editing a file and restarting the server, so the database override wins.

## Where to look

**God Mode → Node Configuration** lists every known value with its resolved
value and its source label. That table is the answer to "why is this setting not
what I put in the env file" — if the source says *database override*, your file
value is being shadowed.

## Values that are managed for you

Some values are written as database overrides by Meridian itself and should not
be hand-edited:

- `central_node_name`, `central_node_public_key`, `central_node_paired_url`, and
  `central_node_paired_at` are written by pairing. Editing them by hand does not
  re-pair anything; it only makes the pairing status lie.
- `node_public_key` and `node_private_key` are written by first-run setup.
  Replacing them orphans every operation this node has already signed.

## Sensitive values

Secrets are never displayed. The configuration table shows *Configured (hidden)*
or *Not set* and nothing else. That applies to the node private key and to the
source-repository credential used for changelog refresh.

If you need to know whether a secret is correct, test the behaviour that uses
it. Do not expect the console to show it back to you.

## Changing node settings

On **God Mode → Node Configuration**, **Save settings** writes node name, node
role, and central node URL as database overrides.

Two things happen that are easy to miss:

- Changing the node role re-evaluates the event-mode safeguards. If the new role
  is not `development` and HTTPS or PowerSync validation fails, the save is
  refused and the reason is shown. That is the safeguard working.
- Changing the central node URL invalidates pairing. The status becomes *Pairing
  recheck required* until you pair again.

## Event mode

Event mode is derived from the effective node role: any role other than
`development` is treated as event/production mode, unless `MERIDIAN_EVENT_MODE`
explicitly forces it on or off.

In event mode the server enforces two checks and fails closed on either:

- The configured application URL must use HTTPS.
- PowerSync must answer its liveness probe.

Local encryption and device signing are checked on the client, not here.

Config schema version mismatches do not block startup. The version is exposed so
clients and the Electron health panel can notice drift.

## Where to see the effect

The God Mode landing screen reports node configuration completeness, node role
and pairing state, required secrets, secure connection policy, and PowerSync
connectivity. If you changed something and want to know whether it helped, that
list is the fastest answer. It reads state and repairs nothing.
