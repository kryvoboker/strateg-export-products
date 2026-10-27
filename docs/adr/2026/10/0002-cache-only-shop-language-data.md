# ADR 0002: Cache Only Shop-Language Data

- Date: 2026-10-27
- Status: Accepted

## Context

The product editor and import-item editor build language-specific fields from the selected shop's active languages. `ProductResourceOptionsService::getShopLanguages()` cached an Eloquent collection in the database cache. The application keeps Laravel's `cache.serializable_classes` setting at `false`, so cached Eloquent objects are not allowed to be restored as PHP classes and can deserialize as incomplete objects.

## Decision

Cache only scalar arrays containing each language's `id`, `name`, and `code`, under a versioned cache key. Return an in-memory Laravel collection of those arrays to consumers. Preserve active-language filtering and the existing ordering. Keep `cache.serializable_classes=false` as the default-deny cache configuration.

## Alternatives Considered

- Enable serialization for Eloquent classes: rejected because it broadens the classes permitted during cache unserialization and couples the cache payload to model serialization.
- Remove database cache entirely: rejected because it is not needed to fix this focused failure.

## Consequences

- Cached option data is composed of serializable scalar values and no longer depends on Eloquent model restoration.
- Consumers use array keys for cached language metadata and can still build form labels and language-specific fields.
- The versioned key avoids interpreting legacy object payloads; matching legacy shop-language row keys can be invalidated without flushing unrelated cache entries.
- Changes to the cached shape must use a new key version.
