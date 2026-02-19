<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_export_items', function (Blueprint $table) {
            $db_prefix = config('database.db_prefix');

            // Create JSONB indexes using raw SQL
            DB::statement("CREATE INDEX IF NOT EXISTS {$db_prefix}product_export_items_payload_product_id ON {$db_prefix}product_export_items ((payload->>'product_id'))");
            DB::statement("CREATE INDEX IF NOT EXISTS {$db_prefix}product_export_items_payload_model ON {$db_prefix}product_export_items ((payload->>'model'))");
            DB::statement("CREATE INDEX IF NOT EXISTS {$db_prefix}product_export_items_payload_sku ON {$db_prefix}product_export_items ((payload->>'sku'))");
            DB::statement("CREATE INDEX IF NOT EXISTS {$db_prefix}product_export_items_payload_ean ON {$db_prefix}product_export_items ((payload->>'ean'))");
            DB::statement("CREATE INDEX IF NOT EXISTS {$db_prefix}product_export_items_payload_description_name ON {$db_prefix}product_export_items (LEFT(payload->'description'->>'name', 2000))");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_export_items', function (Blueprint $table) {
            $db_prefix = config('database.db_prefix');

            DB::statement("DROP INDEX IF EXISTS {$db_prefix}product_export_items_payload_product_id");
            DB::statement("DROP INDEX IF EXISTS {$db_prefix}product_export_items_payload_model");
            DB::statement("DROP INDEX IF EXISTS {$db_prefix}product_export_items_payload_sku");
            DB::statement("DROP INDEX IF EXISTS {$db_prefix}product_export_items_payload_ean");
            DB::statement("DROP INDEX IF EXISTS {$db_prefix}product_export_items_payload_description_name");
        });
    }
};
