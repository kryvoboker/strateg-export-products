<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();

            $table->smallInteger('sort_order')->nullable(false)->default(1);
            $table->boolean('is_active')->nullable(false)->default(false);

            $table->timestamps();
        });

        Schema::create('brand_descriptions', function (Blueprint $table): void {
            $table->id();

            // Cascade delete descriptions when brand is removed.
            $table->foreignId('brand_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Keep description row but null language if language is removed.
            $table->foreignId('shop_language_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('name');

            $table->timestamps();

            $table->unique(['brand_id', 'shop_language_id']);
        });

        Schema::create('brand_shop', function (Blueprint $table): void {
            $table->id();

            // Cascade delete shop links when brand is removed.
            $table->foreignId('brand_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Cascade delete shop links when shop is removed.
            $table->foreignId('shop_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('external_brand_id')->nullable()->index();

            $table->timestamps();

            $table->unique(['brand_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_shop');
        Schema::dropIfExists('brand_descriptions');
        Schema::dropIfExists('brands');
    }
};
