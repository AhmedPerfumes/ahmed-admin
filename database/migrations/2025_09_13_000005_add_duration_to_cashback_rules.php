<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashback_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('cashback_rules', 'duration')) {
                $table->integer('duration')->nullable()->after('cashback_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cashback_rules', function (Blueprint $table) {
            if (Schema::hasColumn('cashback_rules', 'duration')) {
                $table->dropColumn('duration');
            }
        });
    }
};

