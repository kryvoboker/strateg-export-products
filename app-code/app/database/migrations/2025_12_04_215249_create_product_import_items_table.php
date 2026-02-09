<?php

use App\Enums\ProductImportItemsStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_import_items', function (Blueprint $table) {
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
                ->default(ProductImportItemsStatusEnum::NEW->value)
                ->index();

            $table->text('error_message')
                ->nullable()
                ->index()
                ->comment('Error message if the item processing failed');

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['product_import_batch_id', 'status']);

            // Create JSONB indexes using raw SQL
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_import_items_payload_product_id ON product_import_items ((payload->>'product_id'))");
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_import_items_payload_model ON product_import_items ((payload->>'model'))");
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_import_items_payload_sku ON product_import_items ((payload->>'sku'))");
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_import_items_payload_ean ON product_import_items ((payload->>'ean'))");
            DB::statement("CREATE INDEX IF NOT EXISTS " . config('database.db_prefix') . "product_import_items_payload_description_name ON product_import_items (LEFT(payload->'description'->>'name', 2000))");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_import_items');
    }
};
