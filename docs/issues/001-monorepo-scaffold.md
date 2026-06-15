# 001: Monorepo Scaffold

## Type

Technical foundation

## Traceability

- Technical spec: Section 4 Repository and Package Topology
- Technical spec: Section 28 Implementation Order

## Summary

Create the initial Meridian monorepo structure without implementing product behavior.

## Acceptance criteria

- `apps/server`, `apps/mobile`, and `apps/desktop` locations are established or explicitly documented if deferred.
- `packages/shared-types` and `packages/openapi-client` locations are established or explicitly documented if deferred.
- `deploy/docker`, `deploy/caddy`, `deploy/powersync`, and `deploy/dns` locations are established or explicitly documented if deferred.
- README documents the intended workspace layout.
- Process checks continue to pass.
- No Meridian product workflows are implemented.

## Automated tests

- `scripts/process/check.sh`

## Human QA

- `docs/qa/QA-BOOT-01-fresh-checkout-boots.md`

## Notes

This is the first implementation-order slice and should stay focused on repository shape.
