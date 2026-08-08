# Break-glass procedures

For when an event is running and something is blocking work right now.

Read this before you need it. Everything here is faster with a plan than
without.

## The rule

Break glass to unblock the event. Then write down what you broke, so the next
person is not debugging your fix.

Every break-glass action goes in the audit log with your user, the entity, the
before and after values, and your reason. The reason is the part that matters
later.

## Nobody can sign in

**Symptom.** Staff cannot get a magic link, or OAuth is failing.

1. Check whether the node has outbound mail and outbound internet. During an
   event it may have neither by design.
2. Kiosk and shared workstations use login codes, not magic links. If personal
   devices are stuck, a shared workstation may still work.
3. A login code needs nothing but this node. Somebody who still holds a session
   on their own phone can generate their own code for the workstation in front of
   them, with no internet, no mail, and no help from you. Point them at it before
   you do anything else — it is the fastest fix in this document.
4. If they hold no session at all, generate a code for them in God Mode under
   **Workstation Login Codes**. Pick the person and the workstation, and read the
   code to them. It is shown once and is not recoverable; if it is lost, generate
   another. A code lasts six weeks, works only at the workstation it was
   generated for, and can be revoked from the same screen.
5. Do not create accounts by hand to get around a mail problem. A verified email
   resolves to exactly one user, and hand-made duplicates have to be merged
   afterwards.

## Nobody can be checked in

**Symptom.** The Shift Lead Board refuses check-in.

1. Confirm the person is eligible: assigned to the shift or eligible to be added
   unscheduled, with the required training and waiver complete. The refusal
   states which one is missing — read it before overriding anything.
2. If the requirement is genuinely satisfied and the record is wrong, repair the
   input record in God Mode with a reason. Do not fabricate an attendance record
   with no shift; hours cannot exist without a shift and department, and one made
   that way will be rejected.
3. Devices can record attendance offline. If the network is the problem, let the
   device queue and sync later rather than working around it centrally.

## The on-site node cannot reach central

**Symptom.** Queued operations climb and nothing is delivered.

This is a supported state, not an outage. The on-site node is authoritative for
event-scoped writes during the active event window and queues everything for
central. Keep running the event.

Escalate only if the queue stops draining after connectivity is restored — check
**Node Configuration → Node sync** for refused exchanges, which are recorded in
the audit log because a refused exchange is never stored as an operation.

## Central is refusing an edit during the event

**Symptom.** An organizer cannot change something on central and gets an
authority refusal.

That is the governance rule: during the active event window, event-scoped writes
belong to the on-site node, and governance data such as policies, procedures, and
branding is frozen. Make the change on the on-site node, or wait for the window
to close.

Do not disable the window to get an edit through. The window is what stops two
nodes writing the same records at once.

## Event mode will not start

**Symptom.** The node refuses to boot or refuses a role change, citing HTTPS or
the offline read set.

Meridian fails closed here on purpose. Fix the cause:

- HTTPS: the configured application URL must use HTTPS, and the certificate must
  be trusted by the devices in use.
- Offline read set: the node must be able to serve it. A node that cannot is
  usually one whose route or configuration caches are stale — clear them and
  restart the server.

If the event genuinely cannot have either, the node can run as `development` —
but understand what that turns off, and do not use it for a real event with real
personal data.

## A God Mode user is locked out of God Mode

A user without console permissions still reaches their profile and can sign out.
Restoring console access needs another user who already has it. Keep more than
one God Mode account, and do not let both live on the same laptop.

## After the event

- Clear the sync conflict queue: [Sync conflict resolution](sync-conflict-resolution.md).
- Read back your own audit entries and confirm each reason still makes sense.
- File what you had to break glass for. A repeated break-glass is a missing
  feature.
