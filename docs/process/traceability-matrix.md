# Meridian Traceability Matrix

This matrix links Meridian requirements and technical specification sections to implementation issues, pull requests, automated checks, and human QA scenarios.

| Requirement / Spec Section | Status | Issue | PR | Automated tests | Human QA scenario | Notes |
|---|---|---|---|---|---|---|
| Technical spec: Section 4 Repository and Package Topology | Complete | [001](../issues/001-monorepo-scaffold.md) |  | Process validators | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | Monorepo application, package, and deployment locations are established as placeholders without product behavior. |
| Technical spec: Section 29 Implementation Order | In progress | [001](../issues/001-monorepo-scaffold.md), [002](../issues/002-laravel-postgresql-orchid-boot-path.md), [003](../issues/003-ci-baseline.md), [004](../issues/004-branch-protection-setup-note.md) |  | Process validators; branch protection human confirmation | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | Milestone 0 process baseline is established; product implementation should continue in the Alpha 1 plan order. |
| Technical spec: Section 27.1 Alpha 1 acceptance target | In progress | [002](../issues/002-laravel-postgresql-orchid-boot-path.md) |  | Laravel default tests; `composer validate` | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | Bootable server foundation started with the Laravel scaffold (M1.1). |
| Technical spec: Section 3.1 Server/admin application | In progress | [002](../issues/002-laravel-postgresql-orchid-boot-path.md) |  | Laravel default tests; `composer validate` | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | Laravel app scaffolded under `apps/server` (M1.1); PostgreSQL (M1.2) and Orchid (M1.3) follow. |
| Technical spec: Section 5.1 Core server | In progress | [002](../issues/002-laravel-postgresql-orchid-boot-path.md) |  | Laravel default tests; `composer validate`; `DatabaseConfigurationTest`; CI PostgreSQL migration check | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | Core server framework (Laravel) established (M1.1); PostgreSQL 18.x configured as the development database with documented connection setup (M1.2); Orchid (M1.3), OpenAPI, Docker Compose deployment bundle, and PowerSync arrive in later tasks. |
| Data/API spec: Section 3.1 Canonical Source of Truth | In progress | [002](../issues/002-laravel-postgresql-orchid-boot-path.md) |  | `DatabaseConfigurationTest`; CI PostgreSQL migration check | [QA-BOOT-01](../qa/QA-BOOT-01-fresh-checkout-boots.md) | PostgreSQL is the configured development database so Laravel migrations apply against the canonical store (M1.2); canonical domain schema arrives in later tasks. |
| ORG-001 | Not started |  |  | Pending product tests |  | Organizations produce events and manage volunteers. |
| ORG-002 | Not started |  |  | Pending product tests |  | Organizations define departments. |
| TEAM-002 | Not started |  |  | Pending product tests |  | Each department has a default team. |
| VOL-006 | Not started |  |  | Pending product tests |  | Department membership requires at least one team. |
| SLB-005 | Not started |  |  | Pending product tests |  | Check-out creates an actual hours record. |
| FR-007 | Not started |  |  | Pending product tests |  | Field reports are immutable after submission. |
| FR-012 | Not started |  |  | Pending product tests |  | Linked field report content is copied into incident notes. |
| INC-014 | Not started |  |  | Pending product tests |  | Incident changes are preserved in history. |
