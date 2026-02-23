<?php

use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
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
        Schema::create('product_delete_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_delete_batch_id')
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
                ->default(ProductDeleteItemsStatusEnum::NEW->value)
                ->index();

            $table->text('error_message')
                ->nullable()
                ->index()
                ->comment('Error message if the item processing failed');

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['product_delete_batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_delete_items');
    }
};
