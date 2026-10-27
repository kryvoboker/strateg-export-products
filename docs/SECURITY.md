[← Project Description](DESCRIPTION.md) · [Back to README](../README.md) · [Roadmap →](ROADMAP.md)

# Security

## Scope

An internal admin application. It stores credentials for external shops, calls remote shop APIs, and sends catalog text to an AI provider. It is not intended for public access.

## Assets To Protect

| Asset                      | Location                                              | Notes                                                    |
|----------------------------|-------------------------------------------------------|----------------------------------------------------------|
| Shop API keys              | `spme_shops.api_token`                                | Used to log in to the shop API                           |
| Shop session tokens        | `spme_shops.options.auth_api_token`                   | Short-lived; refreshed automatically                     |
| Shop API usernames         | `spme_shops.options.api_username`                     |                                                          |
| Environment secrets        | `.docker/dev/env/`, `app-code/app/.env`               | DB, OpenAI, Google Sheets, Telegram credentials          |
| Product backups            | `spme_product_backups.payload`                        | Full product snapshots                                   |
| Batch error logs           | `storage/app/public/logs/product-batches/`            | Public disk; must not contain secrets                    |

## Rules

- Never commit `.env` files, credentials, `login-credentials.md`, or tokens. Keep them out of logs, error messages, and payload snapshots.
- Admin panel access goes through Filament authentication and role permissions (Filament Shield). New resources and actions must be covered by permissions.
- Validate all uploaded files and Google Sheets input before parsing. Treat spreadsheet cell values as untrusted data.
- Outbound shop requests use the shop's configured `base_url`/`api_url` only. Do not build request targets from imported data.
- Do not log full request payloads or credentials. Use the `stack` channel for errors and the `daily` channel for other logs.
- Use Eloquent or bound query parameters. Do not interpolate user input into raw SQL.
- Destructive operations (delete, restore) must stay shop-scoped and require a non-empty `external_product_id`.

## Known Risks To Review

- `Shop::$fillable` includes `api_token`, and the model casts do not encrypt it. Consider the `encrypted` cast and hiding it from serialization.
- Batch error snapshots are written to the public storage disk. Confirm they are not web-exposed with sensitive data.
- Batch/item payload snapshots persist imported data indefinitely. Define a retention policy.

## Pre-Release Checklist

- [ ] No secrets in the diff (`git diff` and `git status` reviewed).
- [ ] New endpoints, actions, and resources are authorized.
- [ ] Dependencies updated through the developer, not by agents.
- [ ] `APP_DEBUG=false` and a strong `APP_KEY` in production.
- [ ] Database and storage backups verified.

## Reporting

Report vulnerabilities privately to the project maintainer. Do not open public issues for them.

## See Also

- [Configuration](configuration.md) — stores and environment.
- [OpenCart API Token Lifecycle](opencart-api-token-lifecycle.md) — token storage and refresh.
- [Project Rules](RULES.md) — working agreements.
