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
        Schema::create('product_backups', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('backupable');

            $table->string('backup_source', 100)
                ->nullable()
                ->index()
                ->comment('Source of backup: internal, external_api');

            $table->string('backup_kind', 150)
                ->nullable()
                ->index()
                ->comment('Kind of backup: local_product_snapshot, external_product_snapshot');

            $table->foreignId('shop_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('external_product_id')
                ->nullable()
                ->index();

            $table->jsonb('payload')->nullable();
            $table->boolean('is_used')->default(false)->index();

            $table->timestamps();

            $table->unique([
                'backupable_type',
                'backupable_id',
                'backup_source',
                'backup_kind',
                'shop_id',
            ], 'product_backups_unique_scope');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_backups');
    }
};
