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
        Schema::table('users', function (Blueprint $table) {
            $table->string('lastname')->nullable()->after('name');

            $table->string('telephone', 20)
                ->unique()
                ->nullable()
                ->after('email');

            $table->string('avatar', 600)->nullable()->after('telephone');

            $table->boolean('is_active')
                ->nullable(false)
                ->default(false)
                ->after('avatar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['lastname', 'telephone', 'avatar', 'is_active']);
        });
    }
};
