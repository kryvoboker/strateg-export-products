<?php

declare(strict_types=1);

namespace App\Enums\Product\Delete;

enum ProductDeleteItemsStatusEnum:string
{
    case NEW        = 'new';        // item data is saved but not yet processed
    case PROCESSING = 'processing'; // item is currently being processed for delete
    case FAILED     = 'failed';
    case DELETED    = 'deleted';
}
