<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $table_name = DB::getTablePrefix().'product_update_items';

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS product_update_items_unique_update_scope
            ON {$table_name} (
                product_update_batch_id,
                product_id,
                ((payload->>'shop_id')::bigint)
            )
            WHERE (payload->>'operation') = 'update'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS product_update_items_unique_update_scope');
    }
};
