# Strateg Export Products

> An administration system for preparing a product catalog and synchronizing products with online stores.

The project imports products from Excel, Google Sheets, and the administration panel, normalizes the catalog in PostgreSQL, links products to stores, and performs export, update, deletion, and restoration through store APIs.

## Quick Start

    make build-dev
    make up-dev

Open the administration panel at the address configured for the development environment. Stop the containers with make down-dev.

## Example workflow

1. Create a Product Import batch from an Excel file or Google Sheets URL.
2. Wait for normalization, then bind successful products to a store.
3. Export the bound products and use the saved external IDs for later updates, deletion, or restore.

## Features

- Import products from Excel, Google Sheets, and manual entries.
- Manage products, categories, attributes, brands, and manufacturers.
- Link product families to multiple stores.
- Queue-based bulk export, update, deletion, and restoration.
- Multilingual store descriptions and SEO URLs.
- Integration with OpenCart-like and configurable external APIs.

## Main Commands

    make vite
    make vite-build
    docker compose -f .docker/dev/docker-compose.yml exec -T dev-strateg-export-products-php-fpm php artisan test --compact

## Documentation

| Section                                                  | Description                                   |
|----------------------------------------------------------|-----------------------------------------------|
| [Getting Started](docs/getting-started.md)               | Requirements, startup, and environment checks |
| [Architecture](docs/architecture.md)                     | Modules, queues, and data flow                |
| [Configuration](docs/configuration.md)                   | Environment and store settings                |
| [Helper Functions](docs/helpers.md)                      | Functions from app/Supports/helpers.php       |
| [Supports Reference](docs/supports.md)                   | All Supports service methods                  |
| [Product Data Workflows](docs/product-data-workflows.md) | Import, export, update, delete, and restore  |
| [Product Import](docs/product-import-batch-resource.md)  | Import batches and item processing            |
| [Product Updates](docs/product-update-batch-resource.md) | Batch and point updates                       |
| [Product Deletes](docs/product-delete-batch-resource.md) | Remote shop deletion batches                 |
| [Product Catalog](docs/product-resource.md)              | Local catalog operations                      |
| [Remote API Integration](docs/remote-api-integration.md) | Outbound shop requests and API status        |
| [OpenCart API Token Lifecycle](docs/opencart-api-token-lifecycle.md) | Session token login, reuse, and refresh |
| [OpenAPI Specification](docs/openapi.yaml)               | Swagger-readable remote integration contract |
| [Categories](docs/category-resource.md)                  | Category hierarchy and binding                |
| [Attributes](docs/attribute-resource.md)                 | Attribute management and binding              |
| [Stores](docs/shop-resource.md)                          | External store configuration                  |
| [Store Languages](docs/shop-language-resource.md)        | Languages and the default language            |
| [Users](docs/user-resource.md)                           | Administration panel users                    |

## License

The project is distributed under the MIT license.
