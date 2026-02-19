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
        Schema::create('seo_urls', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('seoable');

            $table->foreignId('shop_language_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('query_key')->nullable();
            $table->string('query_value')->nullable();
            $table->string('keyword')->nullable()->index();
            $table->unsignedSmallInteger('sort_order')->nullable()->default(1);

            $table->timestamps();

            $table->index(['query_key', 'query_value']);
            $table->index(['seoable_type', 'seoable_id', 'query_key', 'query_value']);
            $table->index(['shop_language_id', 'query_key', 'query_value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_urls');
    }
};
