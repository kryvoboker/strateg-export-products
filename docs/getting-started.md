[← Contents](README.md) · [Back to README](../README.md) · [Architecture →](architecture.md)

# Getting Started

## Requirements

- Docker Compose.
- GNU Make.
- Access to the local domain configured in .docker/dev/docker-compose.yml.

The application runs in Docker containers with PHP 8.5, Laravel 12, Filament 5, Livewire 4, and PostgreSQL 18.

## Start the Development Environment

Run from the repository root:

    make build-dev
    make up-dev

build-dev builds local images. up-dev starts PHP-FPM, Nginx, PostgreSQL, Redis, and cron. If images already exist, run only make up-dev.

## Check the Status

    docker compose -f .docker/dev/docker-compose.yml ps

PHP-FPM and Nginx should be Up; PostgreSQL and Redis should pass their health checks.

## Application Commands

Run Artisan commands inside the PHP-FPM container:

    docker compose -f .docker/dev/docker-compose.yml exec -T dev-strateg-export-products-php-fpm php artisan route:list

Run tests with the same command and php artisan test --compact.

## Stop the Environment

    make down-dev

## Next Steps

Configure the environment and stores using [Configuration](configuration.md), then read [Architecture](architecture.md).

## See Also

- [Configuration](configuration.md) — environment and store settings.
- [Architecture](architecture.md) — modules and queues.
