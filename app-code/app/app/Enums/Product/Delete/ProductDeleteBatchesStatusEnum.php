<?php

declare(strict_types=1);

namespace App\Enums\Product\Delete;

enum ProductDeleteBatchesStatusEnum: string
{
    case NEW            = 'new';
    case PROCESSING     = 'processing';
    case COMPLETED      = 'completed';
    case FAILED         = 'failed';
    case PARTIAL_FAILED = 'partial_failed';
    case CANCELED       = 'canceled';
}
