[← Categories](category-resource.md) · [Back to README](../README.md) · [Stores →](shop-resource.md)

# AttributeResource (Catalog → Attributes)

## Purpose

The resource manages product attribute names, status, sorting, and store bindings.

## Resource Pages

- index (ListAttributes): attribute list.
- create (CreateAttribute): create an attribute.
- edit (EditAttribute): edit an attribute.

Total: 3 pages.

## How to Use

Create or edit the name, sort order, stores, and active state. Use the list for bulk-linking attributes to stores. ProcessAttributeNameTranslationJob runs after create or update.

## Filters and Statuses

Filter by store or by Active / Inactive status.

## Notifications

The resource reports missing store selections and summarizes the number of attributes and stores processed by a bulk binding.

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Categories](category-resource.md) — related catalog categories.
- [Stores](shop-resource.md) — store bindings.
