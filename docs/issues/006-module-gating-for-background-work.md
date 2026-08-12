# 006: Module Gating for Scheduled Jobs and Export Generation

## Type

Hardening and QA (unowned obligation of a governing section)

## Traceability

- Technical spec: Section 15A.5 Enforcement points, fifth bullet ("Background work")
- Requirements: `MOD-018`, `MOD-019`
- Development plan: [M19.12](../process/meridian-alpha-1-development-plan.md) (shipped), [M19.18](../process/meridian-alpha-1-development-plan.md) (shipped)

## Summary

Technical spec 15A.5 names five places a module is enforced and says any one of
them alone is insufficient. Four have landed:

| Enforcement point | Task | State |
|---|---|---|
| HTTP | M19.12 | Shipped |
| Client | M19.16 | Shipped |
| Sync | M19.17 | Shipped |
| Admin console | M19.13, M19.14 | Shipped |
| **Background work** | — | **Partial** |

The fifth reads in full: *"Scheduled jobs, export generation, and notification
producers skip organizations for which their module is inactive."*

M19.18 closed the **notification producer** clause, because MOD-018's "shall not
be presented" reaches a message as surely as it reaches a screen:
`OutstandingRequirementSweep` now skips an organization that does not run
Documents, and skips without writing a delivery record so somebody who was never
told is still owed the message rather than counted as told.

**Scheduled jobs** and **export generation** are not covered, and no task in the
Alpha 1 development plan cites 15A.5 other than M19.12, which has shipped. This
issue records the gap so it has an owner that outlives the M19.18 pull request.

## What is not covered

Two categories, both to be enumerated as the first step of the work rather than
assumed from this list:

1. **Scheduled jobs.** Everything reached from the console kernel schedule —
   credit calculation runs, lifecycle threshold sweeps, audit archival, node
   health reporting — should skip an organization whose owning module is
   inactive, and should be inert rather than erroring where it composes from a
   module that is off.
2. **Export generation.** The five reporting exports are gated at the route and
   at the signed download (M19.12), so no caller reaches a module-owned export
   for an organization that does not run it. What is unexamined is generation
   reached any other way — a queued or scheduled generation path, and the
   module-owned *contributions* inside a core export, on the same rule the
   department operations reads now follow.

## Why it matters

A background producer is the one enforcement point with no caller to refuse. An
inactive module's records still exist (MOD-020 deletes nothing), so a job that
does not ask about module state will happily calculate, export, or sweep over
capability the organization has switched off — and unlike an HTTP request, there
is nobody on the other end to notice the answer was wrong.

## Suggested shape

The seam already exists and needs no new mechanism:
`App\Services\Modules\ActiveModuleResolver` is the single reader of module state,
and `App\Domain\Modules\DomainNamespace` already declares which module owns each
domain. The work is enumerating the producers, declaring what each reads, and
asserting the skip — plus a coverage test in the shape of
`ModuleRouteCoverageTest`, so a scheduled job added later cannot ship ungated.

## Acceptance criteria

- Every scheduled job and every export-generation path declares the module it
  reads from, or declares itself core.
- A job whose module is inactive for an organization skips that organization and
  writes no record for it.
- A coverage test fails when a producer ships without a declaration.
- The traceability matrix row for technical spec section 15A closes.

## Out of scope

- Anything already delivered by M19.12 through M19.18.
- Changing what a module owns. `DomainNamespace` is the declaration and this work
  reads it rather than restating it.
