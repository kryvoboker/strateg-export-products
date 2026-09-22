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
        Schema::create('shop_languages', function (Blueprint $table) {
            $db_prefix = config('database.db_prefix');

            $table->id();

            $table->foreignId('shop_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('code', 10)->nullable(false);
            $table->string('name', 100)->nullable(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['shop_id', 'code']);
            $table->index(['shop_id', 'is_default']);

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$db_prefix}shop_languages_shop_id_default_unique ON {$db_prefix}shop_languages (shop_id) WHERE is_default = true");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_languages');
    }
};
