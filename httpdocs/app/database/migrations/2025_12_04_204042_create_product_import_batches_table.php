<?php

use App\Enums\ProductImportBatchesSourceTypeEnum;
use App\Enums\ProductImportBatchesStatusEnum;
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
        Schema::create('product_import_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('source_type', 100)
                ->nullable(false)
                ->default(ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value)
                ->index()
                ->comment('Source type of the import batch');

            $table->string('source_name', 2000)
                ->nullable()
                ->comment('Name of the source file or API endpoint or admin panel');

            $table->string('source_path')
                ->nullable()
                ->comment('Path to the source file if exists or API endpoint if exists');

            $table->string('status', 100)
                ->nullable(false)
                ->default(ProductImportBatchesStatusEnum::NEW->value)
                ->index()
                ->comment('Status of the import batch');

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);

            $table->jsonb('options')->nullable();

            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_import_batches');
    }
};
