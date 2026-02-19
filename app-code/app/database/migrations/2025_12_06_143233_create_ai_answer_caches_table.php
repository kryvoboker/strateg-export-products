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
        Schema::create('ai_answer_caches', function (Blueprint $table) {
            $table->id();

            $table->morphs('hashable'); // hashable_id, hashable_type

            $table->text('prompt')
                ->nullable(false)
                ->comment('The prompt sent to the AI model');

            $table->text('answer')
                ->nullable(false)
                ->comment('The answer received from the AI model');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_answer_caches');
    }
};
