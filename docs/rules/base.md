# Base Rules

Project-specific conventions. Axioms are in `../RULES.md`.

## Environment

- Linux, Docker-based development. Run application commands in the PHP-FPM container:
  `docker compose -f .docker/dev/docker-compose.yml exec -T dev-strateg-export-products-php-fpm <command>`
- Tests: add or run them only when the user requests it. Command: `php artisan test --compact` (in the container); run the narrowest relevant test first.
- Formatting and static analysis configs live in `app-code/app/` (`pint.json`, `phpcs.xml`, `phpmd.xml`, `phpstan*.neon`, `psalm*.xml`).

## Code Style

- Match the style of the surrounding code; do not reformat unrelated lines.
- Use strict, explicit types and Laravel conventions (Form Requests, Eloquent relations, enums).
- Helper functions in `Supports/helpers.php` stay dependency-light and contain no workflow orchestration.

## Architecture

- Modular monolith. See `../ARCHITECTURE.md` for module boundaries.
- Filament resources collect input and dispatch jobs or call thin services.
- Jobs prepare batches and run workflows. Services own business rules.
- Public methods of Support services are the only entry points other layers may call.

## Data

- Tables use the `spme_` prefix.
- Batch and item tables keep `payload`, `status`, `error_message`, and `processed_at` for traceability.
- After a binding changes shop-scoped catalog entities, invalidate `ProductResourceOptionsService` caches for that shop after commit.
- Store the remote product ID in `spme_product_shop.external_product_id`. Update, delete, and restore depend on it.

## Documentation

- Public Support methods are listed in `../supports.md`; keep it current.
- Regression notes go to `../patches/{Y-m-d-H.i}.md`; plans go to `../plans/`.
- Artifacts and documentation are written in English.
