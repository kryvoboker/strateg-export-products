[Back to README](../README.md) · [Setup and operations](setup/README.md) · [Agent rules](../AGENTS.md)

# Documentation Index

Navigation and ownership for all project documentation. The root [README](../README.md) is the landing page; detailed topics live here. All maintained documentation is in English.

## Start Here

| Document                                | Description                                   |
|-----------------------------------------|-----------------------------------------------|
| [Setup and operations](setup/README.md) | Environment and required tool checks          |
| [Document conventions](DOCUMENTS.md)    | Canonical sources and artifact locations      |
| [Project description](DESCRIPTION.md)   | Purpose, modules, and tables                  |
| [Architecture](ARCHITECTURE.md)         | Components and source boundaries              |
| [Project rules](RULES.md)               | Universal rules ([base rules](rules/base.md)) |

## Operations

| Document                              | Description                                |
|---------------------------------------|--------------------------------------------|
| [Getting Started](getting-started.md) | Local startup                              |
| [Configuration](configuration.md)     | Environment, stores, and queues            |
| [Deployment](deployment.md)           | Docker and release flow                    |
| [Testing](testing.md)                 | Suites and verification limits             |
| [Security](SECURITY.md)               | Secrets, access rules, and known risks     |

## Integration

| Document                                                        | Description                             |
|-----------------------------------------------------------------|-----------------------------------------|
| [API reference](api.md)                                         | Routes and authentication               |
| [Remote API Integration](remote-api-integration.md)             | Outbound shop requests and API status   |
| [OpenCart API Token Lifecycle](opencart-api-token-lifecycle.md) | Session token login, reuse, and refresh |
| [OpenAPI](openapi.yaml)                                         | Outbound integration contract           |

## Product Workflows and Resources

[Product Data Workflows](product-data-workflows.md), [Product Import](product-import-batch-resource.md), [Product Updates](product-update-batch-resource.md), [Product Deletes](product-delete-batch-resource.md), [Product Catalog](product-resource.md), [Categories](category-resource.md), [Attributes](attribute-resource.md), [Stores](shop-resource.md), [Store Languages](shop-language-resource.md), [Users](user-resource.md).

## Code Reference

[Helper Functions](helpers.md), [Supports Reference](supports.md).

## History and Planning

| Location                   | Description                                               |
|----------------------------|-----------------------------------------------------------|
| [Decisions](adr/README.md) | Durable decision history (ADRs)                           |
| [Roadmap](ROADMAP.md)      | Completed and planned milestones                          |
| [patches/](patches/)       | Regression notes                                          |
| [plans/](plans/)           | Implementation plans                                      |
| `reviews/`, `playground/`  | Created only when needed; see [conventions](DOCUMENTS.md) |
| `../openspec/`             | Tool-managed changes and specifications                   |

## Ownership

| Area                         | Source of truth                                              |
|------------------------------|--------------------------------------------------------------|
| Tasks, status, blockers      | Beads (`bd`). Not documents under `docs/tasks/`              |
| Decisions and rationale      | `docs/adr/`                                                  |
| Agent rules                  | `AGENTS.md` (`CLAUDE.md` is a pointer)                       |
| AI Factory settings          | `.ai-factory/config.yaml`                                    |