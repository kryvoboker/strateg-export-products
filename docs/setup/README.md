[Back to README](../../README.md) · [Documentation index](../README.md) · [Agent rules](../../AGENTS.md)

# Setup and Operations

Read this guide at the start of every work session, then apply the rules in [AGENTS.md](../../AGENTS.md).

## Environment

| Item             | Value                                                                                      |
|------------------|--------------------------------------------------------------------------------------------|
| Application      | Strateg Export Products (Laravel 13, Filament 5, Livewire 4, PostgreSQL 18)                |
| Application code | `app-code/app/` (Laravel source in `app-code/app/app/`)                                    |
| Compose file     | `.docker/dev/docker-compose.yml`                                                           |
| PHP container    | `dev-strateg-export-products-php-fpm`                                                      |
| Database         | `dev-strateg-export-products-postgresql` (host port `3343`)                                |
| Admin panel      | `http://dev.strateg-export-products.com/opa-chirik`                                        |
| Environment vars | `.docker/dev/env/` (never commit or print values)                                          |

Start and stop the stack with `make build-dev`, `make up-dev`, and `make down-dev`. See [Getting Started](../getting-started.md) for details.

Run application commands inside the PHP container:

    docker compose -f .docker/dev/docker-compose.yml exec -T dev-strateg-export-products-php-fpm <command>

Do not install or update `composer` or `npm` dependencies, update Docker images, or restart containers yourself. Ask the developer.

## Required Work Sequence

1. **Beads** — verify the workspace, then find or create and claim an issue.
2. **Context7** — only when external library or API behavior is in question.
3. **Mem0** — search reusable knowledge; accept only records that start with `Strateg Export Products: `.

## Tool Health Gate

Run these checks when onboarding or when tool health is stale. Installation output alone is not an operational check. If a required tool fails, stop implementation, diagnose read-only, record the sanitized command and error, and report it.

| Tool       | Run on | Check                                                                     | Expected result                                                           |
|------------|--------|---------------------------------------------------------------------------|---------------------------------------------------------------------------|
| Beads      | host   | `bd where`                                                                | Workspace is this repository's `.beads`, prefix `strateg-export-products` |
| Local Mem0 | host   | `local-mem0 search "<topic>"`                                             | JSON `results` payload, exit code 0                                       |
| OpenSpec   | host   | `openspec list`                                                           | Lists changes or reports none                                             |
| Graphify   | host   | `graphify --help`                                                         | Command list; `graphify-out/` is created by `graphify update .`           |
| Context7   | host   | `npx ctx7@latest library "<official library name>" "<specific question>"` | Library IDs returned                                                      |

Notes:

- Run Local Mem0, Graphify, and Context7 on the host, not in Docker.
- Beads is limited to this repository. Never read or modify another project's Beads data.
- `graphify-out/` does not exist yet; build it with `graphify update .` before using `graphify query`.
- A missing Dolt remote warning from Beads is expected in the local-only profile.

## Beads Basics

    bd where                       # confirm the workspace first
    bd ready                       # find available work
    bd show <id>                   # read an issue
    bd update <id> --claim         # claim before working
    bd close <id>                  # close completed work

Task content, acceptance criteria, dependencies, and progress live in Beads only. Do not create task documents under `docs/tasks/`.

## Git Profile

Conservative, local-only. Do not commit, push, pull, or sync unless explicitly requested. Preserve unrelated user changes. Do not add or run application tests unless the user asks; see [Testing](../testing.md).

## See Also

- [Documentation index](../README.md) — all documents and owners.
- [Document conventions](../DOCUMENTS.md) — canonical sources and AI Factory paths.
- [Getting Started](../getting-started.md) — start the containers.