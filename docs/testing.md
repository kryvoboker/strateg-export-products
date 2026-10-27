[Back to README](../README.md) · [Documentation index](README.md) · [Setup and operations](setup/README.md)

# Testing

## Policy

Do not add or run application tests unless the user requests testing or verification. Mandatory tool health checks and documentation reviews are separate from application tests.

## Layout

Tests live in `app-code/app/tests/` (PHPUnit through Laravel).

| Suite   | Directory       | Content                                                                         |
|---------|-----------------|---------------------------------------------------------------------------------|
| Unit    | `tests/Unit`    | Services, jobs logic, models, Filament forms and tables, update instructions    |
| Feature | `tests/Feature` | Catalog behavior, Filament pages, jobs, and product imports                     |

Configuration: `phpunit.xml` (and `phpunit.live.xml` with the same suites). Static analysis and style configs sit beside them: `pint.json`, `phpcs.xml`, `phpmd.xml`, `phpstan*.neon`, `psalm*.xml`.

## Running Tests

Run inside the PHP container:

    docker compose -f .docker/dev/docker-compose.yml exec -T dev-strateg-export-products-php-fpm php artisan test --compact

Narrow the run with `--filter=<TestName>` or a file path. Run the smallest relevant test first.

## Verification Limits

- `phpunit.xml` only forces `APP_ENV=testing`; it does not switch to an isolated database. Check the active database connection before running tests that write data. Only one test file uses a database refresh trait.
- `SingleProductExportToShopOneLiveTest` calls a real shop. It is skipped unless `LIVE_OPENCART_EXPORT_TEST=true` is set and the main `pgsql` database has the schema. Never run it without explicit authorization.
- Do not run destructive database commands (`TRUNCATE`, `DROP DATABASE`) to prepare tests.
- Frontend changes are verified by `npm run build` or `npm run dev`, which the developer runs.

## See Also

- [Setup and operations](setup/README.md) — environment and commands.
- [Project rules](RULES.md) — safety rules.
- [Architecture](ARCHITECTURE.md) — modules under test.
