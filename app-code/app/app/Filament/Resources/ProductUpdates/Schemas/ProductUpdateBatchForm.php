<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Schemas;

use App\Filament\Resources\ProductImports\Schemas\ProductImportBatchForm;
use Filament\Schemas\Schema;

class ProductUpdateBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return ProductImportBatchForm::configure($schema);
    }
}

