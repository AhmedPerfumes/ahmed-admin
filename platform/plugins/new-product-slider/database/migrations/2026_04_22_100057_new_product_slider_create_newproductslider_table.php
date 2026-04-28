<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('newproductsliders')) {
            Schema::create('newproductsliders', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('category', 255)->nullable();
                $table->text('desc')->nullable();
                $table->string('product_img', 255)->nullable();
                $table->string('note_img', 255)->nullable();
                $table->string('theme_bg', 50)->nullable();
                $table->string('theme_roman', 20)->nullable();
                $table->string('theme_accent', 50)->nullable();
                $table->string('theme_glow', 50)->nullable();
                $table->string('link', 255)->nullable();
                $table->integer('order_index')->default(0);
                $table->string('status', 60)->default('published');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('newproductsliders_translations')) {
            Schema::create('newproductsliders_translations', function (Blueprint $table) {
                $table->string('lang_code');
                $table->foreignId('newproductsliders_id');
                $table->string('name', 255)->nullable();

                $table->primary(['lang_code', 'newproductsliders_id'], 'newproductsliders_translations_primary');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('newproductsliders');
        Schema::dropIfExists('newproductsliders_translations');
    }
};
