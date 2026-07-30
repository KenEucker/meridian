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
who expect the environment file to be authoritative. It is not: a technician
sitting at the console during an event must be able to change a value without
editing a file and restarting the server, so the database override wins.

## Where to look

**Infrastructure → Node Configuration** lists every known value with its resolved
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

On **Infrastructure → Node Configuration**, **Save settings** writes node name, node
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

## The full configuration catalogue

Node identity is a handful of values; the rest of the server's environment is
catalogued too. **Infrastructure → System Configuration** lists every variable
the server's `.env.example` knows about — that file is the catalogue, and a
variable absent from it is not a variable Meridian claims to manage.

Each row shows the effective value, where it came from, and whether a database
override exists. The source badges are truthful:

| Badge | Meaning |
|---|---|
| Database Override | An active, valid node-local override is winning. |
| Environment / .env | The process environment or `.env` file. Laravel loads `.env` into the environment, so the two genuinely cannot be told apart, and Meridian does not pretend to. |
| Laravel Default | The value compiled into the build's configuration files. |
| Missing | Nothing sets it anywhere. |
| Invalid | A stored override failed validation and is being ignored; the environment value is winning. |
| Unmapped | Catalogued for visibility, but with no declared configuration mapping — read-only. |

## Applying an override

1. Open the variable from **Infrastructure → System Configuration**.
2. Read the activation requirement. It tells you what has to restart before
   the change is fully live; saving does not restart anything for you.
3. Enter the value (it is validated against the variable's declared type) and
   a change reason, then **Save override**.
4. Watch the *pending activation* badge. It stays until every affected process
   is running on the new value — for worker-class settings that means
   restarting the queue/scheduler workers.

Removing or disabling the override restores the environment/default value at
the next activation point. Every change is audited with redacted values.

## What cannot be overridden

**Bootstrap-locked** values are needed before database overrides can load: the
application key, the primary database credentials, the cache and session
stores, and the node signing key. They are visible in the catalogue but can
only be changed in the environment file or deployment configuration, followed
by the restart that layer requires. A row smuggled into the override table for
one of these is skipped at boot and flagged by diagnostics.

Node identity and pairing values are **managed** by the Node Configuration
screen and are read-only in the catalogue — change them where their side
effects (event-mode re-validation, pairing recheck) actually run.

## Secrets

Secret overrides are encrypted at rest and never displayed — not in the
table, not in the edit screen, not in exports, not in the audit log, not in
CLI output. You can replace a secret, disable it, or remove it; you cannot
read it back. If you need to know whether a secret is correct, test the
behavior that uses it.

Overrides are node-local. They are never distributed through PowerSync or
node-to-node sync, central overrides are not copied to on-site nodes, and an
offline node keeps its overrides working.

## Recovering from a bad override

An override that fails validation is skipped at boot — the environment value
keeps winning and diagnostics reports the skip — so a *typed-invalid* value
cannot take the node down. A valid-but-wrong value can. If a saved override
breaks the node:

1. If the console still loads, remove or disable the override on its edit
   screen and restart whatever its activation class names.
2. If the console does not load, the environment file still wins over nothing:
   delete the row from `system_config_overrides` directly
   (`php artisan tinker` or SQL), then restart. The table is node-local, so
   this touches nothing on any other node.

## When the override table is unreachable

The node boots anyway. Failure to read `system_config_overrides` is logged
once (exception class only), the node continues on environment configuration,
and the **Database configuration overrides** diagnostic goes critical so the
fallback is visible rather than silent. Overrides re-apply on the next boot
after the database is reachable.

## CLI

```bash
php artisan meridian:config:list        # catalogue with sources; secrets masked
php artisan meridian:config:validate    # non-zero exit on invalid overrides or missing required values
```
