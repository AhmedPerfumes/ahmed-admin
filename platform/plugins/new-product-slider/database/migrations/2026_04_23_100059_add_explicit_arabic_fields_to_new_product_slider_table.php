<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('newproductsliders')) {
            Schema::table('newproductsliders', function (Blueprint $table) {
                if (! Schema::hasColumn('newproductsliders', 'name_ar')) {
                    $table->string('name_ar', 255)->nullable()->after('name');
                }
                if (! Schema::hasColumn('newproductsliders', 'category_ar')) {
                    $table->string('category_ar', 255)->nullable()->after('category');
                }
                if (! Schema::hasColumn('newproductsliders', 'desc_ar')) {
                    $table->text('desc_ar')->nullable()->after('desc');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('newproductsliders')) {
            Schema::table('newproductsliders', function (Blueprint $table) {
                $table->dropColumn(['name_ar', 'category_ar', 'desc_ar']);
            });
        }
    }
};
