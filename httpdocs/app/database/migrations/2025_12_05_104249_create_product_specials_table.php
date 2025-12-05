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
        Schema::create('product_specials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedInteger('user_group_id')
                ->nullable()
                ->default(1);

            $table->decimal('price', 15, 4)
                ->nullable(false)
                ->default(0.0000);

            $table->unsignedInteger('priority')
                ->nullable()
                ->default(1);

            $table->timestamp('date_start')->nullable(false);
            $table->timestamp('date_end')->nullable(false);

            $table->timestamps();

            $table->unique(['product_id', 'user_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_specials');
    }
};
