[← Roadmap](ROADMAP.md) · [Back to README](../README.md)

# Project Rules

Top-level axioms. Detailed conventions live in `rules/base.md`.

1. Write code comments in English only.
2. Name functions and methods in `camelCase`; PHP variables in `snake_case`; JS/TS variables in `camelCase`; classes, interfaces, traits, and enums in `PascalCase`.
3. Follow the style of the surrounding code when editing existing files.
4. Keep business logic out of Filament tables, forms, actions, and pages. Put it in jobs and services.
5. Keep shop scope, `family_ulid`, and multilingual rows consistent in every catalog change.
6. Never run `docker system prune`, `docker volume rm`, `DROP DATABASE`, or `TRUNCATE`. Prefer read-only database queries.
7. Do not install or update `composer`/`npm` dependencies or Docker images, and do not restart containers. Ask the developer.
8. Log errors and warnings to the `stack` channel and other messages to the `daily` channel.
9. Do not commit or push unless asked.
10. Update the matching file in `docs/` when behavior changes.

## See Also

- [Base rules](rules/base.md) — detailed conventions.
- [Architecture](ARCHITECTURE.md) — dependency rules.
- [Getting Started](getting-started.md) — local environment.
