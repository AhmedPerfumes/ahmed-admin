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
        if (Schema::hasTable('foc_rules') && !Schema::hasColumn('foc_rules', 'allow_with_discount')) {
            Schema::table('foc_rules', function (Blueprint $table) {
                $table->boolean('allow_with_discount')->default(false)->after('gift_limit');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('foc_rules') && Schema::hasColumn('foc_rules', 'allow_with_discount')) {
            Schema::table('foc_rules', function (Blueprint $table) {
                $table->dropColumn('allow_with_discount');
            });
        }
    }
};
