[← Product Catalog](product-resource.md) · [Back to README](../README.md) · [OpenCart API Token Lifecycle →](opencart-api-token-lifecycle.md)

# Remote API Integration

## Application API status

The repository does not define an inbound JSON API. `routes/web.php` contains only the welcome route, and there is no `routes/api.php` or checked-in OpenAPI/Swagger specification. The administration interface is provided by Filament and Livewire.

This page explains how the outbound integration is configured. The Swagger-readable contract is in [openapi.yaml](openapi.yaml).

## Remote request prerequisites

Before export, update, delete, or remote restore, the workflow needs:

- an active shop with `base_url` or `api_url`;
- the matching endpoint field in the shop record or operation options;
- credentials accepted by the remote shop;
- a local `spme_product_shop` binding; and
- an `external_product_id` for update, delete, and restore.

The default client sends form-encoded `POST` requests. A configured `api_token` is sent as a bearer token; optional `api_header_name` and `api_header_value` are also supported. The timeout is configurable through `options.api_timeout` and defaults to 30 seconds, with a minimum of 5 seconds.

## Remote operations

| Operation                              | Endpoint source                                                                         | Payload builder or job                                           | Success requirement                                             |
|----------------------------------------|-----------------------------------------------------------------------------------------|------------------------------------------------------------------|-----------------------------------------------------------------|
| Authentication for OpenCart-like shops | `part_api_url_login`                                                                    | `InteractsWithShopApi`                                           | Response contains `api_token`                                   |
| Export                                 | `product_export_endpoint` or `part_api_url_export_prods`                                | `ProcessProductExportItemJob` and `ProductPayloadBuilderService` | Response contains an external product ID                        |
| Update                                 | `product_update_endpoint`, `part_api_url_update_prods`, or the export endpoint fallback | `ProcessProductUpdateItemJob`                                    | Successful HTTP response                                        |
| Backup before update/delete            | `product_backup_endpoint` or `part_api_url_backup_prods` in options                     | Update/delete item jobs                                          | Successful backup response is persisted as an external snapshot |
| Delete                                 | `product_delete_endpoint` or `part_api_url_delete_prods`                                | `ProcessProductDeleteItemJob`                                    | Successful HTTP response                                        |
| Restore                                | `product_restore_endpoint` or `part_api_url_restore_prods`                              | `ProcessCatalogProductRestoreItemJob`                            | Successful HTTP response                                        |

For OpenCart-like shops, the job obtains or reuses an auth API token, retries once after an invalid-token response, and stores the refreshed token in shop options. See [OpenCart API Token Lifecycle](opencart-api-token-lifecycle.md) for the full login, expiry, retry, and known-gap details.

## Payload identity and scope

Remote product operations are scoped by both `product_id` and `shop_id`. The payload builder filters shop-scoped categories, attributes, manufacturer, brand, descriptions, and language rows for the selected shop. The remote product identifier is read from the product/shop binding rather than guessed from model or EAN.

## Failure handling

Non-successful responses, missing endpoint configuration, invalid URLs, missing credentials, missing product bindings, and missing response identifiers are converted into failed item records. Jobs retain a truncated response body or error message for operator diagnostics and synchronize the parent batch status.

## OpenAPI limitations

The specification documents the logical operations implemented by the application. Map its logical paths to each shop's configured endpoint fields. Remote shops may require additional fields or return different response shapes, so verify the document against the contract of each configured shop before using it as a client-generation source.

## See Also

- [Product Data Workflows](product-data-workflows.md) — lifecycle and prerequisites.
- [Stores](shop-resource.md) — shop endpoint configuration.
- [Product Updates](product-update-batch-resource.md) — update inputs and queue actions.