<?php

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_export_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_import_batch_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->jsonb('payload')->nullable();
            $table->string('status', 100)
                ->nullable()
                ->default(ProductExportItemsStatusEnum::PROCESSING->value)
                ->index();

            $table->text('error_message')
                ->nullable()
                ->index()
                ->comment('Error message if the item processing failed');

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['product_import_batch_id', 'status']);

            // Create JSONB indexes using raw SQL
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_export_items_payload_product_id ON " . config('database.db_prefix') . "product_export_items ((payload->>'product_id'))");
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_export_items_payload_model ON " . config('database.db_prefix') . "product_export_items ((payload->>'model'))");
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_export_items_payload_sku ON " . config('database.db_prefix') . "product_export_items ((payload->>'sku'))");
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_export_items_payload_ean ON " . config('database.db_prefix') . "product_export_items ((payload->>'ean'))");
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_export_items_payload_description_name ON " . config('database.db_prefix') . "product_export_items (LEFT(payload->'description'->>'name', 2000))");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_export_items');
    }
};
