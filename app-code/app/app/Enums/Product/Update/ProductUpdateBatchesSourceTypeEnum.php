<?php

declare(strict_types=1);

namespace App\Enums\Product\Update;

enum ProductUpdateBatchesSourceTypeEnum: string
{
    case CSV_FILE         = 'Csv File';
    case EXCEL_FILE       = 'Excel File';
    case GOOGLE_SHEET     = 'Google Sheet';
    case LOCAL_PRODUCTS   = 'Local Products';
    case EDIT_PRODUCT_API = 'Edit Product API';
}
