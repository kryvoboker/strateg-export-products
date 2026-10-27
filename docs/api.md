[Back to README](../README.md) · [Documentation index](README.md) · [Remote API Integration](remote-api-integration.md)

# API Reference

## Inbound Routes

The application does not expose an integration API. It has no `routes/api.php`; `app-code/app/routes/web.php` contains only the `/` welcome route, and the administration panel is a Filament panel with login.

| Surface         | Path                                | Authentication                |
|-----------------|-------------------------------------|-------------------------------|
| Admin panel     | `/opa-chirik`                       | Filament login (users table)  |
| Welcome page    | `/`                                 | None                          |

## Outbound Integration

The application is an API client of external shops. Endpoint paths are configured per shop and are not fixed by this project.

| Shop field                                                              | Purpose                                     |
|-------------------------------------------------------------------------|---------------------------------------------|
| `api_url`, `base_url`                                                   | Remote API base URL                         |
| `part_api_url_login`                                                    | Authentication endpoint                     |
| `part_api_url_export_prods`, `part_api_url_update_prods`                | Export and update endpoints                 |
| `part_api_url_restore_prods`, `part_api_url_delete_prods`               | Restore and delete endpoints                |
| `api_token`, `options.api_username`                                     | Credentials for the login request           |
| `options.auth_api_token`                                                | Reused OpenCart session token               |

Authentication for OpenCart-like shops uses a session token that is reused and refreshed on rejection. A non-empty remote `error` or `warning` is treated as a failed response even when the HTTP status is successful. Details:

- [Remote API Integration](remote-api-integration.md) — implemented operations and status.
- [OpenCart API Token Lifecycle](opencart-api-token-lifecycle.md) — login, reuse, and refresh.
- [OpenAPI specification](openapi.yaml) — logical operation contract. The remote shop's real contract remains authoritative.

## See Also

- [Configuration](configuration.md) — store settings.
- [Stores](shop-resource.md) — the Shops resource.
- [Security](SECURITY.md) — credential handling.
