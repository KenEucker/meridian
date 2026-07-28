# Meridian Operator Documentation

This is the documentation for people who run a Meridian deployment: node
operators, on-site technical leads, and God Mode users.

It is packaged with the deployment and is served inside the God Mode console
under **Documentation**, so it is readable on an on-site node with no internet
connection.

## What is here

| Document | Read it when |
|---|---|
| [Deployment](deployment.md) | You are standing up a Meridian server for the first time, or moving one. |
| [Node setup and pairing](node-setup-and-pairing.md) | A node has no identity yet, or an on-site node needs to join central. |
| [Configuration](configuration.md) | A setting is not taking effect and you need to know which layer wins. |
| [Data repair](data-repair.md) | Something in the data is wrong and normal product screens cannot fix it. |
| [Sync conflict resolution](sync-conflict-resolution.md) | The conflict queue has entries waiting for a decision. |
| [Break-glass procedures](break-glass.md) | An event is running and something is blocking work right now. |

## What is not here

This tree is operator documentation, not specification. It does not contain the
requirements document, the technical specification, the data/API specification,
UI documentation, QA scripts, architecture decision records, the development
plan, the traceability matrix, or issue documents. Those live elsewhere in
`docs/` and are deliberately not served to the console.

If you are looking for *why* Meridian behaves a certain way, you want the
specification. If you are looking for *what to do right now*, you are in the
right place.

## Before you start

Two things are true of every procedure in this tree:

- **Meridian does not destroy operational history.** Field reports are
  immutable, incidents are not deleted, and completed hours survive later
  changes. If a procedure seems to ask you to erase something, re-read it.
- **Dangerous God Mode actions require a reason.** The reason is recorded in the
  audit log with your user. Write the reason for the next person, not for the
  form.
