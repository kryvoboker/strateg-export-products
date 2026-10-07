[← Attributes](attribute-resource.md) · [Back to README](../README.md) · [Store Languages →](shop-language-resource.md)

# ShopResource (Stores)

## Purpose

The resource manages online stores to which products, categories, and attributes are linked and where API requests are sent.

## Resource Pages

- index (ListShops): store list.
- create (CreateShop): create a store.
- edit (EditShop): edit a store.

Total: 3 pages.

## Configuration Fields

Configure name, type, base_url, api_url, `part_api_url_login`, `part_api_url_export_prods`, `part_api_url_update_prods`, `part_api_url_delete_prods`, `part_api_url_restore_prods`, `api_token`, `options`, and `is_active`. Optional operation-specific endpoint overrides and `api_timeout` are read from shop options.

## Important Notes

- If api_url is empty, the API logic uses base_url.
- Manage each store's languages through the Store Languages relation manager.
- Store-specific external product identifiers are required for later update, deletion, and restoration.
- The OpenAPI document describes outbound requests from this application; it is not an inbound API contract. See [Remote API Integration](remote-api-integration.md).

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Store Languages](shop-language-resource.md) — language configuration.
- [Product Catalog](product-resource.md) — store bindings.
