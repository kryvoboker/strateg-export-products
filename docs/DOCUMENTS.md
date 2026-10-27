[Back to README](../README.md) · [Documentation index](README.md) · [Setup and operations](setup/README.md)

# Document Conventions

## Rules

- All maintained documentation, plans, ADRs, and skill instructions are written in English.
- Human-authored documentation and AI Factory project artifacts live under `docs/`. Tool configuration stays in `.ai-factory/config.yaml`; skill packages stay in agent skill directories.
- The root `README.md` is a landing page. Topic pages live under `docs/`. Do not create duplicate manuals at the root.
- `AGENTS.md` is the canonical agent instruction file; `CLAUDE.md` only points to it.
- Task decomposition, status, and acceptance criteria live in Beads. `docs/tasks/` is retired and must not be used.
- Every application change that alters behavior or appearance, adds functionality, or removes functionality requires an ADR (see below).
- Create optional artifacts only when needed.

## Artifact Locations

| Artifact                | Path                                                  | Notes                                               |
|-------------------------|-------------------------------------------------------|-----------------------------------------------------|
| Project description     | `docs/DESCRIPTION.md`                                 | Purpose and implemented capabilities                |
| Architecture            | `docs/ARCHITECTURE.md`                                | Single architecture file; no lowercase duplicate    |
| Rules                   | `docs/RULES.md`, `docs/rules/base.md`                 | Axioms and detailed conventions                     |
| Roadmap                 | `docs/ROADMAP.md`                                     |                                                     |
| Security                | `docs/SECURITY.md`                                    |                                                     |
| ADRs                    | `docs/adr/<YYYY>/<MM>/<NNNN>-<short-title>.md`        | Indexed in `docs/adr/README.md`                     |
| Plans                   | `docs/plans/<Y-m-d>-<plan-name>.md`                   | AI Factory plans                                    |
| Patches                 | `docs/patches/<Y-m-d-H.i>.md`                         | Regression notes                                    |
| Reviews                 | `docs/reviews/<YYYY>/<MM>/<NNNN>-<short-title>.md`    | Code review history; created on first review        |
| Playground              | `docs/playground/README.md`                           | Provisional experiments; created when needed        |
| OpenSpec                | `openspec/`                                           | Tool-managed; native workspace stays at repo root   |
| Integration contract    | `docs/openapi.yaml`                                   | Outbound remote-shop contract, not an inbound API   |
| Database migrations     | `app-code/app/database/migrations/`                   | Laravel migrations; no separate `docs/database/` SQL |

## ADR Naming

Use the decision date for `<YYYY>/<MM>`. `<NNNN>` is a zero-padded four-digit serial that increases globally across all ADRs; never restart it by month or year. Follow it with a hyphen and a concise kebab-case title. Preserve accepted records; supersede them through linked successor ADRs. Each ADR states context, date, status, alternatives, decision, and consequences, and is added to [docs/adr/README.md](adr/README.md). ADRs keep rationale; they do not replace Beads.

## Link Conventions

- Relative links only.
- Documentation pages follow the navigation order of the root README table: header line with previous and next links, "See Also" at the end.

## See Also

- [Documentation index](README.md) — all documents.
- [Decisions](adr/README.md) — ADR index.
- [Project rules](RULES.md) — universal rules.
