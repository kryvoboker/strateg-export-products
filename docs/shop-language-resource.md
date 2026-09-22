[← Stores](shop-resource.md) · [Back to README](../README.md) · [Users →](user-resource.md)

# ShopLanguageResource (Store Languages)

## Purpose

The resource manages each store's language code, name, active state, and default language.

## Resource Pages

- index (ListShopLanguages): language list.
- create (CreateShopLanguage): create a language.
- edit (EditShopLanguage): edit a language.

Total: 3 pages.

## How to Use

1. Select a store.
2. Enter code (uk, en, de, and so on), name, and active state.
3. Optionally mark the language as default.

## Constraints and Errors

- A language code must be unique within a store.
- Each store must have at least one default language and cannot have more than one default language.
- Validation failures display a danger notification.

The Store Languages relation manager on the store page also displays the default-language column.

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Stores](shop-resource.md) — parent store settings.
- [Product Catalog](product-resource.md) — multilingual catalog data.
