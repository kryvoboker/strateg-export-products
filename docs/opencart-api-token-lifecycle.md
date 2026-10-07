[← Remote API Integration](remote-api-integration.md) · [Back to README](../README.md) · [Categories →](category-resource.md)

# OpenCart API Token Lifecycle

This page describes how the application obtains, stores, reuses, and refreshes OpenCart session tokens for remote shop operations.

## Two different credentials

The shop configuration contains two kinds of credentials:

| Credential             | Stored in                                                                | Purpose                                                                            |
|------------------------|--------------------------------------------------------------------------|------------------------------------------------------------------------------------|
| OpenCart API key       | `shops.api_token`, with the API username in `shops.options.api_username` | Authenticate to the configured OpenCart login endpoint and request a session token |
| OpenCart session token | `shops.options.auth_api_token`                                           | Authenticate export, update, delete, backup, and restore requests                  |

The session token is not given a local expiration time. The application keeps it in the shop's `options` JSON and relies on the remote API to indicate when it is no longer accepted.

## Authentication and request flow

For these operations, the job follows this sequence:

1. Read `auth_api_token` from the shop options.
2. If no session token is stored, send a form-encoded `POST` to `part_api_url_login` with `username` and the shop API key as `key`.
3. Treat a non-empty `error` or `warning` response field as a failure, even if the HTTP status is successful. Then require a non-empty `api_token` and store it in `shops.options.auth_api_token`.
4. Send the operation request using the session token. OpenCart item jobs pass it as a query parameter on the configured operation URL.
5. If the response indicates an invalid or expired token, acquire a per-shop cache lock, re-read the shop and token, authenticate if another job has not already refreshed it, then retry the request once.

The shared request and token helpers are in `app-code/app/app/Jobs/Traits/InteractsWithShopApi.php`. Export applies the flow in `app-code/app/app/Jobs/ProcessProductExportItemJob.php`; update and restore jobs implement the corresponding flow in their item jobs.

Invalid-token detection recognizes HTTP 401/403, HTML responses, and token/session error markers. There is no locally configured token lifetime, so refresh is reactive: the remote shop's response determines when a new login is needed.

## Delete flow

Delete and its backup use the same shared authentication and token-refresh flow as export, update, and restore. The login form contains `username` and `key`; operation requests send the session token in the URL.

## Why a login can return HTTP 200 without a token

The current Strateg OpenCart login endpoint can return a JSON `error` for a bad API key or a source IP that is not allow-listed while still responding with HTTP 200. Such a response has no `api_token`.

The shared response validator checks the JSON `error` and `warning` fields before requiring `api_token`, including when the HTTP status is 2xx. This preserves the remote diagnostic message in the failure instead of reducing every such response to a missing-token error.

For an incident, check the shop's API username/key and allowed source IP, then capture the login response status, content type, and safe error fields. Never log or display the API key or returned session token.

## Bulk processing considerations

Each export item is handled by a queued job. Refresh uses a cache lock keyed by shop ID and re-reads the saved token while holding the lock. When multiple jobs reject the same token, a later job can reuse the token refreshed by the first job instead of repeating the login. The operation is retried at most once after refresh; a second failure is persisted as a failed item.

## Relevant implementation files

- `app-code/app/app/Jobs/Traits/InteractsWithShopApi.php` — token lookup, authentication requests, persistence, and invalid-token detection.
- `app-code/app/app/Jobs/ProcessProductExportItemJob.php` — product export authentication and one-time retry.
- `app-code/app/app/Jobs/ProcessProductUpdateItemJob.php` — update and backup authentication.
- `app-code/app/app/Jobs/ProcessProductDeleteItemJob.php` — delete and backup authentication through the shared token flow.
- `app-code/app/app/Jobs/ProcessCatalogProductRestoreItemJob.php` — remote restore authentication.

## See Also

- [Remote API Integration](remote-api-integration.md) — shop endpoints and remote request behavior.
- [Stores](shop-resource.md) — shop configuration and API credentials.
- [Product Data Workflows](product-data-workflows.md) — export, update, delete, and restore workflows.
