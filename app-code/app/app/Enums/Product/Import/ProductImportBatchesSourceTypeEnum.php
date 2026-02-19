<?php

declare(strict_types=1);

namespace App\Enums\Product\Import;

enum ProductImportBatchesSourceTypeEnum: string
{
    case CSV_FILE     = 'Csv File';
    case EXCEL_FILE   = 'Excel File';
    case GOOGLE_SHEET = 'Google Sheet';
    case ADMIN_PANEL  = 'Admin Panel';
}
