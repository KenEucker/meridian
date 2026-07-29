# System Diagnostics

Whether this Meridian node is operating correctly, and what to do when it is
not.

## Where to look

**Infrastructure → System Diagnostics** runs every check when the page loads
and shows one card per check: status, summary, sanitized details, recommended
action, duration, and whether the check is required or optional. **Refresh**
runs everything again. There is no background polling.

**Infrastructure → Node Health** shows the latest sanitized health report from
this node and from every paired node that has delivered one. Nodes report
every ten minutes while they can reach central.

## Reading the statuses

| Status | Meaning |
|---|---|
| Healthy | The check measured what it wanted and found nothing wrong. |
| Warning | Something needs attention but the node keeps working. |
| Critical | The node cannot do part of its job. |
| Unknown | The check could not measure — which is reported honestly rather than guessed. |
| Not applicable | This install does not use the thing being checked. |

Overall health follows the same rule everywhere: a **required** check going
critical makes the node critical; an optional one going critical — a partially
configured integration, say — only degrades the node to warning.

## Offline on-site nodes are not broken

An on-site node with no internet queues operations for central. That is the
design working. Diagnostics and Node Health present a queued backlog on an
on-site node as *expected offline operation*; only failed operations, refused
exchanges, open sync conflicts, and stale health reports ask for a human.

A health report on the Node Health screen is labelled **stale** when it is
older than 30 minutes. Stale from a node that should be connected means the
node stopped reporting — check that node directly.

## Running diagnostics from the CLI

```bash
php artisan meridian:diagnostics
```

Exit code is non-zero when any required check is critical, so deployment
tooling and container health checks can gate on it. `--json` emits the same
sanitized bundle the export button produces.

## Exporting a support bundle

**Export sanitized bundle** on the diagnostics screen (or
`meridian:diagnostics --json`) downloads a JSON file safe to attach to a
support request: build and version information, node identity, check results,
and configuration *source/status* metadata. It never contains configuration
values, secrets, tokens, credentials, or volunteer data — that is enforced by
construction and by automated tests, not by operator care.

## The scheduler heartbeat

Several checks depend on the scheduler actually firing (`php artisan
schedule:run` every minute). The scheduler writes a heartbeat each run; the
**Scheduler heartbeat** check goes critical when the heartbeat is more than
five minutes old. If it does, nothing else scheduled — node sync, health
reports, credit freezes — is running either, so fix the scheduler first.

## When the override check is critical

**Database configuration overrides** going critical means the node booted
without being able to read the override table. The node is still up — it fell
back to environment configuration, which is the designed behavior — but any
database override you rely on is not in effect. Restore database availability
and the overrides re-apply on the next boot. See
[Configuration](configuration.md) for the recovery steps.
