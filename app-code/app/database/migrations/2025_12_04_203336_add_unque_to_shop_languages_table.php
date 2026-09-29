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
        $db_prefix = config('database.db_prefix');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$db_prefix}shop_languages_shop_id_default_unique ON {$db_prefix}shop_languages (shop_id) WHERE is_default = true");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $db_prefix = config('database.db_prefix');

        DB::statement("DROP INDEX IF EXISTS {$db_prefix}shop_languages_shop_id_default_unique");
    }
};
