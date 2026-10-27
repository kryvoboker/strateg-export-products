[Back to ADR index](../../README.md) · [Documentation index](../../../README.md)

# 0001. Canonical Documentation Structure

- **Date:** 2026-10-22
- **Status:** Accepted
- **Related work:** Beads issue `strateg-export-products-k2u`

## Context

Documentation was split between a lowercase `docs/architecture.md` summary and the AI Factory `docs/ARCHITECTURE.md`. The global agent instructions require a setup guide, a documentation index, document conventions, and an ADR index. `AGENTS.md` and `CLAUDE.md` already point to `docs/setup/README.md`, which did not exist.

## Decision

- Keep one architecture document, `docs/ARCHITECTURE.md`, matching `paths.architecture` in `.ai-factory/config.yaml`. Remove `docs/architecture.md`.
- Add `docs/setup/README.md`, `docs/README.md`, `docs/DOCUMENTS.md`, and `docs/adr/`, plus `api.md`, `testing.md`, and `deployment.md` pages.
- Track tasks in Beads only; do not create `docs/tasks/`.
- Keep AI Factory rules at `docs/rules/base.md` as configured in `.ai-factory/config.yaml`.

## Alternatives Considered

- Keep both architecture files: rejected, they diverge and collide on case-insensitive file systems.
- Move rules to `.ai-factory/rules/base.md`: not chosen, `.ai-factory/config.yaml` already points to `docs/rules/base.md`.

## Consequences

- Links to the old `architecture.md` were updated to `ARCHITECTURE.md`.
- New contributors and agents have a single entry point at `docs/setup/README.md`.
- Optional artifacts (`reviews/`, `playground/`) are created only when first needed.
