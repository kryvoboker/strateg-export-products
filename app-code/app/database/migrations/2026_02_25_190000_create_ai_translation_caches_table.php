<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_translation_caches', function (Blueprint $table): void {
            $table->id();

            $table->morphs('translatable');

            $table->char('hash', 64)
                ->index()
                ->comment('SHA-256 hash from normalized translation prompt');

            $table->longText('prompt');
            $table->longText('answer');

            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_translation_caches');
    }
};
