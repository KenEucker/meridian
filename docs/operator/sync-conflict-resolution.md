# Sync conflict resolution

How to clear the conflict queue.

## What a conflict is

Meridian syncs between nodes as signed, append-only operations. An operation
that arrives and cannot be applied safely — because local and remote state
disagree about the same record — becomes a **sync conflict** and waits for a
human.

Two things follow from that:

- A conflict is one operation, not one record's whole history.
- **Unresolved conflicts do not block unrelated sync.** Everything else keeps
  moving. You are not holding up the event by leaving the queue for an hour.

Conflicts are different from operation failures. A failure means an operation
could not be delivered or could not be applied for a technical reason, and it
shows up under **Node Configuration → Node sync**. A conflict means both
versions are valid and somebody has to choose.

## Where the queue is

**God Mode → Sync Conflicts.** The list is grouped by entity type so you can
work one class of disagreement at a time, which is faster and produces more
consistent decisions than working chronologically.

The God Mode landing screen shows the outstanding count and links here.

## Reviewing one conflict

The review screen shows:

- the entity type and the reason the operation could not be applied;
- the **local value** — what this node holds;
- the **remote value** — what the peer sent;
- the two resolutions, with the recommended default marked.

You cannot edit either version here. That is deliberate: the resolver chooses
between two versions that actually exist, rather than inventing a third that
nobody's node has ever seen.

## The two resolutions

- **Accept on-site** — the on-site node's version wins.
- **Accept central** — the central node's version wins.

Choose on-site when the disagreement is about something that happened at the
event: attendance, deployment, on-the-ground incident state. The on-site node
was there.

Choose central when the disagreement is about governance data: configuration,
policies, organization structure. Central is authoritative for those outside the
active event window.

When you genuinely cannot tell, prefer the recommended default and record why.

## After resolving

Resolution applies the chosen version and writes an audit entry with your user
and your decision. A resolved conflict shows its decision and offers no further
action — resolutions are not undone by re-resolving, because that would be a
second disagreement rather than a correction of the first.

If accepting a version produces a wrong record, fix the record through the
normal path in [Data repair](data-repair.md) and say in the reason that it
followed a conflict resolution.

## During an event

Severe conflicts appear in the Electron health panel on the on-site command
centre, so somebody notices without watching the console.

Clearing the queue is not urgent by default. It becomes urgent when the
conflicting entity is one the event is actively using — a shift being staffed, an
incident being run. Work those first and leave the rest for after the event.
