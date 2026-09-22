[← Helper Functions](helpers.md) · [Back to README](../README.md) · [Product Import →](product-import-batch-resource.md)

# Supports Services and Helper Functions

This reference covers every function in app-code/app/app/Supports/helpers.php and every public method declared under app-code/app/app/Supports/Services.

## Scope

- All global helper functions are documented below.
- Only public service methods are documented as application-facing entry points.
- Protected and private implementation details are intentionally omitted.

## Global helper functions

| Function | Responsibility |
|---|---|
| clear_telephone | Shared global helper; its PHPDoc defines parameters and return type. |
| parse_telephone | Shared global helper; its PHPDoc defines parameters and return type. |
| trim_strs_in_arr | Shared global helper; its PHPDoc defines parameters and return type. |
| get_telegram_photo | Shared global helper; its PHPDoc defines parameters and return type. |
| get_telegram_message | Shared global helper; its PHPDoc defines parameters and return type. |
| get_telegram_doc | Shared global helper; its PHPDoc defines parameters and return type. |
| is_telegram_has_photo | Shared global helper; its PHPDoc defines parameters and return type. |
| is_telegram_has_doc | Shared global helper; its PHPDoc defines parameters and return type. |
| get_now_date | Shared global helper; its PHPDoc defines parameters and return type. |
| validate_url | Shared global helper; its PHPDoc defines parameters and return type. |
| escape_special_html | Shared global helper; its PHPDoc defines parameters and return type. |
| decode_html_entities | Shared global helper; its PHPDoc defines parameters and return type. |
| normalize_str | Shared global helper; its PHPDoc defines parameters and return type. |
| sanitize_str | Shared global helper; its PHPDoc defines parameters and return type. |
| normalize_positive_int_list | Shared global helper; its PHPDoc defines parameters and return type. |
| normalize_trimmed_str | Shared global helper; its PHPDoc defines parameters and return type. |
| normalize_nullable_trimmed_str | Shared global helper; its PHPDoc defines parameters and return type. |
| normalize_array_payload | Shared global helper; its PHPDoc defines parameters and return type. |
| resolve_upload_path_placeholders | Shared global helper; its PHPDoc defines parameters and return type. |
| string_value | Shared global helper; its PHPDoc defines parameters and return type. |
| integer_value | Shared global helper; its PHPDoc defines parameters and return type. |
| array_value | Shared global helper; its PHPDoc defines parameters and return type. |
| string_keyed_array | Shared global helper; its PHPDoc defines parameters and return type. |
| boolean_value | Shared global helper; its PHPDoc defines parameters and return type. |
| float_value | Shared global helper; its PHPDoc defines parameters and return type. |
| list_value | Shared global helper; its PHPDoc defines parameters and return type. |
| nullable_string | Shared global helper; its PHPDoc defines parameters and return type. |
| resolve_string | Shared global helper; its PHPDoc defines parameters and return type. |
| resolve_public_url | Shared global helper; its PHPDoc defines parameters and return type. |
| log_stack_trace | Shared global helper; its PHPDoc defines parameters and return type. |
| to_json | Shared global helper; its PHPDoc defines parameters and return type. |
| from_json | Shared global helper; its PHPDoc defines parameters and return type. |
| json_encode_throw | Shared global helper; its PHPDoc defines parameters and return type. |
| json_decode_throw | Shared global helper; its PHPDoc defines parameters and return type. |
| get_allowed_locales | Shared global helper; its PHPDoc defines parameters and return type. |

## Public service methods

### AiPromptHasherService

File: app-code/app/app/Supports/Services/Ai/AiPromptHasherService.php

| Method | Responsibility |
|---|---|
| normalize | Normalizes text before hashing or comparison. |
| hash | Creates a stable prompt hash. |

### AiTranslationPromptBuilderService

File: app-code/app/app/Supports/Services/Ai/AiTranslationPromptBuilderService.php

| Method | Responsibility |
|---|---|
| buildTranslatePrompt | Builds a translation prompt for source and target languages. |

### AiTranslationService

File: app-code/app/app/Supports/Services/Ai/AiTranslationService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |
| attributeName | Handles attribute name. |
| categoryName | Handles category name. |
| attributeDescription | Handles attribute description. |
| categoryDescription | Handles category description. |
| brandName | Handles brand name. |
| brandDescription | Handles brand description. |
| manufacturerName | Handles manufacturer name. |
| manufacturerDescription | Handles manufacturer description. |
| productName | Handles product name. |
| productDescription | Handles product description. |
| productAttributeText | Handles product attribute text. |

### OpenAiRateLimiterService

File: app-code/app/app/Supports/Services/Ai/OpenAiRateLimiterService.php

| Method | Responsibility |
|---|---|
| throttle | Applies the external translation rate limit. |

### ProductBackupPayloadRestoreService

File: app-code/app/app/Supports/Services/Products/Backup/ProductBackupPayloadRestoreService.php

| Method | Responsibility |
|---|---|
| applySnapshotToProduct | Applies a validated backup snapshot to a local product. |

### ProductBackupRestoreService

File: app-code/app/app/Supports/Services/Products/ProductBackupRestoreService.php

| Method | Responsibility |
|---|---|
| hasValidLatestLocalSnapshotForProduct | Checks whether valid latest local snapshot for product. |
| resolveLatestValidLocalSnapshotForProduct | Resolves latest valid local snapshot for product. |
| isBackupPayloadValid | Checks whether backup payload valid. |
| isExternalBackupPayloadValid | Checks whether external backup payload valid. |
| hasValidLatestExternalSnapshotForProductShop | Checks whether valid latest external snapshot for product shop. |
| resolveLatestValidExternalSnapshotForProductShop | Resolves latest valid external snapshot for product shop. |
| restoreLatestSnapshotForProduct | Handles restore latest snapshot for product. |
| restoreFromBackup | Handles restore from backup. |

### ProductDeleteQueueService

File: app-code/app/app/Supports/Services/Products/ProductDeleteQueueService.php

| Method | Responsibility |
|---|---|
| queueForDeleteBatches | Queues for delete batches. |
| retryFailedForDeleteBatches | Retries failed for delete batches. |
| queueForDeleteItems | Queues for delete items. |
| retryFailedForDeleteItems | Retries failed for delete items. |
| queueForProductIdsAndShopIds | Queues for product ids and shop ids. |
| queueSingleProductShopDelete | Queues single product shop delete. |
| queueDeleteItem | Queues delete item. |
| syncBatchStatusByItems | Synchronizes batch status by items. |

### ProductShopBindingService

File: app-code/app/app/Supports/Services/Products/ProductShopBindingService.php

| Method | Responsibility |
|---|---|
| bindProductToShopAndReturnTargetProduct | Handles bind product to shop and return target product. |
| bindProductToShopsAndReturnTargets | Handles bind product to shops and return targets. |
| bindProductsToShops | Handles bind products to shops. |
| applyDefaultLanguageToProductTranslations | Handles apply default language to product translations. |
| synchronizeProductTranslations | Synchronizes hronize product translations. |
| synchronizeProductTranslationsForShop | Synchronizes hronize product translations for shop. |

### DeSeoSlugService

File: app-code/app/app/Supports/Services/SeoSlug/DeSeoSlugService.php

| Method | Responsibility |
|---|---|
| make | Builds a language-specific SEO slug. |

### DefaultSeoSlugService

File: app-code/app/app/Supports/Services/SeoSlug/DefaultSeoSlugService.php

| Method | Responsibility |
|---|---|
| make | Builds a language-specific SEO slug. |

### EnSeoSlugService

File: app-code/app/app/Supports/Services/SeoSlug/EnSeoSlugService.php

| Method | Responsibility |
|---|---|
| make | Builds a language-specific SEO slug. |

### ProductSeoKeywordService

File: app-code/app/app/Supports/Services/SeoSlug/ProductSeoKeywordService.php

| Method | Responsibility |
|---|---|
| make | Builds a language-specific SEO slug. |

### RuSeoSlugService

File: app-code/app/app/Supports/Services/SeoSlug/RuSeoSlugService.php

| Method | Responsibility |
|---|---|
| make | Builds a language-specific SEO slug. |

### UaSeoSlugService

File: app-code/app/app/Supports/Services/SeoSlug/UaSeoSlugService.php

| Method | Responsibility |
|---|---|
| make | Builds a language-specific SEO slug. |

### AttributeDescriptionAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Attribute/AttributeDescriptionAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### AttributeNameAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Attribute/AttributeNameAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### BrandDescriptionAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Brand/BrandDescriptionAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### BrandNameAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Brand/BrandNameAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### CategoryDescriptionAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Category/CategoryDescriptionAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### CategoryNameAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Category/CategoryNameAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### ManufacturerDescriptionAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Manufacturer/ManufacturerDescriptionAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### ManufacturerNameAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Manufacturer/ManufacturerNameAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### ProductAttributeTextAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Product/ProductAttributeTextAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### ProductDescriptionAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Product/ProductDescriptionAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

### ProductImportPayloadTranslationService

File: app-code/app/app/Supports/Services/Translations/Product/ProductImportPayloadTranslationService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |
| translatePayloadToAllLanguages | Expands an imported payload to all active languages. |

### ProductNameAiTranslatorService

File: app-code/app/app/Supports/Services/Translations/Product/ProductNameAiTranslatorService.php

| Method | Responsibility |
|---|---|
| __construct | Injects required collaborators. |

## See Also

- [Architecture](architecture.md) — module boundaries and data flow.
- [Configuration](configuration.md) — store and queue settings.
- [Product Import](product-import-batch-resource.md) — workflows that consume these services.
