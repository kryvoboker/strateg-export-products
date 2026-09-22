# Strateg Export Products

A service for importing, processing, binding, exporting, and updating products between the local catalog and online stores through Excel, Google Sheets, and APIs.

## Development

From the repository root:

    make build-dev
    make up-dev

Alternatively:

    docker compose -f .docker/dev/docker-compose.yml build
    docker compose -f .docker/dev/docker-compose.yml up -d

## Production

    make build-prod
    make up-prod

## Stop Containers

    make down-dev
    make down-prod

## Frontend

Run the development watcher:

    make vite

Build production assets:

    make vite-build

## Tests

Run the full test suite in the PHP container:

    docker compose -f /home/kamaz/www/strateg-projects/strateg-export-products/.docker/dev/docker-compose.yml exec -T dev-strateg-export-products-php-fpm php artisan test --compact

Run one test file:

    docker compose -f /home/kamaz/www/strateg-projects/strateg-export-products/.docker/dev/docker-compose.yml exec -T dev-strateg-export-products-php-fpm php artisan test --compact tests/Feature/SomeTest.php
