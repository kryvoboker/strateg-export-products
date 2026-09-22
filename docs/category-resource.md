[← Product Catalog](product-resource.md) · [Back to README](../README.md) · [Attributes →](attribute-resource.md)

# CategoryResource (Catalog → Categories)

## Purpose

The resource manages category names, hierarchy, status, sorting, store bindings, and child-category tree views.

## Resource Pages

- index (ListCategories): category list.
- create (CreateCategory): create a category.
- edit (EditCategory): edit a category.

Total: 3 pages.

## How to Use

Create or edit the name, parent category, sort order, stores, and active state. The list can show child categories and bulk-link categories to stores. ProcessCategoryNameTranslationJob runs after create or update.

## Filters and Statuses

Filter by store or by Active / Inactive status.

## Main Actions

- Show child categories.
- Show all categories to clear the subtree filter.
- Bulk-link categories to stores.

## Deletion

Deletion on the edit page requires confirmation and displays the number of child categories.

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Attributes](attribute-resource.md) — related catalog attributes.
- [Stores](shop-resource.md) — store bindings.
