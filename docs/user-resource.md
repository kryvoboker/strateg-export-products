[← Store Languages](shop-language-resource.md) · [Back to README](../README.md)

# UserResource (Users)

## Purpose

The resource manages administration-panel users: profile data, password, active state, avatar, and email verification.

## Resource Pages

- index (ListUsers): user list.
- create (CreateUser): create a user.
- edit (EditUser): edit a user.

Total: 3 pages.

## How to Use

- Enter name, lastname, email, and telephone.
- Optionally upload an avatar.
- Set and confirm a password.
- Enable or disable the user's active state.

## Important Constraints

- Bulk deletion checks the app.denied_delete_emails list.
- If the selection contains a protected user, deletion is cancelled and an error is shown.

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Stores](shop-resource.md) — related administration settings.
- [Product Catalog](product-resource.md) — catalog workflows.
