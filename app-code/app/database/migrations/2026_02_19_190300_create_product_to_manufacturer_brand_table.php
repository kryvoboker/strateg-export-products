<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('product_to_manufacturer_brand', function (Blueprint $table): void {
            $table->id();

            // Cascade delete binding row when product is removed.
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Null manufacturer reference when manufacturer is removed.
            $table->foreignId('manufacturer_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Null brand reference when brand is removed.
            $table->foreignId('brand_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['product_id']);
            $table->index(['manufacturer_id']);
            $table->index(['brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_to_manufacturer_brand');
    }
};
