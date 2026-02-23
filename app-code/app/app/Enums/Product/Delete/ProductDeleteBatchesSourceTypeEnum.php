<?php

declare(strict_types=1);

namespace App\Enums\Product\Delete;

enum ProductDeleteBatchesSourceTypeEnum:string
{
    case EXCEL_FILE     = 'Excel File';
    case GOOGLE_SHEET   = 'Google Sheet';
    case ADMIN_PANEL    = 'Admin Panel';
    case LOCAL_PRODUCTS = 'Local Products';
}
