[← Security](SECURITY.md) · [Back to README](../README.md) · [Project Rules →](RULES.md)

# Project Roadmap

> An internal admin platform that maintains a shared product catalog and synchronizes it with external shops.

## Milestones

- [x] **Catalog import** — Excel, Google Sheets, and manual input with batch/item traceability.
- [x] **Shop binding** — product families bound to one or many shops, with per-shop duplication and shop-scoped taxonomy.
- [x] **Multilingual content and SEO** — AI-backed translation with caching, SEO URL synchronization for all active shop languages.
- [x] **Export, update, delete, restore** — queued workflows with external IDs, local and external backups.
- [x] **OpenCart token lifecycle** — session token login, reuse, and refresh (see `opencart-api-token-lifecycle.md`).
- [ ] **Idempotent OpenCart product import** — transactional upsert keyed by model/EAN on the shop side (plan: `plans/2026-09-28-opencart-product-api-upload.md`).
- [ ] **Security hardening** — protect shop credentials at rest and review panel access (see `SECURITY.md`).
- [ ] **Test coverage for critical workflows** — binding, export payloads, and backup restore flows.
- [ ] **Additional shop connectors** — configurable API adapters beyond OpenCart-like shops.

## Completed

| Milestone                                      | Date       |
|------------------------------------------------|------------|
| Initial roadmap created from the current state | 2026-10-22 |

## See Also

- [Project Description](DESCRIPTION.md) — what the application does.
- [Security](SECURITY.md) — open risks.
- [Architecture](ARCHITECTURE.md) — module boundaries.