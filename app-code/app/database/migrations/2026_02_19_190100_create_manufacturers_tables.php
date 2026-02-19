<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table): void {
            $table->id();

            $table->smallInteger('sort_order')->nullable(false)->default(1);
            $table->boolean('is_active')->nullable(false)->default(false);

            $table->timestamps();
        });

        Schema::create('manufacturer_descriptions', function (Blueprint $table): void {
            $table->id();

            // Cascade delete descriptions when manufacturer is removed.
            $table->foreignId('manufacturer_id')
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

            $table->unique(['manufacturer_id', 'shop_language_id']);
        });

        Schema::create('manufacturer_shop', function (Blueprint $table): void {
            $table->id();

            // Cascade delete shop links when manufacturer is removed.
            $table->foreignId('manufacturer_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Cascade delete shop links when shop is removed.
            $table->foreignId('shop_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('external_manufacturer_id')->nullable()->index();

            $table->timestamps();

            $table->unique(['manufacturer_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturer_shop');
        Schema::dropIfExists('manufacturer_descriptions');
        Schema::dropIfExists('manufacturers');
    }
};
