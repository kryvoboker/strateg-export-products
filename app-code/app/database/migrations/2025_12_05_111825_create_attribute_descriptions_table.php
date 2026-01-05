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
        Schema::create('attribute_descriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attribute_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('shop_language_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('name')->nullable();

            $table->timestamps();

            $table->unique(['attribute_id', 'shop_language_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_descriptions');
    }
};
