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
        Schema::create('category_name_hashes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->char('hash', 64)
                ->nullable(false)
                ->index()
                ->comment('Hash SHA-256 of the category name for check is the name changed');

            $table->timestamps();

            $table->unique(['category_id', 'hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_name_hashes');
    }
};
