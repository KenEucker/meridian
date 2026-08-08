# QA-SYNC-01: On-Site / Central Node Sync

## Purpose

Verify the Milestone 12 node sync path as a human reviewer: a paired on-site
Meridian node queues signed append-only operations while central is unreachable,
drains them through the bidirectional node-sync exchange when central returns,
stores remote operations before applying them, enforces active-event authority,
surfaces failures without treating an outage backlog as a defect, and lets God
mode resolve sync conflicts by accepting either the on-site or central version.

This script closes the Milestone 12 human QA gate. It does not define new
operation vocabularies for product features, exercise the server-to-device sync
path, or require raw database replication. Per-entity appliers remain owned
by the feature tasks that emit those operations.

## Requirements covered

- Technical spec section 7.4: Node setup
- Technical spec sections 10.1 through 10.4: Sync model, event authority,
  conflict policy, and node operation schema
- Technical spec section 21.10: Active-event governance edit freeze
- Technical spec sections 23 and 25.3: Audit log and Electron health panel
- Data/API spec sections 13.1 through 13.6: Nodes, config, pairing, operations,
  and sync exchange endpoint
- Data/API spec sections 14.1 and 14.2: Audit events and sync conflicts
- UI Implementation Contract section 12.9: Orchid / God Mode Admin Screens
- Meridian Alpha 1 tasks M12.1 through M12.11

## Environment

- A dedicated development/QA environment. Any database reset steps in this
  script must not be run against shared or valuable data.
- Repository dependencies installed with approved PHP, Composer, Node.js 24 LTS,
  and pnpm 11.x versions.
- Two isolated Meridian server installs or two isolated local server profiles:
  one central node and one on-site node, each with its own database, `APP_KEY`,
  node keys, and `.env` values.
- The central server reachable from the on-site server at a stable URL. In
  event mode this URL must use HTTPS; plain HTTP is acceptable only for a local
  development-mode rehearsal.
- Orchid/God Mode available on both installs at `/admin`.
- Optional for the Electron health check: the Meridian Electron wrapper can be
  run against the on-site server.

## Personas

- God mode administrator with `platform.node.config` and
  `platform.sync-conflicts`.
- On-site node operator responsible for keeping the event node running during
  intermittent internet.
- Central operations reviewer responsible for post-event reconciliation.
- Human reviewer observing UI, command, database, and audit evidence.

## Setup data

1. From the repository root, install dependencies if needed:
   ```bash
   corepack pnpm install
   composer --working-dir=apps/server install
   ```
2. Prepare two isolated Laravel environments. Use separate databases and ports,
   for example central at `http://127.0.0.1:8001` and on-site at
   `http://127.0.0.1:8002` for development-mode rehearsal.
3. Reset and seed both databases from their corresponding server profiles:
   ```bash
   php apps/server/artisan migrate:fresh --seed
   ```
4. On the central profile, complete `/setup` with node name `qa.central` and
   role `central`.
5. On the on-site profile, complete `/setup` with node name `qa.onsite`, role
   `onsite`, and the central node URL.
6. Pair the on-site node to central by following the central pairing portion of
   `QA-NODE-01-first-run-node-setup.md`.
7. Sign in to Orchid on both installs as a God mode administrator.
8. Confirm both installs show the expected pairing state on
   `/admin/node-config`.

## Steps

### A. Automated sync evidence

1. From `apps/server`, run the node sync, authority, governance freeze, and
   conflict suites:
   ```bash
   php artisan test \
     tests/Feature/NodeOperationSchemaTest.php \
     tests/Feature/NodeOperationSigningTest.php \
     tests/Feature/NodeOperationReceiveApplyTest.php \
     tests/Feature/NodeSyncLoopTest.php \
     tests/Feature/EventAuthorityTest.php \
     tests/Feature/GovernanceEditFreezeTest.php \
     tests/Feature/SyncConflictSchemaTest.php \
     tests/Feature/SyncConflictServiceTest.php \
     tests/Feature/SyncConflictResolverTest.php \
     tests/Feature/SyncConflictOrchidTest.php
   ```
2. From the repository root, run the Electron health panel tests:
   ```bash
   corepack pnpm --filter @meridian/kiosk run test
   ```

### B. Pairing and idle sync state

3. On central, open `/admin/node-config` and confirm the paired on-site node is
   listed or otherwise visible as paired.
4. On on-site, open `/admin/node-config` and confirm pairing status is
   `Paired with central`, the central node name is shown, and the Node sync
   panel is present.
5. Before any queued operations exist, run this on the on-site profile:
   ```bash
   php artisan meridian:node-sync
   ```
6. Refresh `/admin/node-config` on the on-site node.

### C. Outage queue and recovery

7. Stop the central server or block the on-site profile from reaching the
   central URL.
8. Create or replay one supported local node operation from the on-site profile.
   If no product workflow in the current build emits a real operation for the
   targeted entity, use the automated evidence from section A as the canonical
   operation producer and record this as a manual-observation gap rather than
   inventing a fake product action.
9. Run on the on-site profile:
   ```bash
   php artisan meridian:node-sync
   ```
10. Open `/admin/node-config` on the on-site node and confirm queued work is
    visible but is not marked as a fault by itself.
10A. Open the shared client against the **on-site** node and sign in. With
    central still blocked, confirm the app shell shows the banner **Central
    unreachable** — "Local node may work but central sync is unavailable" — and
    not **Online**. This is M18.52: the device asks the on-site node what it can
    reach rather than assuming that reaching the node means reaching everything
    (technical spec 9.6).
10B. With that banner showing, create an incident from the same client. It must
    be accepted. Connected-only work gates on whether a node is reachable, never
    on whether central is; a refusal here is a defect, not a safety feature.
11. Restart or unblock central.
12. Run on the on-site profile:
    ```bash
    php artisan meridian:node-sync
    ```
13. Refresh `/admin/node-config` on both nodes.
13A. Make one request from the shared client — reload a surface — and confirm
    the banner goes silent again, which is what **Online** looks like.

### D. Bidirectional receive/apply and refusal visibility

14. Create or replay one supported operation that originates on central and is
    owed to the on-site node.
15. Run `php artisan meridian:node-sync` from the on-site profile again.
16. Confirm the on-site Node sync panel records a last receipt and separates
    received/applied counts from queued/delivered counts.
17. Attempt one invalid sync exchange, such as a request from an unpaired,
    revoked, stale, or incorrectly signed peer, using automated-test evidence if
    a safe manual peer is not available.
18. Refresh `/admin/node-config` and confirm the refusal appears under recent
    refused exchanges with a stable reason code.

### E. Active-event authority and governance freeze

19. Put the seeded event into its active event window and ensure the on-site
    node is the authoritative on-site primary node for that event.
20. From a non-authoritative node, attempt an event-scoped local write covered
    by M12.6.
21. From any node, attempt a policy/procedure document edit and a fragment edit
    for the active event's organization.
22. Deliver an authentic event-scoped operation from a node that is not the
    authoritative on-site node.

### F. Conflict queue and resolver

23. Produce or replay a sync conflict where local and remote values differ for
    the same operation/entity. If the current product surface cannot yet produce
    a live conflict, use the `SyncConflict*` automated evidence from section A
    and inspect a seeded/factory-created conflict in a disposable QA database.
24. Open `/admin/sync-conflicts`.
25. Open the conflict detail.
26. Confirm local and remote values are read-only, the recommended default is
    visible, and the only resolution actions are **Accept on-site** and
    **Accept central**.
27. Resolve one conflict by accepting on-site.
28. Create or reopen a second disposable conflict and resolve it by accepting
    central.
29. Return to the conflict list and review the corresponding audit events.

### G. Electron health observation

30. Start the on-site Laravel server and shared Kiosk client.
31. Start the Electron wrapper pointed at the on-site server.
32. Open the health panel with `Ctrl+Shift+H` (`Cmd+Shift+H` on macOS).
33. Confirm sync failures or severe unresolved conflicts are visible in the
    health panel when the on-site server health endpoint exposes them. If the
    build still shows placeholder sync fields, record that the God-mode Node
    sync panel is the implemented sync-health evidence and file the M12.10
    follow-up before release.

## Expected results

- The automated suites pass and cover append-only operation storage, operation
  signing/countersigning, idempotent receive-store-apply behavior, the
  bidirectional exchange, active-event authority, governance edit freeze,
  conflict queueing, conflict resolution, Orchid permissions, and Electron
  health rendering.
- Pairing establishes a trusted central/on-site relationship before sync runs.
- `php artisan meridian:node-sync` drains queued operations in both directions
  when the peer is reachable.
- If central is unreachable, the command exits successfully with an outage
  explanation and leaves on-site operations queued for a later run.
- A device working against the on-site node reports **Central unreachable**
  during that outage rather than **Online**, and still accepts connected-only
  work such as incident creation (M18.52; technical spec 9.6).
- Queued work alone appears as a backlog, not as an attention/failure state.
- Applied, unapplied, delivered, refused, last-sent, and last-received states
  are shown distinctly in God Mode Node Configuration.
- Remote operations are stored before application; failures are recoverable and
  do not block unrelated operations.
- Invalid, unauthentic, or unauthorized exchanges are refused, audited, and not
  inserted into `node_operations`.
- During the active event window, event-scoped writes are accepted only from the
  authoritative on-site node, while policy/procedure and fragment edits are
  frozen on every node.
- Sync conflicts appear in the God-mode queue, show read-only local/remote
  values, require a human choice, and audit the selected resolution.
- The Electron health panel either shows the M12.10 sync/conflict signal or the
  reviewer records a release-blocking follow-up that the signal is still
  placeholder-only.

## Evidence to capture

- Terminal output from the server and desktop automated test commands.
- Screenshot of central `/admin/node-config` showing the paired on-site node.
- Screenshot of on-site `/admin/node-config` showing pairing and idle Node sync
  state.
- Screenshot of on-site `/admin/node-config` during an outage showing queued
  work without a failure state.
- Screenshot after recovery showing last sent/received and updated counts.
- Terminal output from `php artisan meridian:node-sync` during outage and after
  recovery.
- Screenshot or audit export showing a refused exchange reason code.
- Screenshot of the active-event authority or governance freeze refusal.
- Screenshot of `/admin/sync-conflicts` list and two conflict detail resolutions.
- Audit evidence for `node_operation.rejected`, `node_sync.refused`, and
  `sync_conflict.resolved` where applicable.
- Screenshot of the Electron health panel sync/conflict state or the recorded
  M12.10 placeholder/follow-up note.

## Failure notes

- If pairing is not established, stop the sync scenario and complete
  `QA-NODE-01` first.
- If operation content or signatures can be edited after insertion, stop testing
  and file a blocking append-only/signature issue.
- If redelivery creates duplicate operations or duplicate domain effects, stop
  testing and file a blocking idempotency issue.
- If an unreachable central node marks queued on-site work as a defect, file a
  sync-health issue; outage backlogs are expected behavior.
- If a refused exchange is stored as a normal operation, or if it lacks audit
  evidence, file a blocking sync security/audit issue.
- If a non-authoritative node can create active-event event-scoped writes, file
  a blocking event-authority issue.
- If policy/procedure or fragment edits succeed during the active event window,
  file a blocking governance-freeze issue.
- If a sync conflict can be edited manually, auto-resolved without a reviewer,
  resolved twice, or resolved without audit evidence, file a blocking conflict
  resolver issue.
- If Electron health hides severe sync failures or severe unresolved conflicts
  in the target M12.10 build, file a blocking on-site health issue before
  release.
