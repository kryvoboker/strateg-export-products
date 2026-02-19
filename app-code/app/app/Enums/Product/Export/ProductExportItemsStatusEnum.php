<?php

declare(strict_types=1);

namespace App\Enums\Product\Export;

enum ProductExportItemsStatusEnum: string
{
    case PROCESSING     = 'processing'; // item is currently being processed
    case FAILED         = 'failed';
    case PARTIAL_FAILED = 'partial_failed'; // item has some issues but was partially processed, may require manual review
    case NORMALIZED     = 'normalized';     // item data has been normalized
    case EXPORTED       = 'exported';       // item has been successfully imported and reade for upload to shops
}
