<?php

declare(strict_types=1);

namespace App\Enums\Product\Update;

enum ProductUpdateBatchesSourceTypeEnum:string
{
    case CSV_FILE    = 'csv_file';
    case EXCEL_FILE  = 'excel_file';
    case API         = 'api';
    case ADMIN_PANEL = 'admin_panel';
}
