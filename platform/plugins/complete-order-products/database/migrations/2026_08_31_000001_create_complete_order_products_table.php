<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('complete_order_products')) {
            Schema::create('complete_order_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('custom_title', 255)->nullable();
                $table->string('custom_title_ar', 255)->nullable();
                $table->integer('order_index')->default(0);
                $table->string('status', 60)->default('published');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('complete_order_products');
    }
};
