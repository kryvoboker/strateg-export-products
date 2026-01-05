<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductImportItemsStatusEnum:string
{
    case NEW        = 'new';      // item data is saved but not yet processed
    case PROCESSING = 'processing'; // item is currently being processed
    case FAILED     = 'failed';
    case NORMALIZED = 'normalized'; // item data has been normalized
    case SUCCESSED  = 'successed';  // item has been successfully imported and reade for upload to shops
}
