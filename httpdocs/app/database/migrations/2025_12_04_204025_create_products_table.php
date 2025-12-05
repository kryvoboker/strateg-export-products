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
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('marked_to_shop')
                ->nullable()
                ->index()
                ->comment('Indicates the shop where the product is marked was exported to. This field only for usability!');

            $table->string('model')->index()->nullable();
            $table->string('sku')->index()->nullable();
            $table->string('ean')->index()->nullable();
            $table->integer('quantity')->nullable(false)->default(0);
            $table->integer('minimum')->nullable(false)->default(1);
            $table->string('image', 3000)->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamp('date_available')->nullable()->useCurrent();
            $table->timestamp('date_added')->nullable()->useCurrent();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
