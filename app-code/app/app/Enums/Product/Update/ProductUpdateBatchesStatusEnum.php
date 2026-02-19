<?php

declare(strict_types=1);

namespace App\Enums\Product\Update;

enum ProductUpdateBatchesStatusEnum: string
{
    case NEW            = 'new';
    case PROCESSING     = 'processing';
    case COMPLETED      = 'completed';
    case FAILED         = 'failed';
    case PARTIAL_FAILED = 'partial_failed';
    case CANCELED       = 'canceled';
}
