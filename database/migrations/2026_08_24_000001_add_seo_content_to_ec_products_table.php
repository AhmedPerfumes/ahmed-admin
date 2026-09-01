<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_products', function (Blueprint $table) {
            if (!Schema::hasColumn('ec_products', 'seo_content')) {
                $table->longText('seo_content')->nullable();
            }
            if (!Schema::hasColumn('ec_products', 'seo_content_ar')) {
                $table->longText('seo_content_ar')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ec_products', function (Blueprint $table) {
            if (Schema::hasColumn('ec_products', 'seo_content')) {
                $table->dropColumn('seo_content');
            }
            if (Schema::hasColumn('ec_products', 'seo_content_ar')) {
                $table->dropColumn('seo_content_ar');
            }
        });
    }
};
