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
        Schema::create('attribute_name_hashes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attribute_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->char('hash', 64)
                ->nullable(false)
                ->index()
                ->comment('Hash SHA-256 of the attribute name for check is the name changed');

            $table->timestamps();

            $table->unique(['attribute_id', 'hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_name_hashes');
    }
};
