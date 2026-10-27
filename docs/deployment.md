[Back to README](../README.md) · [Documentation index](README.md) · [Configuration](configuration.md)

# Deployment

## Development Stack

Docker Compose file: `.docker/dev/docker-compose.yml`. Services:

| Service        | Container                                  | Role                                          |
|----------------|--------------------------------------------|-----------------------------------------------|
| PHP-FPM        | `dev-strateg-export-products-php-fpm`      | Laravel application                           |
| Cron           | `dev-strateg-export-products-cron`         | Runs `schedule:run` every minute (queue worker) |
| Nginx          | `dev-strateg-export-products-nginx`        | Web server behind Traefik (`dev.strateg-export-products.com`) |
| PostgreSQL     | `dev-strateg-export-products-postgresql`   | Database, host port `3343`                    |

Commands: `make build-dev`, `make up-dev`, `make down-dev`, `make restart-dev`, `make rebuild-dev`.

## Production

The `Makefile` defines `up-prod`, `down-prod`, `build-prod`, `restart-prod`, and `rebuild-prod` against `.docker/prod/docker-compose.yml`. That file is not in the repository, so these targets do not work as shipped. Add the production compose configuration before relying on them.

## Release Flow

There is no automated release pipeline in the repository. Before a release:

1. Review `git status` and the diff for secrets (see [Security](SECURITY.md)).
2. Install dependencies and build assets (`composer install`, `npm run build`) — done by the developer.
3. Run database migrations (`php artisan migrate`) in the PHP container after reviewing them.
4. Confirm the `cron` container is running so queued jobs are processed.
5. Check batch and item statuses in the administration panel after the first operations.

`.rsyncignore` lists paths excluded when files are synchronized to a server (VCS data, `.env` files, caches, logs, `graphify-out/`).

Do not deploy without explicit authorization.

## See Also

- [Getting Started](getting-started.md) — local startup.
- [Configuration](configuration.md) — environment and queues.
- [Security](SECURITY.md) — pre-release checklist.
