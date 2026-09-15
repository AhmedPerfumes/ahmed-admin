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
        if (Schema::hasTable('ec_orders')) {
            Schema::table('ec_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('ec_orders', 'whatsapp_review_request_sent_at')) {
                    $table->timestamp('whatsapp_review_request_sent_at')->nullable()->after('review_request_sent_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ec_orders')) {
            Schema::table('ec_orders', function (Blueprint $table) {
                if (Schema::hasColumn('ec_orders', 'whatsapp_review_request_sent_at')) {
                    $table->dropColumn('whatsapp_review_request_sent_at');
                }
            });
        }
    }
};
