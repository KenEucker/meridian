# Meridian Traceability Matrix

This matrix links Meridian requirements and technical specification sections to implementation issues, pull requests, automated checks, and human QA scenarios.

| Requirement / Spec Section | Status | Issue | PR | Automated tests | Human QA scenario | Notes |
|---|---|---|---|---|---|---|
| Technical spec: Section 4 Repository and Package Topology | In progress | [001](../issues/001-monorepo-scaffold.md) |  | Process validators | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | Monorepo application, package, and deployment locations are established as placeholders without product behavior. |
| Technical spec: Section 29 Implementation Order | In progress | [001](../issues/001-monorepo-scaffold.md), [002](../issues/002-laravel-postgresql-orchid-boot-path.md), [003](../issues/003-ci-baseline.md) |  | Process validators | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | Establish implementation order and first foundation slices. |
| Technical spec: Section 27.1 Alpha 1 acceptance target | Not started | [002](../issues/002-laravel-postgresql-orchid-boot-path.md) |  | Pending product tests | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | Alpha 1 proof target begins with bootable foundation. |
| ORG-001 | Not started |  |  | Pending product tests |  | Organizations produce events and manage volunteers. |
| ORG-002 | Not started |  |  | Pending product tests |  | Organizations define departments. |
| TEAM-002 | Not started |  |  | Pending product tests |  | Each department has a default team. |
| VOL-006 | Not started |  |  | Pending product tests |  | Department membership requires at least one team. |
| SLB-005 | Not started |  |  | Pending product tests |  | Check-out creates an actual hours record. |
| FR-007 | Not started |  |  | Pending product tests |  | Field reports are immutable after submission. |
| FR-012 | Not started |  |  | Pending product tests |  | Linked field report content is copied into incident notes. |
| INC-014 | Not started |  |  | Pending product tests |  | Incident changes are preserved in history. |
