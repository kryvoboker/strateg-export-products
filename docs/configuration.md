[← Architecture](ARCHITECTURE.md) · [Back to README](../README.md) · [Helper Functions →](helpers.md)

# Configuration

## Environment Files

Development Docker configuration is stored in .docker/dev/env/. The .docker/dev/docker-compose.yml file loads shared and PostgreSQL variables into containers.

The Laravel application is located in app-code/app. Its standard Laravel settings (.env and config/*.php) are applied inside PHP-FPM.

Do not add passwords, API tokens, or other secrets to documentation or Git. Use container environment values for database access.

## Stores

Configure a store in the administration panel through the Shops resource.

| Field                     | Purpose                                         |
|---------------------------|-------------------------------------------------|
| name                      | Store name                                      |
| type                      | Integration type, for example opencart 4        |
| base_url                  | External store base URL                         |
| api_url                   | Custom API URL                                  |
| part_api_url_login        | Relative authentication endpoint                |
| part_api_url_export_prods | Product export endpoint                         |
| api_token                 | Token when required by the integration          |
| options                   | Additional endpoints and integration parameters |
| is_active                 | Whether the store participates in operations    |

For OpenCart-like APIs, configure update, restore, deletion, and backup endpoints through options. The external product identifier is stored separately for each product-to-store relationship.

## Languages

Create store languages through Store Languages. Each store must have one default language. Language records are used for descriptions, attributes, and SEO URLs.

## Queues and Background Tasks

Import, binding, export, update, deletion, and restoration run as queued jobs.

| Setting            | Value                                                                                               |
|--------------------|-----------------------------------------------------------------------------------------------------|
| Queue driver       | `QUEUE_CONNECTION`, default `database`                                                              |
| Cache store        | `CACHE_STORE`, default `database`                                                                   |
| Worker             | The `cron` container runs `php artisan schedule:run` every minute                                   |
| Scheduled command  | `ProcessQueueOnceCommand` (`app:process-queue-once-command`) runs `queue:work` once and exits       |
| Overlap protection | `withoutOverlapping(181)` prevents a second worker from starting for up to 181 minutes              |

No separate queue worker container is defined in `.docker/dev/docker-compose.yml`; jobs start within a minute after dispatch while the `cron` container is running.

Check batch and item statuses in the administration panel. External API errors are stored at operation level so the operation can be retried.

## See Also

- [Getting Started](getting-started.md) — start the containers.
- [Stores](shop-resource.md) — store resource fields.
- [Store Languages](shop-language-resource.md) — configure languages.