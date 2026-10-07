[← Remote API Integration](remote-api-integration.md) · [Back to README](../README.md) · [OpenAPI Specification →](openapi.yaml)

# OpenCart API Token Lifecycle

This page describes how the Laravel application obtains, stores, reuses, and refreshes OpenCart session tokens. It documents the current implementation, including the handling gaps that affect bulk operations.

## Two different credentials

The shop configuration contains two kinds of credentials:

| Credential             | Stored in                                                                | Purpose                                                                            |
|------------------------|--------------------------------------------------------------------------|------------------------------------------------------------------------------------|
| OpenCart API key       | `shops.api_token`, with the API username in `shops.options.api_username` | Authenticate to the configured OpenCart login endpoint and request a session token |
| OpenCart session token | `shops.options.auth_api_token`                                           | Authenticate export, update, delete, backup, and restore requests                  |

The session token is not given a local expiration time. The application keeps it in the shop's `options` JSON and relies on the remote API to indicate when it is no longer accepted.

## Current export, update, and restore flow

For these operations, the job follows this sequence:

1. Read `auth_api_token` from the shop options.
2. If no session token is stored, send a form-encoded `POST` to `part_api_url_login` with `username` and the shop API key as `key`.
3. Require a successful HTTP response containing a non-empty `api_token`, then store that token in `shops.options.auth_api_token`.
4. Send the operation request using the session token. Export, update, backup, and restore requests place it in the URL; delete and delete-backup requests place it in the form body.
5. If the operation response looks like an expired or invalid token response, authenticate again with `username` and `key`, store the new token, and retry the operation once.

The shared request and token helpers are in `app-code/app/app/Jobs/Traits/InteractsWithShopApi.php`. Export applies the flow in `app-code/app/app/Jobs/ProcessProductExportItemJob.php`; update and restore jobs implement the corresponding flow in their item jobs.

The invalid-token check recognizes HTTP 401/403, HTML responses, and several token-related error markers. It does not use a configured token lifetime or proactively refresh based on age.

## Delete flow difference

`ProcessProductDeleteItemJob` currently requests a session token by posting a single `api_token` field, populated from `shops.api_token`. Strateg's login controller authenticates with the `username` and `key` fields; it does not accept that single-field form. As a result, an initial login or refresh for deletion can fail against the current Strateg login endpoint even when the stored API username and key are valid.

This differs from export, update, and restore, which use the `username`/`key` login form. The delete authentication request should be checked against the target shop's actual login contract before relying on token expiry recovery for deletes.

## Why a login can return HTTP 200 without a token

The current Strateg OpenCart login endpoint can return a JSON `error` for a bad API key or a source IP that is not allow-listed while still responding with HTTP 200. Such a response has no `api_token`.

Laravel currently checks the HTTP success status and then looks for `api_token`. If it is absent, it reports `OpenCart login API did not return api_token`; it does not include the login response's `error` field in that message. Therefore this message alone cannot distinguish a rejected key, an IP allow-list rejection, or another unexpected successful-status response.

For an incident, check the shop's API username/key and allowed source IP, then capture the login response status, content type, and safe error fields. Never log or display the API key or returned session token.

## Bulk processing considerations

Each export item is handled by an independent queued job. Token lookup and refresh do not use a per-shop lock or single shared refresh operation. If many jobs encounter an expired token together, they can all attempt login and write `auth_api_token` at the same time. The implementation does not coordinate those refreshes or guarantee that every request uses the newest token.

The current export tests cover missing master credentials and ordinary API failures, but do not verify expiry, re-authentication, or concurrent refresh behavior. Treat large retries after an authentication failure as unsafe until the login response and queue behavior have been diagnosed.

## Relevant implementation files

- `app-code/app/app/Jobs/Traits/InteractsWithShopApi.php` — token lookup, authentication requests, persistence, and invalid-token detection.
- `app-code/app/app/Jobs/ProcessProductExportItemJob.php` — product export authentication and one-time retry.
- `app-code/app/app/Jobs/ProcessProductUpdateItemJob.php` — update and backup authentication.
- `app-code/app/app/Jobs/ProcessProductDeleteItemJob.php` — delete and backup authentication; currently uses a different login form.
- `app-code/app/app/Jobs/ProcessCatalogProductRestoreItemJob.php` — remote restore authentication.

## See Also

- [Remote API Integration](remote-api-integration.md) — shop endpoints and remote request behavior.
- [Stores](shop-resource.md) — shop configuration and API credentials.
- [Product Data Workflows](product-data-workflows.md) — export, update, delete, and restore workflows.