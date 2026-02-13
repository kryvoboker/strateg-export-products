<?php

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
        Schema::create('product_attribute_text_hashes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('attribute_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->char('hash', 64)
                ->nullable(false)
                ->index()
                ->comment('Hash SHA-256 of the product attribute text for check is the attribute text changed');

            $table->timestamps();

            $table->unique(['product_id', 'attribute_id', 'hash'], 'product_name_attribute_text_hash_unique'); // for fix very long key name
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attribute_text_hashes');
    }
};
