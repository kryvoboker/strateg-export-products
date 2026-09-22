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

Configure name, type, base_url, api_url, part_api_url_login, part_api_url_export_prods, api_token, options, and is_active.

## Important Notes

- If api_url is empty, the API logic uses base_url.
- Manage each store's languages through the Store Languages relation manager.
- Store-specific external product identifiers are required for later update, deletion, and restoration.

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Store Languages](shop-language-resource.md) — language configuration.
- [Product Catalog](product-resource.md) — store bindings.
